#!/usr/bin/env bash
#
# Preload the ephemeral n8n with the fixture workflows the suite asserts against —
# a CONTROL CASE that does NOT go through the module under test. Fixtures are
# created straight through n8n's own public API, so model-discovery and chat
# scenarios act on genuinely pre-existing workflows, the way an n8n admin's flows
# would already exist.
#
# Ported from the sibling nextcloud-n8n project's preload: workflow JSON lives in
# tests/workflows/*.json, and JSON is handled with python3 (always on the CI
# runner) rather than jq (not). What differs from the sibling is only the
# manifest — our fixtures are chat-trigger workflows with public/active/tag
# variations, so each row carries a tag and an activate flag.
#
# Every fixture is LLM-free — Chat Trigger → Code → responseMode: lastNode — and
# each chat trigger sets "Make Chat Publicly Available" in its JSON except the
# Private Agent, which exists to prove the public filter. See features/README.md.
#
# Inputs (env):  N8N_URL, N8N_API_KEY (minted by mint-n8n-key.sh, with tag scopes).

set -euo pipefail

: "${N8N_URL:?N8N_URL is required}"
: "${N8N_API_KEY:?N8N_API_KEY is required — run mint-n8n-key.sh first, and it must carry tag scopes or the first call 403s}"

here="$(cd "$(dirname "${BASH_SOURCE[0]}")/../workflows" && pwd)"

api() {
  # api <method> <path> [json-body]
  local method="$1" path="$2" body="${3:-}"
  if [ -n "$body" ]; then
    curl -fsS -X "$method" "$N8N_URL/api/v1$path" \
      -H "X-N8N-API-KEY: $N8N_API_KEY" -H 'Content-Type: application/json' -d "$body"
  else
    curl -fsS -X "$method" "$N8N_URL/api/v1$path" -H "X-N8N-API-KEY: $N8N_API_KEY"
  fi
}

# The id of a tag, creating it if n8n does not have it yet. Idempotent so a
# re-run against a warm n8n does not pile up duplicate tags.
tag_id() {
  local name="$1" id
  id="$(api GET '/tags?limit=100' \
    | python3 -c 'import sys,json; d=json.load(sys.stdin)["data"]; n=sys.argv[1]; print(next((t["id"] for t in d if t["name"]==n), ""))' "$name")"
  if [ -z "$id" ]; then
    id="$(api POST '/tags' "{\"name\":\"$name\"}" \
      | python3 -c 'import sys,json; print(json.load(sys.stdin)["id"])')"
  fi
  echo "$id"
}

# Create a workflow from a fixture file, stripping to the API-accepted fields.
# Prints the new workflow id.
create_workflow() {
  local file="$1" body id
  body="$(python3 -c '
import sys, json
w = json.load(open(sys.argv[1]))
print(json.dumps({"name": w["name"], "nodes": w["nodes"],
                  "connections": w["connections"], "settings": w.get("settings", {})}))
' "$file")"
  id="$(api POST '/workflows' "$body" \
    | python3 -c 'import sys,json; print(json.load(sys.stdin)["id"])')"
  if [ -z "$id" ]; then
    echo "preload: creating workflow from $file returned no id" >&2
    exit 1
  fi
  echo "$id"
}

# preload <file> <tag-or-empty> <activate yes|no>
preload() {
  local file="$1" tag="$2" activate="$3" wf tagid
  wf="$(create_workflow "$here/$file")"
  if [ -n "$tag" ]; then
    tagid="$(tag_id "$tag")"
    api PUT "/workflows/$wf/tags" "[{\"id\":\"$tagid\"}]" > /dev/null
  fi
  if [ "$activate" = "yes" ]; then
    api POST "/workflows/$wf/activate" > /dev/null
  fi
  echo "  loaded $file -> workflow $wf${tag:+, tag $tag}${activate:+, active=$activate}"
}

echo "== preloading n8n with fixture workflows =="
#        file                  tag        activate
preload  echo-agent.json       mysite     yes
preload  canned-agent.json     ''         yes
preload  rename-me.json        mysite     yes
preload  webhook-only.json     mysite     yes
preload  inactive-agent.json   mysite     no
preload  private-agent.json    mysite     yes
preload  two-doors.json        mysite     yes
preload  shop-bot.json         shopsite   yes
echo "== preload complete =="
