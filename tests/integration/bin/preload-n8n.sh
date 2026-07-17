#!/usr/bin/env bash
#
# Preloads the ephemeral n8n with the fixture workflows the suite asserts against.
#
# CONTROL CASE, not convenience: fixtures are created through n8n's OWN public API,
# never through the module under test, so scenarios act on genuinely pre-existing
# workflows. Every fixture is LLM-free — Chat Trigger → Code → lastNode — because
# this suite tests the transport, not the intelligence. See features/README.md.
#
# Every chat fixture sets the trigger's "Make Chat Publicly Available" (public:
# true) explicitly, and ships its own webhookId: without public the webhook never
# registers and the fixture 404s no matter how active it is — the trap the
# Private Agent fixture exists to pin.
#
# Env: N8N_URL (default http://localhost:5678), N8N_API_KEY (required).

set -euo pipefail
# -e does not reach into command substitutions by default, so a failed create
# would otherwise cascade into confusing 404s instead of stopping here.
shopt -s inherit_errexit

N8N_URL="${N8N_URL:-http://localhost:5678}"
: "${N8N_API_KEY:?N8N_API_KEY is required — and it must be a WRITE-capable key; a read-only key 403s every create}"

api() {
  local method="$1" path="$2" body="${3:-}"
  if [ -n "$body" ]; then
    curl -fsS -X "$method" "$N8N_URL$path" \
      -H "X-N8N-API-KEY: $N8N_API_KEY" \
      -H "Content-Type: application/json" \
      -d "$body"
  else
    curl -fsS -X "$method" "$N8N_URL$path" \
      -H "X-N8N-API-KEY: $N8N_API_KEY"
  fi
}

# ── Tags ────────────────────────────────────────────────────────────────────────
# mysite = the suite's site tag; shopsite = the second domain's tag.
tag_id() {
  local name="$1"
  local id
  id="$(api GET '/api/v1/tags?limit=100' | jq -r --arg n "$name" '.data[] | select(.name==$n) | .id' | head -1)"
  if [ -z "$id" ]; then
    id="$(api POST '/api/v1/tags' "{\"name\":\"$name\"}" | jq -r '.id')"
  fi
  echo "$id"
}

MYSITE_TAG="$(tag_id mysite)"
SHOPSITE_TAG="$(tag_id shopsite)"
echo "tags: mysite=$MYSITE_TAG shopsite=$SHOPSITE_TAG"

# ── Node builders ───────────────────────────────────────────────────────────────
chat_trigger() {
  local name="$1" webhook_id="$2" public="$3"
  jq -n --arg name "$name" --arg hook "$webhook_id" --argjson public "$public" '{
    id: $hook, name: $name, webhookId: $hook,
    type: "@n8n/n8n-nodes-langchain.chatTrigger", typeVersion: 1.4,
    position: [0, 0],
    parameters: {public: $public, mode: "webhook", authentication: "none",
                 options: {responseMode: "lastNode"}}
  }'
}

code_node() {
  local name="$1" js="$2"
  jq -n --arg name "$name" --arg js "$js" '{
    id: ("code-" + $name), name: $name,
    type: "n8n-nodes-base.code", typeVersion: 2,
    position: [260, 0],
    parameters: {mode: "runOnceForAllItems", jsCode: $js}
  }'
}

# Creates a workflow, optionally tags and activates it. Prints the workflow id.
create_workflow() {
  local name="$1" nodes="$2" connections="$3" tag="$4" activate="$5"
  local body id
  body="$(jq -n --arg name "$name" --argjson nodes "$nodes" --argjson conn "$connections" \
    '{name: $name, nodes: $nodes, connections: $conn, settings: {executionOrder: "v1"}}')"
  id="$(api POST '/api/v1/workflows' "$body" | jq -r '.id // empty')"
  if [ -z "$id" ]; then
    echo "creating workflow '$name' failed" >&2
    exit 1
  fi
  if [ -n "$tag" ]; then
    api PUT "/api/v1/workflows/$id/tags" "[{\"id\":\"$tag\"}]" > /dev/null
  fi
  if [ "$activate" = "yes" ]; then
    api POST "/api/v1/workflows/$id/activate" > /dev/null
  fi
  echo "$id"
}

wire() {
  # Connection object: every listed trigger feeds the one downstream node.
  local target="$1"; shift
  local conn='{}'
  for trigger in "$@"; do
    conn="$(jq --arg t "$trigger" --arg dst "$target" \
      '. + {($t): {main: [[{node: $dst, type: "main", index: 0}]]}}' <<<"$conn")"
  done
  echo "$conn"
}

ECHO_JS='return [{json: {output: JSON.stringify($input.first().json)}}];'
CANNED_JS='return [{json: {output: "the answer is 42"}}];'

# ── The pack ────────────────────────────────────────────────────────────────────
# Echo Agent: hands back everything it received — how we assert what we sent.
nodes="$(jq -n --argjson t "$(chat_trigger 'When chat message received' 'aaaa1111-0000-4000-8000-000000000001' true)" \
              --argjson c "$(code_node Echo "$ECHO_JS")" '[$t, $c]')"
id="$(create_workflow 'Echo Agent' "$nodes" "$(wire Echo 'When chat message received')" "$MYSITE_TAG" yes)"
echo "Echo Agent: $id"

# Canned Agent: NO site tag — pins that untagged workflows are not offered.
nodes="$(jq -n --argjson t "$(chat_trigger 'When chat message received' 'aaaa1111-0000-4000-8000-000000000002' true)" \
              --argjson c "$(code_node Canned "$CANNED_JS")" '[$t, $c]')"
id="$(create_workflow 'Canned Agent' "$nodes" "$(wire Canned 'When chat message received')" '' yes)"
echo "Canned Agent: $id"

# Rename Me: exists to be renamed mid-suite without disturbing the other fixtures.
nodes="$(jq -n --argjson t "$(chat_trigger 'When chat message received' 'aaaa1111-0000-4000-8000-000000000003' true)" \
              --argjson c "$(code_node Canned "$CANNED_JS")" '[$t, $c]')"
id="$(create_workflow 'Rename Me' "$nodes" "$(wire Canned 'When chat message received')" "$MYSITE_TAG" yes)"
echo "Rename Me: $id"

# Webhook Only: tagged and active, but no chat trigger — you cannot chat with it.
webhook_node="$(jq -n '{
  id: "hook-only", name: "Webhook", type: "n8n-nodes-base.webhook", typeVersion: 2,
  position: [0, 0], webhookId: "aaaa1111-0000-4000-8000-000000000004",
  parameters: {path: "fixture-webhook-only", httpMethod: "POST"}
}')"
nodes="$(jq -n --argjson t "$webhook_node" --argjson c "$(code_node Canned "$CANNED_JS")" '[$t, $c]')"
id="$(create_workflow 'Webhook Only' "$nodes" "$(wire Canned Webhook)" "$MYSITE_TAG" yes)"
echo "Webhook Only: $id"

# Inactive Agent: a fine chat trigger that is simply never activated.
nodes="$(jq -n --argjson t "$(chat_trigger 'When chat message received' 'aaaa1111-0000-4000-8000-000000000005' true)" \
              --argjson c "$(code_node Canned "$CANNED_JS")" '[$t, $c]')"
id="$(create_workflow 'Inactive Agent' "$nodes" "$(wire Canned 'When chat message received')" "$MYSITE_TAG" no)"
echo "Inactive Agent: $id"

# Private Agent: active, but the chat trigger is NOT publicly available.
nodes="$(jq -n --argjson t "$(chat_trigger 'When chat message received' 'aaaa1111-0000-4000-8000-000000000006' false)" \
              --argjson c "$(code_node Canned "$CANNED_JS")" '[$t, $c]')"
id="$(create_workflow 'Private Agent' "$nodes" "$(wire Canned 'When chat message received')" "$MYSITE_TAG" yes)"
echo "Private Agent: $id"

# Two Doors: ONE workflow, TWO public chat triggers — each door its own model.
front="$(chat_trigger 'Front Door' 'aaaa1111-0000-4000-8000-000000000007' true)"
admin="$(jq '.position=[0,220]' <<<"$(chat_trigger 'Admin Door' 'aaaa1111-0000-4000-8000-000000000008' true)")"
nodes="$(jq -n --argjson f "$front" --argjson a "$admin" --argjson c "$(code_node Echo "$ECHO_JS")" '[$f, $a, $c]')"
id="$(create_workflow 'Two Doors' "$nodes" "$(wire Echo 'Front Door' 'Admin Door')" "$MYSITE_TAG" yes)"
echo "Two Doors: $id"

# Shop Bot: the second domain's agent, tagged for shopsite, not mysite.
nodes="$(jq -n --argjson t "$(chat_trigger 'When chat message received' 'aaaa1111-0000-4000-8000-000000000009' true)" \
              --argjson c "$(code_node Canned "$CANNED_JS")" '[$t, $c]')"
id="$(create_workflow 'Shop Bot' "$nodes" "$(wire Canned 'When chat message received')" "$SHOPSITE_TAG" yes)"
echo "Shop Bot: $id"

echo "fixture pack loaded"
