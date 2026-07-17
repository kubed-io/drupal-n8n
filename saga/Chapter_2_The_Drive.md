# Chapter 2 — The Drive

> The van is packed, the diner pie is finished, and the county line is ahead.
> This chapter is the drive: **every feature is a stop on the route**, and at
> every stop the ritual is the same — README first, `.feature` second, probes
> until the ground is solid, code last. We do not build a stop we haven't
> scouted, and we don't need the whole route scouted to start driving — just
> always one stop ahead of the wheels.
>
> **The smoothie** is still the blend: an n8n agent answering in a Drupal chat
> box like it lived there all along. Somewhere on this road we take **the first
> sip** — one real message, one real answer. That is a stop on the route, not
> the end of it. **Dr K decides when we've arrived, and nobody else.**
>
> This document is the trip plan **and** the field notebook. Research findings
> land here with receipts (file:line, live probe output, URL). When a finding
> kills an idea, the idea stays in the doc with a tombstone, so nobody digs it
> up and plants it again.

---

## Where we start — 2026-07-15

Carried in from Chapter 1, per its closing inventory:

- **v0.1.0 shipped.** The connection (URL + Key + Test connection, UI and drush)
  is real, unit-tested, and installed by the cluster's install Job.
- ☐ `admin-connection.feature` still `@todo` — needs the ephemeral n8n wired
  into `integration.yml`. **Day-one debt for this chapter.**
- ☐ **Phase 4, per-domain config** — fully mapped in Ch1 §9.1, not written.
- `chat()` returns a placeholder. `getConfiguredModels()` returns a placeholder.
  The provider is a proven skeleton with no organs.

**Operation mode this session:** research, planning, and vibing. No solution
code was written. Two things *were* touched in the world, both probes:
a temporary echo workflow created on the live n8n to verify the chat-webhook
contract (deleted after — evidence below), and read-only source archaeology in
the live Drupal pod.

---

## 1. Ground truth — verified 2026-07-15, do not re-discover

Chapter 1's §2.2 was read against `drupal/ai` ~1.1 docs. The live pod now runs
**`drupal/ai` 1.4.4 + `ai_agents` 1.3.2 on Drupal 11.4.4**, and the ground
moved under one load-bearing assumption. Everything below was read from the
pod's source or proven against the live n8n. Treat as fact.

### 1.0 Dr K's call: the new API only

> **Decided 2026-07-15, and it prunes this chapter hard:** we target the **new
> API only** — assistants are agents. **No backwards compatibility, none.** We
> do not support, test, document, or think about the legacy assistant.
>
> What that buys, immediately:
> - **Fork F is dead.** The `promptJsonDecoder` — Ch1's "sharpest edge in the
>   design" — lives only in the legacy `process()` path. The agent path never
>   calls it. **An n8n agent can answer with JSON and nothing will mangle it.**
>   A whole risk, a whole probe, and a whole mitigation: deleted.
> - **Fork G collapses to one column.** No two-generation matrix, no
>   legacy-minting drush flavor, no "which kind of assistant is this" branch.
> - **Ch1's bundled-`system_prompt.txt` override is irrelevant** — that's
>   `process()`'s legacy branch too.
>
> What it costs, and it is not small: **streaming (Stop 5) is dead** until
> upstream changes. Proven, three independent gates, §1.3a.
>
> What it changes and would have bitten us silently: **the session bridge tag
> is not `ai_assistant_thread_`.** §1.1b.

### 1.1 The earthquake: assistants are agents now

The drupal.org project page says it outright: *“The AI Assistants API is now a
wrapper around AI Agents.”* In source:

- **The assistant form auto-creates a companion `ai_agent` config entity for
  every NEW assistant** (`ai_assistant_api/src/Form/AiAssistantForm.php`,
  `submitForm()` — new entity: `storage('ai_agent')->create([...])` with the
  form's *Instructions* as the agent's `system_prompt`, `orchestration_agent:
  TRUE`, then `$entity->set('ai_agent', $agent->id())`). The assistant's own
  `system_prompt`/`pre_action_prompt` are set to `""`.
- If `ai_agents` ≥1.1 is missing, the form refuses to create assistants at all:
  *“All assistants going forward will be agents. To be able to add a new
  assistant you need to update to the AI Agents module 1.1.0+.”*
  (`AiAssistantForm.php:117-122`)
- At runtime, `AiAssistantApiRunner::process()` **short-circuits before the
  thin path**: `if ($this->assistant->get('ai_agent') && moduleExists('ai_agents'))
  return ai_assistant_api.agent_runner->runAsAgent(...)`
  (`AiAssistantApiRunner.php:325-337`).
- Old actions-based assistants get a deprecation warning: *“will be removed in
  2.0.0.”*

**Consequence — the only path we build for:**

```
ai_chatbot block → AiAssistantApiRunner::process()
                     └── ai_agent set?  → AgentRunner::runAsAgent()
                                            └── AiAgentEntityWrapper::determineSolvability()
                                                  └── $provider->chat($input, $model, $tags)   ← us
```

So **every chat lands on us through an agent**, and the agent is the thing that
builds the `ChatInput`, the system prompt, and the tags. Chapter 1 designed
against `process()`'s *other* branch, which we now never touch.

#### 1.1a The zero-tools passthrough — PROVEN, not argued

The worry was that the agent layer would fight our n8n agent. It doesn't, and
this is no longer a reading of the source — a probe drove the **real
`AiAgentEntityWrapper`** on the live pod with a spy provider slotted in via
`setAiProvider()`, exactly the way `AgentRunner::runAsAgent()` wires it:

```
WRAPPER CLASS: Drupal\ai_agents\PluginBase\AiAgentEntityWrapper
SOLVABILITY: 1
FINISHED:    true
CHAT CALLS:  1
--- call 0
  model:    hello-world
  streamed: false
  TAGS:     ai_agents | ai_agents_n8n_probe_agent | ai_agents_prompt_n8n_probe_agent
            | ai_agents_runner_assistant_thread_probe_xyz
            | ai_agents_thread_assistant_thread_probe_xyz
  sysprompt len=80 head=You are the probe agent.  This is the first time that this agent has been run.
  messages: user:hello van
SOLVE RETURNED: 'spy-reply'
TOTAL CHAT CALLS AFTER SOLVE: 1
```

Read that carefully, because it settles four things at once:

1. **One `chat()` call per turn. Total.** `solve()` did **not** add a second.
   With a zero-tools agent the loop is: call us → no tool calls in the response
   → `finished = TRUE` → our text *is* the answer
   (`AiAgentEntityWrapper.php:683-720`). The passthrough is real.
2. **A plain `ChatMessage` is safe.** `ChatMessage::getTools()` exists and
   returns `?array` (`ai/src/OperationType/Chat/ChatMessage.php:194`), so
   `$response->getTools()` → NULL → `!empty(NULL)` is FALSE → finished. This is
   exactly where OpenRouter's *streamed iterator* fataled (§1.4) — our
   non-streamed `ChatOutput` walks past it.
3. **The agent's system prompt is small and ours to ignore** — 80 chars from
   the agent entity, not Drupal's bundled essay. D1 unchanged: we drop it.
4. **The tags are `ai_agents_*`.** See next.

#### 1.1b The session bridge tag is WRONG in Chapter 1 — corrected

**This is the finding that would have cost us a day of "why does memory not
thread."** Ch1 §2.2c built the bridge on the `ai_assistant_thread_` tag. On the
agent path **that tag is never created.** The wrapper builds its own list
(`AiAgentEntityWrapper.php:561-575`):

```php
$tags = ['ai_agents', 'ai_agents_' . $agent_id, 'ai_agents_prompt_' . $agent_id];
if ($this->runnerId)   { $tags[] = 'ai_agents_runner_' . $this->runnerId; }
if ($this->threadId)   { $tags[] = 'ai_agents_thread_' . $this->threadId; }
```

The bridge still exists — it just moved. `AgentRunner::runAsAgent()` passes the
assistant's `getThreadsKey()` in as `$job_id` and calls **both**
`setRunnerId($job_id)` and `setProgressThreadId($job_id)` (which assigns
`threadId` *and* flips on progress tracking — `AiAgentEntityWrapper.php:736`).
So the assistant's thread key arrives on **two** tags, proven above:

| Tag | Carries |
|---|---|
| `ai_agents_thread_<key>` | the assistant thread key — **read this one** |
| `ai_agents_runner_<key>` | same value, but semantically the run, and a sub-agent would overwrite the meaning |
| `ai_agents_<agent_id>` | which agent — useful for logging, not identity |

> **Decision:** the session bridge reads **`ai_agents_thread_`**, strips the
> prefix, hands the rest to n8n as `sessionId`. Same stability properties Ch1
> wanted (stable per user, per assistant, per browser session), different
> prefix. `ai_assistant_thread_` is now a **tombstone** — if you see it in our
> code, it is a bug from a stale memory.

#### 1.1c NEW TRAP — a programmatically created assistant can be unloadable

Found by breaking it. Creating an `ai_assistant` **without** `llm_configuration`
saves a config object whose typed property is `null`, and every later `load()`
throws:

```
TypeError: Cannot assign null to property
Drupal\ai_assistant_api\Entity\AiAssistant::$llm_configuration of type array
```

The entity is then unloadable *and undeletable through the entity API* — it has
to be removed at the config layer
(`configFactory()->getEditable(...)->delete()`). `specific_error_messages` has
the same shape. **Stop 6 mints assistants programmatically, so this is a
direct hit:** always pass `llm_configuration: []` and
`specific_error_messages: []`. Probably worth an upstream issue; definitely
worth a test. *(Yes, this happened on the live site during this session. Both
probe entities were cleaned at the config layer and the site verified healthy —
40 projects, 0 needing update.)*

### 1.2 The n8n chat contract — proven live, with receipts

A temporary probe workflow (`Chat Trigger → Code (echo)`, then
`Chat Trigger → Agent(Gemini)` for streaming) was created on the live n8n,
exercised from inside the Drupal pod, and deleted. Findings:

1. **Fork B1 VERIFIED ✅.** The public REST API exposes the chat trigger's
   webhook id. `GET /api/v1/workflows/<id>` → the chatTrigger node carries
   `webhookId` alongside `parameters`:
   ```
   CHAT TRIGGER NODE KEYS: parameters,type,typeVersion,position,id,name,webhookId
   webhookId: 5d16bf0a-69a9-4edf-9f00-887a074fc2e4
   ```
2. **The chat URL is `<base>/webhook/<webhookId>/chat`.** The bare
   `/webhook/<webhookId>` 404s. Both shapes were hit; only `/chat` answers.
3. **The contract is exactly what Ch1 predicted, plus metadata:**
   ```
   POST {action:"sendMessage", sessionId, chatInput, metadata:{...}}
     →  200 {"output": "..."}          (responseMode: lastNode)
   POST {action:"loadPreviousSession", sessionId}
     →  200 {"data": [...]}
   ```
   **`metadata` passes through verbatim** — the echo saw
   `{"drupal_route":"/support","assistant":"test"}` untouched as
   `$json.metadata`. Kelly's metadata use case is confirmed cheap.
4. **NEW TRAP — `active` is not enough.** The first workflow probed was
   `active: true` but its chat trigger had `public` unset (default `false`):
   the webhook is **not registered** and 404s. Model discovery must filter on
   **chatTrigger present + workflow active + `parameters.public == true`**
   (and surface *why* a workflow was excluded — `drush n8n:models --all`).
5. **Streaming wire format captured** (chat trigger `options.responseMode:
   "streaming"`): **newline-delimited JSON, not SSE**, content-type stays
   `application/json`:
   ```
   {"type":"begin","metadata":{"nodeId":"…","nodeName":"Agent","itemIndex":0,"runIndex":0,"timestamp":…}}
   {"type":"item","content":"pong","metadata":{…}}
   {"type":"end","metadata":{…}}
   ```
   Newer n8n also emits `tool-call-start` / `tool-call-end` chunks
   ([n8n PR #20499](https://github.com/n8n-io/n8n/pull/20499)) — raw material
   for “agent is working…” blurbs, later.
6. **NEW TRAP — streaming + a non-streaming last node = HTTP 200 with a
   completely EMPTY body.** A Code node can't stream; n8n returns nothing and
   calls it success. Our provider must treat an empty streamed body as an
   error path (“the workflow's response mode is streaming but nothing in it
   streams”), not as an empty answer. Also the reason **CI can't fixture
   streaming with LLM-free workflows** — only the AI Agent node streams. The
   streaming stop needs a different test strategy (unit-level NDJSON parsing
   against a canned stream; live streaming proven manually or against a real
   agent out-of-pipeline).
7. **`loadPreviousSession` is real but UNPROVEN.** With a buffer-window memory
   wired to trigger + agent, it still returned `{"data":[]}`. Needs a proper
   probe against the real Postgres Chat Memory before any “history
   rehydration” feature is specced. Parked with a flag (see Later stops).
8. **Chat Hub is a thing now** (n8n ≥2.1): chat triggers carry
   `availableInChat`, `agentName`, `agentDescription` —
   [docs](https://docs.n8n.io/build/ways-of-building-workflows/chat-hub) /
   [announcement](https://community.n8n.io/t/announcing-chat-hub-beta/236446).
   n8n grew its own “list of assistants,” and its metadata lives **on the
   trigger node we already fetch**. Free enrichment: when present, use
   `agentName` as the model label and `agentDescription` in the models
   listing. Note Chat Hub wants streaming on
   ([community](https://community.n8n.io/t/publishing-chat-agents-to-n8n-chat-not-showing-up/291241)).
9. **Webhook auth is its own thing.** `X-N8N-API-KEY` authenticates the REST
   API only. The chat webhook has separate auth: `none` / `basicAuth` /
   `n8nUserAuth` on the trigger. A public chat webhook with `none` is an open
   endpoint to anyone who learns the UUID. **Security story for the README and
   a connection-level option:** support Basic auth for chat calls, credential
   via the Key module. (n8nUserAuth is for n8n's own UI sessions — not us.)
10. **Every message is one n8n execution** — worth one line in the README for
    capacity planning; n8n cloud users have quotas (we're self-hosted, still
    worth saying).

### 1.3 The Drupal chat surface — what `ai_chatbot` actually gives us

Read from the pod, `ai/modules/ai_chatbot` 1.4.4. This is the UI we inherit
for free, and it is **richer than Ch1 knew**:

- **Two blocks ship: the legacy `ChatFormBlock` and `DeepChatFormBlock`** — the
  DeepChat one bundles the [deep-chat](https://deepchat.dev) web component
  (`deepchat/deepchat.bundle.js`) and is the one that matters. Placements
  include normal regions **and a `toolbar` mode** (admin toolbar chat).
- **Streaming machinery exists but is unreachable for us — see §1.3a.** The
  plumbing is real: block setting `stream` → POST `stream: 1` → `DeepChatApi`
  returns an `AiStreamedResponse` SSE (`data: {"html": …, "overwrite": true}`
  per chunk). It just never reaches an agent-backed assistant.
- **Markdown is rendered** (CommonMark when installed), links get base-path
  fixed, and the XSS allowlist **includes `img`** (`DeepChatApi::$allowedTags`).
  → **An n8n agent that answers with `![…](https://…/image.png)` shows an
  image in the chat box today.** No work. (No `script`/`iframe`/`video` — a
  “custom UI in the bubble” is bounded by this allowlist.)
- **A metadata channel already exists in the right direction**: the block
  posts `contexts: {current_route: <path>}` with every message
  (`DeepChatFormBlock.php:698-701`), and `DeepChatApi` hands it to
  `AiAssistantApiRunner::setContext()`. **But** the runner only bakes context
  into the *system prompt* (`AssistantMessageBuilder::buildMessage`), which we
  throw away (Fork D1). **The provider never receives contexts — only `$tags`.**
  Closing that gap is the design question of Stop 4 (options scouted there).
- **Extension hooks we get for free**: `hook_deepchat_settings(&$settings)` —
  alter the whole deep-chat component config from any module (the file-upload
  toggle, avatars, extra `additionalBodyProps` all live here);
  `hook_deepchat_prepend_message()` — append our own HTML to any reply;
  `deepchat_styles/*.yml` theming (module- or theme-provided skins).
- **Files: inbound is NOT wired.** The deep-chat component can render a file
  input, but `DeepChatApi` extracts only `role`/`text` from messages, and the
  assistant pipeline's `UserMessage` is a bare string. `ChatMessage` (provider
  level) supports files/images — the gap is the assistant runner + controller,
  i.e. **upstream**, not us. Verdict in §3.
- **Structured results & verbose mode exist** (`show_structured_results`,
  `verbose_mode` block settings): verbose mode surfaces Drupal-side tool calls
  step by step (“Calling @tools…”, `should_continue` loop). For us mostly
  inert (tools are n8n's), but the streaming `tool-call-*` chunks from §1.2.5
  could feed an equivalent someday.
- **Reset session** (`ResetSession` controller) mints a fresh thread id →
  under our bridge that's a fresh n8n `sessionId`. “New conversation” works
  without us clearing anything in n8n; old rows just orphan in the memory
  table. Document as expected behavior.
- **Ch1 §9's anonymous-collision risk is RESOLVED in source.** With
  `allow_history: session_one_thread`, `setAssistant()` hits the
  `shouldStoreSession()` branch first and mints a **random per-browser-session
  hash** (`generateUniqueKey()` → tempstore `current_thread_id_<assistant>`,
  `Crypt::hashBase64(uniqid(...))` — `AiAssistantApiRunner.php:170-180,740`).
  The deterministic `assistant_thread_<assistant>_<uid>` line is effectively
  dead code when history is on. Anonymous visitors get distinct sessions;
  PrivateTempStore for anonymous rides the PHP session that `DeepChatApi::
  setSession()` starts. `session-memory.feature`'s “two visitors do not share
  a conversation” should now pass **by construction** — keep the scenario as
  the proof.
- **Who consumes assistants?** On this install, grep says: **only
  `ai_chatbot`** (plus the entity's own admin UI). The block layout is *the*
  implementation surface. The assistant is also reachable headless via
  `/api/deepchat` — which is exactly what a future decoupled front-end would
  use, and none of our business to build.

### 1.3a Streaming is DEAD on the new API — three gates, all closed

**The price of §1.0, stated loudly so nobody plans around a thing that cannot
happen.** Streaming cannot reach an n8n-backed assistant today. Not "is hard" —
*cannot*, and three independent mechanisms each stop it on their own:

| # | Gate | Evidence |
|---|---|---|
| 1 | The block never asks. `DeepChatFormBlock::isStreamingSupported()` returns **FALSE whenever `ai_agent` is set**, before consulting the block's own `stream` setting | `ai_chatbot/src/Plugin/Block/DeepChatFormBlock.php:900-910` |
| 2 | The runner never applies it. `$this->streaming` is read at **exactly one place** — `AiAssistantApiRunner.php:498`, inside `assistantMessage()`, which is the **legacy** branch. `process()` short-circuits into `runAsAgent()` at line 325, long before | `grep -n streaming AiAssistantApiRunner.php` → only 498 |
| 3 | The agent never propagates it. `AgentRunner::runAsAgent()` contains **zero** streaming code (one vestigial docblock), and the wrapper never calls `setStreamedOutput(TRUE)` | proven: the §1.1a probe requested `streamedOutput(TRUE)` and the provider still saw **`streamed: false`** |

So even if we wrote a perfect NDJSON→`StreamedChatMessageIterator` adapter, and
even if the user ticked **Stream** on the block, `$input->isStreamedOutput()`
would be `FALSE` and we'd return a normal `ChatOutput`. And if we returned a
streamed iterator *anyway*, we'd reproduce OpenRouter's fatal (§1.4) —
`AiAgentEntityWrapper->determineSolvability()` calls `getText()` on the
response, which a `StreamedChatMessageIterator` does not have.

> **Verdict: Stop 5 is struck from the route.** Streaming is now an **upstream
> question**, not a feature of ours. The honest framing for the README: *answers
> arrive complete, because Drupal's assistant pipeline does not stream
> agent-backed assistants.* That is a true statement about Drupal, not an
> apology about us.
>
> **The upstream shape, if we ever want it:** `AgentRunner::runAsAgent()` would
> have to accept the runner's streaming flag and the wrapper would have to
> handle a streamed response in its no-tool-calls terminal case (where the loop
> is already over and nothing needs `getText()`). That is a small, well-scoped
> MR against `ai` — and n8n's NDJSON is *already* the right shape to feed it.
> **A genuinely good first contribution to the AI initiative, and a Chapter-N
> decision, not a Chapter-2 one.**

### 1.4 Precedent worth stealing (and the one that walked into the same wall)

- **[openrouter provider issue #3576265](https://www.drupal.org/project/ai_provider_openrouter/issues/3576265)**
  — another `chat` provider hit this exact terrain in Feb 2026: chatbot showed
  no responses on AI 1.2/1.3, streaming never enabled, then
  `Call to undefined method …StreamedChatMessageIterator::getText() in
  AiAgentEntityWrapper->determineSolvability()`. Their landed answer matches
  our §1.1 analysis word for word: *streaming only for legacy assistants;
  agent-backed assistants get a single non-streamed response by design; clear
  `ai_agent` via drush to convert (“there is no UI way to do so”).* We are not
  guessing — someone already paid this toll and published the receipt.
- **[ai_agents_exporter](https://www.drupal.org/project/ai_agents_exporter)** +
  **[issue #3577241](https://www.drupal.org/node/3577241)** — upstream is
  building the *mirror image* of us: **Drupal agents pushed out** to external
  platforms (Claude Code sub-agent files, VAPI voice assistants), with an
  `AiAgentExporter` plugin type landing in `ai_agents` core (MR !249). Their
  lifecycle vocabulary is exactly what our sync stop needs: external id in
  key-value (never config), `auto_sync` on save, cleanup hook on delete, an
  Operations-dropdown entry per agent. Ours is the inbound direction (n8n is
  the source of truth); name-check them in the README so nobody thinks we
  overlap.
- **The Drupal MCP direction is proven from this very session**: this planning
  conversation ran `general_info` over the site's `/mcp` endpoint (returned
  `Kubed, 11.4.4`) and the site's agents appear as `aia_*`/`aif_*` MCP tools.
  n8n→Drupal continues to need **zero code from us**.

### 1.5 Fork H — the tool path *(Dr K, mid-session: “register workflows like webhooks as tools”)*

> *“maybe we can register workflows like webhooks as tools into drupal. then we
> could make a native drupal assistant that can use a n8n workflow like a tool”*

**This is not an alternative to the provider. It is the other half of the
module, and it fits the new API's grain better than the provider does.**
Researched in source this session; the machinery is all there.

**Two different sentences, and the module should be able to say both:**

| | **Provider path** (Stops 1–4) | **Tool path** (Fork H) |
|---|---|---|
| The sentence | “**Chat with** my n8n agent.” | “My Drupal assistant **can use** my n8n workflows.” |
| Where the brain is | **n8n.** Drupal's LLM is bypassed — the agent is a zero-tools passthrough (§1.1a) | **Drupal.** The n8n workflow is a *capability*, not a mind |
| Which workflows it serves | Chat Trigger only — and only `public: true` ones | **Webhook, Execute Workflow, Form triggers** — i.e. the ~90% that aren't chat agents |
| Cost | no Drupal LLM tokens | a real Drupal LLM must be configured, and it pays for the reasoning |
| Fits the new API? | tolerated (we hollow the agent out) | **natively — the new API *is* agents + tools** |

> ### ⚠️ 1.5a — CORRECTION, 2026-07-16. Most of what follows was wrong.
>
> Dr K asked *“workflow trigger: can this even be called outside of n8n?”* and
> *“I'm pretty sure we need the webhook for tools.”* **He was right on both, and
> the research below was written before I checked.** Three corrections, all
> verified:
>
> **(1) There is NO execute endpoint on the n8n public REST API.** Not
> "undocumented" — absent. Pulled from the live instance's own OpenAPI spec:
> ```
> /workflows  /workflows/{id}  /workflows/{id}/{versionId}  /workflows/{id}/activate
> /workflows/{id}/deactivate  /workflows/{id}/archive  /workflows/{id}/unarchive
> /workflows/{id}/transfer  /workflows/{id}/tags  /executions  /executions/{id}
> /executions/{id}/retry  /executions/{id}/stop  /credentials  /tags  /users
> /variables  /data-tables  /audit  /source-control/pull
> ```
> `POST /workflows/{id}/execute` → **405**. You can *retry* an execution but
> never *start* one. (Others have walked into this:
> [PraisonAI-Tools #22](https://github.com/MervinPraison/PraisonAI-Tools/issues/22)
> — a shipped tool calling an endpoint that never existed.)
>
> **(2) The Execute Workflow Trigger is NOT externally callable — by any route.**
> Not REST (no endpoint), and **not MCP either**: the instance-level MCP
> `execute_workflow` tool's own reference says *“Production mode supports
> workflows with **Webhook, Chat Trigger, Form Trigger, and Schedule Trigger**
> nodes”* — the sub-workflow trigger is absent from that list by design. It
> exists to be called by *another n8n workflow*, full stop. **So "lead with the
> Execute Workflow Trigger" — my §1.5 recommendation below — is dead.** Its
> declared inputs are a beautiful tool signature that nothing outside n8n can
> reach.
>
> **(3) The tool path may already be built by someone else — but NOT for free,
> and NOT with anything we have.** *(Corrected again, same day, after Dr K
> pointed out I was conflating two modules — verified on the pod:
> `drupal/mcp` **1.2.3 is installed and enabled** (the **server**);
> `drupal/mcp_client` is **NOT INSTALLED**, not present at all.)* Two things
> exist that I didn't know about:
> - **n8n instance-level MCP** (`/mcp-server/http`) — n8n *is* an MCP server.
>   Per-workflow opt-in via an **“Available in MCP”** toggle (Dr K's tag
>   instinct, but native), OAuth2 or Bearer auth,
>   [docs](https://docs.n8n.io/connect/connect-to-n8n-mcp-server).
> - **[`drupal/mcp_client`](https://www.drupal.org/project/mcp_client)** — by
>   **Marcus Johansson** (who maintains `drupal/ai` itself) + James Abrahams.
>   Connects Drupal to any MCP server over Streamable HTTP, and — the line that
>   matters — *“**Tools from MCP servers are automatically discovered and exposed
>   as AI function call plugins**.”* **That is the deriver I was about to
>   build, already written, by the AI module's own maintainer.**
>
> **So: do not build `N8nWorkflowDeriver`.** See §1.5b for what to do instead.
> The deriver research below is kept because it's the fallback if `mcp_client`
> disappoints, and because the `#[FunctionCall]` anatomy is worth knowing.

**The mechanism is stock and proven by two shipped examples.** Tools are
`#[FunctionCall(...)]` plugins (`ai/src/Attribute/FunctionCall.php`):

```php
#[FunctionCall(
  id: 'ai_search:rag_search',
  function_name: 'ai_search_rag_search',
  name: 'RAG/Vector Search',
  description: 'This method will search one index …',   // ← the tool description IS the prompt
  group: 'information_tools',
  context_definitions: [ 'search_string' => new ContextDefinition(…), … ],
  deriver: NULL,                                        // ← and there is a deriver slot
)]
```

The attribute carries a **`deriver`** — and `ai` already ships two derivers that
turn *N of a thing* into *N tools*: `ActionPluginDeriver` (every Drupal action
becomes a tool) and `AutomatorPluginDeriver`. **An `N8nWorkflowDeriver` is the
same shape**: one tool per tagged workflow, `function_name` from the workflow
name, `description` from the workflow's own description, `context_definitions`
from its declared inputs. That is exactly how discovery already works for us —
same client, same tag convention, same cache.

**The elegant part — the trigger type decides the Drupal surface.** This is the
insight that makes the whole module coherent instead of two bolted-together
ideas. **Corrected against the live API and the MCP tools reference:**

| n8n trigger | Reachable from outside n8n? | Drupal surface | Built by |
|---|---|---|---|
| **Chat Trigger** | ✅ `POST /webhook/<webhookId>/chat` — **synchronous**, `{output}` in the response | a **model** → an assistant+agent point at it | **us** |
| **Webhook Trigger** | ✅ `POST /webhook/<path>` — **synchronous** | a **tool** — but input is freeform, nothing declares a schema | us, *or* mcp_client |
| **Form Trigger** | ✅ webhook; MCP passes `formData` | tool / `n8n_webform` | later |
| **Schedule Trigger** | ✅ MCP `execute_workflow` only (it has no URL) | nothing — it's a cron | — |
| **Execute Workflow Trigger** | ❌ **NO. Not REST, not MCP** (§1.5a) | **none — tombstone** | nobody |
| **MCP Server Trigger** | ✅ it **is** an MCP server, with named+typed+**synchronous** tools | **tools, auto-discovered** | **`drupal/mcp_client`** |

**The input-schema problem, honestly resolved.** A webhook declares nothing, so
a tool derived from one is `n8n_my_workflow(freeform_json)` — barely better than
`execute_workflow`. The schema has to come from somewhere, and n8n already has
the somewhere: **the MCP Server Trigger + Custom n8n Workflow Tool nodes**, which
is n8n's own native way to say *“this workflow is a tool, here are its
params.”* Don't invent a second convention next to it.

**And the killer detail about the MCP route:** `execute_workflow` **“returns the
execution ID immediately without waiting for completion”** — it is **async**. A
Drupal agent using it would have to `search_workflows` → `execute_workflow` →
poll `get_execution` — three-plus tool calls and a polling loop for one logical
action, with a 5-minute MCP cap. **That is bad tool ergonomics**, and it's why
instance-level MCP is *not* the answer for "call my Jira workflow" even though
it looks like it is. An **MCP Server Trigger** workflow, by contrast, answers
the tool call directly with its result.

**Two findings that make this better than it first looks:**

- **`return_directly` exists.** `AiAgentEntityWrapper::toolShouldReturnDirectly()`
  (line 1132) reads `tool_settings[<plugin>]['return_directly']` off the agent
  entity; when set, the tool's output becomes the answer with **no second LLM
  pass** (line ~525: `$this->question = $output; return JOB_SOLVABLE;`). So a
  Drupal agent can *route* to n8n agents and hand back their answers verbatim —
  no paraphrase tax, no rewriting of your agent's carefully-formatted reply.
- **Tools are exposed over MCP for free.** Drupal's `mcp` module surfaces
  function calls as `aif_*` tools — visible in this very session
  (`aif_ai_search_rag_search`, `aif_chef`, …). **An n8n workflow registered as a
  Drupal tool automatically becomes an MCP tool**, callable by anything that
  speaks Drupal's MCP — including, deliciously, another n8n agent.

**The honest costs:**
- Requires a real Drupal LLM. The provider path's selling point is that it
  needs none.
- If the n8n workflow is *itself* an AI agent, you now have two LLMs. That's
  normal (it's the sub-agent pattern), but it's latency and money, and
  `return_directly` is the mitigation.
- Tool name + description **are** the prompt. Garbage workflow names produce a
  bot that never calls the tool. This is a docs/UX duty, not a code one.
- **It does not rescue streaming** (§1.3a). Nothing does, on the new API.

#### 1.5c Three directions, and only two are on the same axis

**The clarification that ends the confusion** (Dr K, 2026-07-16 — he has the MCP
*server*, not the *client*, and that distinction is the whole thing):

| | Direction | Status on this site |
|---|---|---|
| **1. MCP server** — `drupal/mcp` | an **n8n agent calls Drupal's** tools | ✅ **installed + enabled** (1.2.3, with `mcp_studio`). Ch1 §4: free, already works |
| **2. MCP client** — `drupal/mcp_client` | a **Drupal agent calls n8n's** tools | ❌ **not installed.** A new, experimental dependency. This is Fork H |
| **3. The provider** — `ai_provider_n8n` | **neither — not a tool relationship** | what we're building |

**#1 and #2 are the same axis** — *whose tools does whose agent call*. **#3 is a
different axis entirely** — *who answers*. The n8n workflow occupies the **model
slot**, where `gpt-4o` goes. No MCP is involved in it at any point.

**And #1 + #3 compose — half of it is already running:**

```
Drupal chat box → assistant → agent → provider n8n → your n8n agent   ← #3 (us)
                                                          │
Drupal MCP /mcp/post ◀────── MCP Client Tool ─────────────┘           ← #1 (already live)
   (content, JSON:API, RAG, Drupal agents)
```

That is the README's round trip, and it needs **nothing** from `mcp_client`.

#### 1.5b The verdict — **we probably shouldn't build the tool path at all**

Chapter 1's governing constraint says it for us:

> *“Least code possible. If a stock Drupal mechanism does the job, we use it and
> we do not improve on it.”*

**A stock mechanism does the job.** `drupal/mcp_client` + an n8n **MCP Server
Trigger** workflow gives a Drupal agent named, typed, synchronous n8n tools,
auto-discovered as AI function-call plugins, with **zero lines from us**. It is
the **exact repeat of Ch1 §4**, where the n8n→Drupal direction turned out to be
free because Drupal's MCP server already existed. Now the Drupal→n8n *tool*
direction is free too, because both ends grew MCP while we were packing the van.

The three candidate routes, ranked:

| Route | Verdict |
|---|---|
| **`mcp_client` + n8n MCP Server Trigger** | ✅ **Recommended — but "zero code" ≠ "free".** Named, typed, synchronous tools, no code *from us*. Real costs: a **new experimental dependency the site does not have** (§1.5c), plus the user builds one MCP-server workflow in n8n. n8n owns what its tools are — correct ownership, and the ownership split (§1) is the whole thesis. |
| **`mcp_client` + n8n instance-level MCP** | ◑ **Different feature, worth documenting.** Gives Drupal an *n8n-admin* agent: search/execute/build workflows. Async + polling makes it wrong for "call my workflow as a tool", right for "let my Drupal agent manage n8n". |
| **Our own `N8nWorkflowDeriver` + webhooks** | ❌ **Don't.** Only wins if `mcp_client` is unusable — and it buys `n8n_thing(freeform_json)`, because webhooks declare no schema. We'd write a subsystem to be *worse* than the free option. |

**The one honest caveat:** `mcp_client` is **experimental** — `1.0.x-dev`, "first
testable release", and its own page admits *“extensive parts of this module
[were] generated via AI coding agents”*. Best-possible pedigree (the `drupal/ai`
maintainer), early-stage reality. **So Fork H's real work is a probe, not a
build:** stand up an MCP Server Trigger workflow, point `mcp_client` at it, and
see whether the tools land as function calls. **That's an afternoon, and the
outcome is either "document it in the README" or "now we know why we build."**

> **Recommendation: provider first, and Fork H is probably a README section, not
> a Stop.** The provider path is the stated vision (“select any assistant, it
> shouldn't matter if it's n8n”), it is two stops from done, and the sip is
> owed. It is also the **one thing nothing else does**: no module, upstream or
> otherwise, makes an n8n chat agent answer as a Drupal assistant. Tools from
> n8n are a solved problem we should *point at*, not re-solve.
>
> **This sharpens the module's identity rather than shrinking it:** we own the
> **Chat Trigger → model** mapping. Everything else n8n-shaped that Drupal might
> want — tools, RAG callbacks, admin — is already MCP's job, in both directions.
>
> Ch1's `n8n_webform` gets the same scrutiny at its stop: if a Drupal thing
> triggering an n8n workflow is a **tool**, and tools are `mcp_client`'s job,
> then Webform's stock Remote Post handler plus a URL may be the entire honest
> answer. **Dr K's call.**

---

## 2. Dr K's brainstorm, answered point by point

Every block from the Chapter 2 kickoff prompt, with a verdict:
**CORE** (this chapter's route), **STOP** (a named stop below), **LATER**
(parked, real), **DEAD END** (tombstone with cause of death).

### “How exactly do we spoof assistants as n8n agents? Can the list include ours?”
**CORE — and it's two different lists; keep them straight.**
- The **model dropdown inside one assistant** lists our workflows the moment
  `getConfiguredModels()` is real (Stop 1). That's the spoof, and it's the
  same gesture as picking `gpt-4o`. Nothing to sync; n8n stays source of truth.
- The **assistants list** (`/admin/config/ai/ai-assistant`) lists
  `ai_assistant` config entities. An n8n workflow only appears there when an
  assistant *entity* points at it. We can mint those on demand (Stop 6) — one
  per tagged workflow if Dr K wants — but each is a real, boring Drupal config
  entity we created, not a mirage. **Recommendation: dropdown-first (zero
  sync), entity-minting as the Stop 6 convenience.**

### “The assistant edit page overlaps the n8n agent's fields — sync the info? set workflow values from Drupal?”
**Half CORE, half LATER — and the ownership split from Ch1 §1 survives contact.**
- The overlap is real (prompt, model, memory settings) and Ch1's answer stands:
  those Drupal fields are **inert by design**; n8n owns the brain. Trying to
  write Drupal's prompt fields into the workflow inverts the source of truth
  and reopens every “two prompts fighting” problem. **Sync of brain-fields:
  DEAD END, deliberately.** (Upstream's exporter project is exactly this — for
  agents whose brain *is* Drupal's. Ours isn't.)
- **But we control a legitimate per-model settings surface**: the “Provider
  Configuration” block on the assistant form is generated from **our**
  `definitions/api_defaults.yml` + `getModelSettings()`
  (`AiProviderClientBase::getAvailableConfiguration()`, line 388). That is the
  sanctioned home for n8n-specific, per-assistant knobs: extra metadata
  key/values, “forward Drupal context,” streaming preference. **Stop 4.**
- Reading n8n's `agentName`/`agentDescription` (Chat Hub fields) to label the
  model: **Stop 1**, free.

### “Alternatively: sync assistants into the list, edit button links to n8n?”
**STOP 6 — yes, and this is the shape.** Link, don't sync. Mint the assistant
entity (legacy-mode, §1.1 G1), keep only Drupal-owned fields real (label,
roles, error messages, history mode), and add an **“Open in n8n”** operation
link (`hook_entity_operation`) pointing at `<n8n>/workflow/<id>` — the exact
`nextcloud-n8n` gesture. The heavy edit form the user lands on is n8n's own —
which is the whole point.

### “Don't show ours in the main list, only in the select?”
**Resolved by the above.** The model *select* shows workflows automatically;
the assistants *list* only shows what we deliberately minted. Both behaviors
fall out of the architecture with zero suppression code. Tombstone for the
suppression idea: nothing to suppress.

### “What if we implemented it as a full provider?”
**That is what we are.** Ch1 decided it; nothing found this session argues
otherwise. The new nuance is Fork G (§1.1): the provider is necessary but the
*assistant-creation UX* is now also ours to own, because upstream's UI only
makes agent-backed assistants.

### “Could Drupal and n8n share the session id / chat history table / vector DB?”
- **Session id: CORE, and now correctly designed** — the **`ai_agents_thread_`**
  tag → `sessionId` bridge (Stop 3, §1.1b). That *is* the sharing.
- **Chat history table** (`apps/n8n/components/db/chat-db.yaml`, the `n8n-chat`
  Postgres DB): n8n's Postgres Chat Memory owns it. Drupal writing it: **DEAD
  END** (two writers, one table, no contract — and n8n's schema is an
  implementation detail that changes). Drupal *reading* it for history
  rehydration: **LATER**, and the honest path is the `loadPreviousSession`
  action, not SQL — probe first (§1.2.7).
- **Vector DB**: Drupal's RAG (`ai_vdb_provider_postgres` + `search_api`)
  already exposes itself to n8n **through the MCP server** (`ai_search_rag_search`
  tool — live on this site). The agent should query Drupal's knowledge через
  MCP, not share a pgvector schema. **Shared-schema idea: DEAD END; MCP does
  it better and already works.**
- **“Ignore/gray out conflicting fields”**: CORE, already the README's
  “Settings that intentionally do nothing” table. Graying them out in the form
  (a small `#states`/form-alter on provider selection) — nice-to-have at
  Stop 6, low cost, big “this module knows what it's doing” signal.

### “n8n is flexible with inputs/outputs — send info back to Drupal so flows match what Drupal expects?”
**CORE, with a sharp edge.** The response contract is `{output}` (or: name the
last node's field `output`/`text`, else n8n returns the whole object — the
README must teach this). Structured side-channels (the agent returning JSON
for Drupal to act on) collide head-on with **Fork F** in the legacy path — the
`promptJsonDecoder` will eat JSON-shaped answers. Verdict: v1 contract is
**markdown text in `output`, period**; images by URL; anything structured is a
LATER design (and probably rides a dedicated field like `{output, drupal:{…}}`
that we strip before the decoder can see it — probe at Stop 2).

### “Drupal makes the workflow and controls specific nodes?”
**LATER — a different product, honestly bigger than this whole module.**
It's CRUD-out: templates, node pinning (“these nodes are Drupal-managed”),
drift detection, conflict UX. The upstream exporter (§1.4) is the pattern
library *when* this becomes a chapter. For now the creation story is: **deep
link into n8n** ("Create agent workflow" → n8n new-workflow URL, maybe
seeded from a template we publish), tag it, refresh models. Written down;
parked; not forgotten.

### “Tags, like nextcloud-n8n?”
**STOP 1, adopted.** Convention: tag `drupal` (or configurable) = opt-in to
discovery, on top of the hard filter (chatTrigger + active + public). The
public API supports tag filtering on the list endpoint, and tags came back in
every live listing this session. `--all` mode explains exclusions.

### “Metadata — arbitrary data into the chat, auto-added by Drupal?”
**STOP 4, verified feasible today** (§1.2.3). Design there; the one open
question is transport of *per-request* page context to the provider (§1.3,
contexts-vs-tags gap — three candidate mechanisms listed at the stop).

### “Assistant editor vs implementation — where else do assistants show up? what else to sync?”
**Answered by source** (§1.3): the chat blocks (incl. toolbar placement) are
the only consumers on this site; block config chooses the assistant +
UI trimmings (bot name, avatar, first message, style file, structured
results, verbose, stream). Nothing else to sync — block-level settings are
site-owner territory. The README's job (Stop 6) is one honest page: *workflow
in n8n → assistant in Drupal → block in a region → visitor chats.*

### “Full CRUD?”
**The verdict table:**

| | v1 (this chapter) | Later |
|---|---|---|
| **Create** | In n8n (deep link + tag + refresh). We mint the *assistant entity*, never the workflow | Drupal-made workflows (CRUD-out, parked) |
| **Read** | Model discovery + `drush n8n:models` + status (active/public) surfaced | history rehydration via `loadPreviousSession` |
| **Update** | In n8n (“Open in n8n” operation). Rename in n8n → dropdown follows (cache TTL) | managed-nodes drift detection |
| **Delete** | Deleting the *assistant* never touches n8n. Deleted/deactivated workflow → model vanishes from discovery; existing assistant pointing at it gets the connection-failure UX (specific error, Stop 2) | optional “archive workflow” action, if ever |

**Sync vs link: LINK.** Depth budget spent on the chat path, not on mirroring.

### “Control how responses display — custom UI for structured responses?”
**Mostly already answered by the platform** (§1.3): markdown + images render
today; `hook_deepchat_prepend_message` and the theme layer are the sanctioned
extension points; the XSS allowlist bounds how fancy a bubble can get. Kelly's
instinct (“probably out of scope… maybe customize in the n8n flow”) is
correct: **the n8n flow decides the markdown; Drupal renders it.** A theming
recipe belongs in the README (Stop 6). Custom renderers: LATER, demand-driven.

### “Files in and out?”
- **Out (agent → chat): WORKS TODAY** via markdown image/link URLs (§1.3).
  Pattern for the README: n8n flow uploads the artifact somewhere reachable
  (Drupal file via JSON:API/MCP, or any CDN), replies with the URL. This is
  also the n8n-recommended pattern for chat surfaces.
- **In (user → agent): DEAD END *for us*, upstream gap.** The assistant
  pipeline is text-only end-to-end (§1.3). n8n's side is ready
  (`allowFileUploads`, multipart, binary + known
  [metadata-serialization quirk](https://community.n8n.io/t/metadata-from-n8n-embedded-chat-is-not-serialized-correctly-as-json-when-a-file-is-added/108322)),
  Drupal's isn't. Wiring it would mean forking the DeepChat controller — the
  exact “churn-prone frontend layer” this project exists to not own. Document
  in “Not this module”; file/watch an upstream `ai` issue; revisit when
  `UserMessage` grows files.

### “n8n's protocols — streaming, execute last node, sync?”
**Fully mapped** (§1.2): `lastNode` (v1 default), `responseNodes` (the Chat
node's mode — see next), `streaming` (Stop 5, format captured). “Maybe more”:
no — that's the complete set on the current trigger.

### “Tool approval / human-in-the-loop?”
**DEAD END for v1, with a precise cause of death:** n8n's HITL for chat is the
**Chat node** (ex-“Respond to Chat”: send-and-wait, approve/disapprove
buttons) and it is **explicitly unsupported in Embedded mode** — hosted-chat
only ([docs](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-langchain.chat):
*“In Embedded mode, use the Respond to Webhook node instead”* — which can't
pause for a human). Drupal is an embedded client by definition. Drupal's own
approval surface applies to *Drupal-side* tools, which n8n-backed assistants
don't use. **Workaround that needs no code:** the agent asks in plain text and
the user answers in the next message — n8n memory keeps the thread.
Real HITL = LATER, upstream-shaped (n8n would have to support response nodes
on embedded triggers).

### “Can we keep it thin?”
**Yes, and the research made it thinner.** Score from this session alone:
anonymous sessions — free (fixed upstream); images out — free; metadata
passthrough — free (n8n side); markdown+links — free; reset-session — free;
model labels/descriptions — free (Chat Hub fields); MCP direction — still
free. The only genuinely new obligations are the NDJSON adapter (Stop 5) and
owning assistant creation (Stop 6), and both are small.

---

## 3. The forks, updated

| Fork | Status after this session |
|---|---|
| **A — what is a model** | **A1 confirmed**, filter hardened: chatTrigger + active + **`public: true`** (§1.2.4) + opt-in tag. Labels enriched from `agentName`/`agentDescription`. |
| **B — webhook URL** | **B1 VERIFIED live** (§1.2.1-2). `webhookId` from the REST payload; URL `= <base>/webhook/<webhookId>/chat`. B2/B3 tombstoned. |
| **C — what we send** | **C1 stands.** The wrapper hands us the history as `ChatInput`; we take the last user message. `history_context_length` inert — README duty unchanged. |
| **D — system prompt** | **D1 stands** — proven small (80 chars, from the agent entity) and dropped. D3 (opt-in forward) stays a Stop 4 candidate as a metadata key, not a prompt. |
| **E — streaming** | **DEAD on the new API. Stop 5 struck** (§1.3a — three closed gates, `streamed: false` proven). Reborn as an optional upstream MR, Chapter N. |
| **F — promptJsonDecoder** | **DEAD.** Legacy-path-only, and §1.0 says we have no legacy path. Ch1's "sharpest edge" cost us nothing. **An n8n agent may answer in JSON.** |
| **G — assistant generation** | **Collapsed to one column** by §1.0. We mint an **agent + assistant pair** (zero tools, `llm_provider: n8n`), and the stock click-path must degrade — same target either way, since the UI only makes agent-backed assistants. G3 (n8n as a code-defined `AiAgent` plugin) stays parked. |
| **H — the tool path** *(new, Dr K)* | **Real, and probably already built by someone else** (§1.5b). `drupal/mcp_client` + an n8n **MCP Server Trigger** = named/typed/sync n8n tools in Drupal for **zero code** — Ch1 §4 repeating. Our deriver would be *worse* (webhooks declare no schema) and instance-level MCP's `execute_workflow` is **async + polling**. **Fork H is a probe then a README section**, not a build. Dr K's call. |
| **I — is Execute Workflow Trigger reachable?** *(Dr K's question)* | **NO — closed, twice.** No REST execute endpoint exists (405, absent from the live OpenAPI spec); MCP's `execute_workflow` supports only Webhook/Chat/Form/Schedule triggers. Dr K called this before the research did. **Webhook or MCP are the only doors.** |

---

## 4. The route — stops, in order

Every stop follows the ritual: **README section first → `.feature` next →
probes until falsifiable → code → the feature runs live in CI.** A stop is
*scouted* before we drive to it; the route past the next stop is allowed to be
fuzzy. Exit criteria are falsifiable or they aren't exit criteria.

### Stop 0 — Kick the tires *(the carried debt + the unknowns with license plates)*
The pre-drive checklist. No features, all proof.
- **Wire the ephemeral n8n into `integration.yml`** and turn
  `admin-connection.feature` live — Ch1's standing debt, and every later stop's
  harness. The recipe is ported verbatim (Ch1 §7.1); the fixture pack is the
  LLM-free set from Ch1 §7.2 **plus `public: true` on every chat fixture**
  (§1.2.4 — the old fixture plan would have 404'd and we'd have burned a day).
- ~~**Probe the zero-tools degrade**~~ — **DONE 2026-07-15** (§1.1a). One
  `chat()` call, `finished`, `ai_agents_thread_` tags, `streamed: false`.
- ~~**Probe Fork F blast radius**~~ — **struck.** §1.0 killed the legacy path
  and the decoder with it.
- ~~**Probe streaming**~~ — **DONE, and it's dead** (§1.3a).
- **Probe the contexts→provider gap** (§1.3): can the provider read the shared
  `ai_assistant_api.runner` service state at `chat()` time? (Candidates listed
  at Stop 4; this probe picks one.) **The only unknown left blocking design.**
- Exit: pipeline green with live n8n; the contexts question has an answer, not
  vibes.

### Stop 1 — Reading the map *(model discovery)*
- `N8nClient::listWorkflows()` + filter (chatTrigger, active, `public`, tag) →
  `getConfiguredModels()` returns `{workflowId: label}`; label prefers
  `agentName`; cache with TTL + clear on settings save; **never persisted**.
- `drush n8n:models` and `--all` with per-workflow include/exclude reasons.
- README + `model-discovery.feature` updated *first* (new: the `public`
  requirement, the tag convention, Chat Hub name/description enrichment).
- Exit: fixture workflows appear; `no-chat-trigger`, `inactive-agent`, and a
  new `private-agent` fixture provably excluded, with reasons; live in CI.

### Stop 2 — The first sip *(chat, for real)*
The stop where somebody finally tastes something.
- `chat()`: resolve webhookId (cached with the model list) → POST
  `{action: sendMessage, sessionId, chatInput, metadata}` → map `{output}` →
  `ChatOutput`. Last-user-message only (C1). System prompt dropped (D1).
- Error mapping to the assistant's specific-error UX (§1.3): n8n 404
  (inactive/private → `AiSetupFailureException`-flavored), timeout, 5xx from a
  failing workflow, empty output → each lands as the right exception so the
  chatbot shows the configured message, and the log carries the n8n status +
  workflow id (“five-second diagnosis” promise from the README).
- **Close Fork F** with whatever Stop 0's probe demanded.
- Optional-but-designed-now: connection-level **Basic auth for chat webhooks**
  (§1.2.9) — schema + Key reference, even if the first cut only supports
  `none`.
- Exit: `assistant-chat.feature` and `connection-failure.feature` live in CI
  against fixtures; **on the live cluster, a real message to a real agent
  answers in the chat block** — the sip, witnessed by Dr K.

### Stop 3 — The regulars *(the session bridge)*
- Read **`ai_agents_thread_<id>`** from `$tags` → `sessionId` (§1.1b — **not**
  `ai_assistant_thread_`, which the agent path never emits). No storage.
- Verify reset-session → new n8n session; verify anonymous isolation rides the
  upstream fix (§1.3) — the feature file keeps the scenario as regression
  proof.
- README: the session contract table (who owns memory, what clearing what
  clears), already drafted in Ch1's README — now it becomes true.
- Exit: `session-memory.feature` live (echo-agent proves same-session
  threading; the “agent remembers” phrasing stays reframed per Ch1 §7.2).

### Stop 4 — Postcards home *(metadata & context)*
The binding feature — Drupal facts riding along to the agent.
- Always-on metadata (cheap, useful, non-PII): assistant id, Drupal base URL,
  module version. Per-model config (via **our** `api_defaults.yml` surface,
  §2): custom key/values, toggles for user context (uid/roles/name — **opt-in,
  privacy note in README**), page context.
- Page context (`current_route`) transport: whichever mechanism Stop 0's probe
  blessed — candidates: (a) provider reads the shared runner service, (b) a
  tiny upstream MR adding context to `$tags` (the community-friendly fix;
  exporter issue shows the initiative takes these), (c) our own
  `hook_deepchat_settings` additionalBodyProps + a request-scoped service.
- README teaches the n8n side: `$json.metadata.drupal_route` in one screenshot.
- Exit: echo-agent asserts exact metadata payload in CI; a live agent
  demonstrably branches on `drupal_route`.

### ~~Stop 5 — Rolling the windows down *(streaming)*~~ — **STRUCK 2026-07-15**
Struck by §1.0 + §1.3a: streaming cannot reach an agent-backed assistant, and
agent-backed is the only kind we support. Not deferred for effort — **closed for
impossibility**, with three closed gates and a `streamed: false` receipt.

What survives, and where it went:
- The NDJSON format is **captured** (§1.2.5) and the chat trigger's `streaming`
  mode still exists — so the day upstream opens the gate, our side is a known,
  small adapter.
- The **empty-body trap** (§1.2.6) still matters the moment a user sets their
  trigger to `streaming` while we call it expecting `lastNode` — so it moves
  into **Stop 2's error mapping** as a real, testable case: *200 + empty body →
  a clear error, never a silent empty bubble.*
- The upstream MR shape is written down in §1.3a. **Chapter N, Dr K's call.**

### Stop 6 — The concierge *(onboarding & the assistants list)*
The stop that makes the ultimate vision one command long.
- **`drush n8n:assistant <workflow-id>`** (name negotiable): mints the
  **agent + assistant pair** the new API requires — an `ai_agent` entity with
  **`tools: []`** (the passthrough proven in §1.1a) plus an `ai_assistant`
  pointing at it with `llm_provider: n8n`, `llm_model: <workflow-id>`. Label
  from `agentName`, description from `agentDescription`, sane defaults
  (`session_one_thread`, error messages). **Must pass `llm_configuration: []`
  and `specific_error_messages: []` or the entity becomes unloadable —
  §1.1c, with a regression test.** Optional `--sync` flavor: one pair per
  `drupal`-tagged workflow, additive, never destructive.
- **“Open in n8n”** operation on the assistants list (`hook_entity_operation`)
  for n8n-backed assistants.
- Inert-field hygiene: gray/annotate the brain-fields on the assistant form
  when n8n is the provider (small form-alter, big trust win) — and a warning
  when someone attaches Drupal tools to an n8n-backed assistant, because that
  is the one action that turns the proven passthrough (§1.1a) into two agents
  fighting over one conversation.
- README rewritten as the journey it now is: *tag workflow → one drush command
  (or three clicks) → place block → chat.* Quickstart above the fold.
- Exit: on a fresh site with the connection configured, **zero → chatting in
  under five minutes by following the README literally** — timed, by someone
  who isn't the author.

### Stop 7 — The toolbox *(Fork H)* — **a probe and a README section, not a build**
Rewritten 2026-07-16 by §1.5a/§1.5b. The deriver is **cancelled before it was
written** — the best possible outcome for a stop.
- **The probe (an afternoon):** stand up an n8n **MCP Server Trigger** workflow
  with one Custom n8n Workflow Tool attached; `composer require
  drupal/mcp_client`; register the MCP URL at `/admin/structure/mcp-server`
  with a Bearer credential via the Key module; check the tool lands as an AI
  function call and that a Drupal agent can call it and get a **result, not an
  execution id**.
- **If it works** → a README section: *"Want your Drupal assistant to **use**
  n8n workflows rather than **be** one? That's `mcp_client` + an MCP Server
  Trigger. Here's the recipe."* Plus the second recipe for instance-level MCP
  (the n8n-admin agent). **We write no code.**
- **If it doesn't** → we now have a *reason* to build, a bug report for
  Marcus's queue, and the `#[FunctionCall]` + deriver research (§1.5) waiting.
- Either way, document `return_directly` (§1.5) — it's how an n8n agent's answer
  comes back unparaphrased, and it's the same trick regardless of route.
- Re-ask `n8n_webform` here: if "a Drupal thing triggers an n8n workflow" is a
  tool, and tools are `mcp_client`'s job, stock Remote Post + a URL may be the
  whole answer.
- Exit: a written verdict on `mcp_client`, backed by a real attempt — not an
  opinion.

### The horizon *(scouted, parked, in Dr K's order whenever he calls them)*
- **Streaming, upstream** — the `AgentRunner`/wrapper MR sketched in §1.3a.
  The only way the windows ever roll down.
- **Webform → n8n** (`n8n_webform`) — unchanged from Ch1; still opens with
  “does stock Remote Post already do this?”, and Stop 7 may answer it first.
- **History rehydration** — `loadPreviousSession` probe first (§1.2.7).
- **Files in** — upstream watch (§2).
- **HITL** — upstream-shaped (§2).
- **CRUD-out / Drupal-managed workflows** — the different product (§2).
- **G3, n8n workflows as Drupal agent plugins** — the deep integration (§1.1).
- **Per-domain config** — Ch1 Phase 4, mapped in Ch1 §9.1, slot it when the
  connection work reopens.
- **Adopting `drupal.org/project/n8n`** — Ch1 §2.4, still a Chapter-N question.

---

## 5. Risks & open questions *(the living ledger)*

- **We are betting the module on the zero-tools passthrough.** §1.1a proves it
  today, on `ai_agents` 1.3.2. It is not a documented contract — it's emergent
  behaviour of the wrapper's terminal case. If 2.0 changes the loop (say, an
  always-on reflection pass), our one-call guarantee becomes two. Mitigation:
  a **Kernel test that asserts exactly one `chat()` call** for a zero-tools
  agent — a canary that fails loudly on upgrade rather than doubling the bill
  silently. **This is now the chapter's biggest external risk**, inherited from
  the legacy path's old slot.
- ~~**Fork F**~~ — dead with the legacy path (§1.0). No mitigation needed.
- **No streaming, and it's structural** (§1.3a). The risk isn't technical, it's
  expectation: "why doesn't it stream like ChatGPT" will be asked forever. The
  README answers it once, factually, and points at the upstream shape.
- **Public chat webhooks are unauthenticated by default** (§1.2.9). The README
  must say it plainly; Basic-auth support is designed at Stop 2. Until then,
  network posture (in-cluster n8n) is the control.
- **The contexts→provider gap** (§1.3) — Stop 4's design hinges on Stop 0's
  probe; worst case is candidate (c), which is ours alone and stays thin.
- **Streaming empty-body trap** (§1.2.6) — scheduled, Stop 5.
- **`loadPreviousSession` unproven** (§1.2.7) — blocks only the parked
  rehydration feature.
- **n8n moves fast** (Chat Hub arrived between our chapters; the Respond to
  Chat node was renamed *and* re-scoped within months). Version-pin the
  ephemeral n8n in CI *and* keep a canary job on `latest` — the sibling repo's
  playbook.
- **Metadata + file upload serialization quirk** in n8n (community #108322) —
  only bites if files ever land; noted at the tombstone.
- **Every chat message = an n8n execution** — a docs duty, not a bug.

---

## 6. The link library

**Drupal — source-of-truth pages**
- AI module: <https://www.drupal.org/project/ai> · [Develop an AI Provider](https://project.pages.drupalcode.org/ai/latest/developers/writing_an_ai_provider/)
- AI Agents: <https://www.drupal.org/project/ai_agents>
- Drupal AI 2026 roadmap: <https://dri.es/drupal-ai-roadmap-for-2026> · [initiative blog](https://www.drupal.org/about/ai/initiatives/blog/drupal-ai-initiative-introducing-inside-ai-and-outside-ai)
- openrouter “no response / legacy streaming” issue: <https://www.drupal.org/project/ai_provider_openrouter/issues/3576265>
- Agents exporter (mirror-image precedent): <https://www.drupal.org/project/ai_agents_exporter> · [plan issue #3577241](https://www.drupal.org/node/3577241)
- Chatbot-crash release notes (why we pin): [ai 1.4.0-rc1](https://www.drupal.org/project/ai/releases/1.4.0-rc1)

**n8n — source-of-truth pages**
- Chat Trigger: <https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-langchain.chattrigger>
- Chat node (HITL, hosted-only): <https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-langchain.chat>
- @n8n/chat embedded client (the reference implementation of *us*): <https://www.npmjs.com/package/@n8n/chat>
- Chat Hub: <https://docs.n8n.io/build/ways-of-building-workflows/chat-hub> · [beta announcement](https://community.n8n.io/t/announcing-chat-hub-beta/236446)
- Streaming tool-call chunks: <https://github.com/n8n-io/n8n/pull/20499>
- Public API: <https://docs.n8n.io/api/>

**Community threads that paid tolls for us**
- Streaming only from the agent-as-last-node: <https://community.n8n.io/t/stream-ai-agent-response-using-respond-to-chat-nodes/273468>
- Chat-node HITL scope: <https://community.n8n.io/t/why-is-the-respond-to-chat-always-in-waiting-for-input/266971>
- Metadata + files serialization: <https://community.n8n.io/t/metadata-from-n8n-embedded-chat-is-not-serialized-correctly-as-json-when-a-file-is-added/108322>
- Chat Hub requires streaming: <https://community.n8n.io/t/publishing-chat-agents-to-n8n-chat-not-showing-up/291241>

---

## 7. Session log

- **2026-07-17 — the tag becomes the map, and auto-gen is cut.** Dr K settled
  two things the POC had left fuzzy. **(1) No auto-generated assistants.**
  Turning a model into an assistant is a design choice — one model can back
  several assistants with different roles and metadata (many:1 is a *choice*,
  not a rule) — so the module must not automate it. Confirmed in code: nothing
  ever touched `ai_assistant` entities, so there was no 1:1 to enforce and
  nothing to remove but the unbuilt `assistant-sync.feature` and its README
  prose. **(2) The tag is the discovery filter, one per site.** The `tag`
  setting existed but was **dead config — `listChatWorkflows()` never read
  it**; now it does. Verified live that the n8n public API filters
  `/workflows?tags=<name>` (media→8, missing→0, multi=AND), then wired it:
  empty tag = every qualifying workflow (fresh-install friendly, preserves the
  POC), set tag = only that tag's workflows. Proven on the cluster: tagged
  Kubed Assistant `mysite`, `tag=mysite`→shows, `tag=otherteam`→empty,
  `tag=""`→all. **Multisite falls out for free:** because the client reads the
  tag through `configFactory`, a Domain override of `n8n.settings:tag` scopes
  each subsite to its own agents (Ch1 §9.1's mechanism paying out), default
  site unchanged whether Domain is installed or not — the live-domain test is
  `@todo @domain` pending the integration harness. The mental model is now
  clean end to end: **n8n chat trigger = Drupal model; the site tag = which
  agents this site (or domain) sees; the assistant = a human's choice of which
  model to expose, as many faces as they want via metadata.**
- **2026-07-16, homework round — Dr K's questions, answered by doing.**
  - **The defaults page** (`/admin/config/ai/settings`) inventoried live: ~17
    operation types; **n8n appears on exactly one row — plain Chat** — absent
    from speech-to-text/embeddings/etc. (we implement only `ChatInterface`) and
    absent from all four capability rows (Tools, Complex JSON, Structured
    Response, Image Vision — verified per-row). Setting n8n as the *site-wide*
    chat default is legal but steered against in the README. "Why not n8n for
    Speech to Text?" — because n8n has no STT trigger contract to map onto;
    trigger type decides the surface, and no trigger = no surface.
  - **TWO DOORS, proven live:** one workflow, two public chat triggers, two
    registered webhooks, both answering independently. **The chat trigger is
    the addressable unit — the door; the workflow is the building.** Dr K
    called it before the probe did. The POC's discovery had a last-door-wins
    bug; fixed: each public trigger is now its own model (`workflowId` for the
    first door so nothing changes for the 99% case, `workflowId::webhookId`
    for extra doors, label suffixed with the door's node name).
  - **The agent-form confusion is real and upstream:** the `ai_agent` edit
    form has **no provider/model field at all** — provider binding lives only
    on the assistant. And the assistant list shows only Label/Machine
    name/Status, so nothing marks an n8n-backed one. Stop 6's "Open in n8n"
    operation link is the fix.
  - **Personas (Dr K's generic-agent idea) has a clean mechanism:** several
    assistants can already share one model; the assistant's Instructions land
    in the (dropped) system prompt the wrapper hands `chat()` — so an opt-in
    toggle can forward it as `metadata.instructions` with **zero new
    plumbing**, and a generic n8n flow reads it as a variable. Written into
    the README as prose (README-first), not yet specced.
  - Housekeeping: key-module Givens in `admin-connection.feature`; the
    selection-surface inventory now heads `agent-exclusion.feature`; the
    `Two Doors` fixture joins the pack; **9 unit tests added** for
    `listChatWorkflows`/`chatSend` (filters, labels, two doors, contract
    payload, key never leaks to the webhook, refusals, timeout floor).
- **2026-07-16, later still — the spec gets the haircut.** Dr K reset the
  process: **the README comes first**, not the feature files — an idea is
  cheapest to scrutinize as prose, a `.feature` is only earned after due
  diligence proves it possible, and then it's the base case plus a few *likely*
  edges, never an exhaustive matrix (the refactor loop finds the real edges;
  guessed ones are the wrong ones). That flow now lives in
  `features/README.md`, `CONTRIBUTING.md` and `AGENTS.md`. Applied immediately:
  **webform-submit.feature deleted** (parked idea, likely answered by stock
  Remote Post / ECA — now one row in "Not this module"), **assistant-sync
  slimmed 16 scenarios → 7**, **model-discovery 9 → 6**, stale comments purged
  from the survivors (the dead JSON-decoder hazard, the disproven uid-0
  anonymous worry, `ai_assistant_thread_` in AGENTS.md — each replaced with
  what the POC actually proved). README now states the core loop is proven
  live, carries the companion-agent nuance, requires AI ^1.4 + AI Agents, and
  the n8n_webform submodule is out of the tables. **The repo's own docs now
  match the ground truth this chapter dug up.** Noted in passing: the POC's
  `kubed_assistant` agent already shows up as an MCP tool on Drupal's server —
  n8n could now call the assistant that calls n8n. The loop closes itself.
- **2026-07-16, later — THE FIRST SIP. 🥤** Dr K questioned whether the whole
  module was worth existing, answered it himself (*"the goal here is to
  commandeer the drupal chatbox"*), and called for a live POC. Delivered, on
  the live site, same day:
  - Real `chat()` + `listChatWorkflows()` + `chatSend()` written into the
    working copy (webhookId discovery, `public` filter, `ai_agents_thread_` →
    `sessionId`, last-user-message-only, metadata `{source: drupal}`).
  - A real n8n agent — **Kubed Assistant** (`Y3PcIp6oXHUqfUmA`): Chat Trigger
    (public, embedded) → Agent(Gemini) + buffer memory.
  - Model discovery live: exactly `[Y3PcIp6oXHUqfUmA => "Kubed Assistant"]`,
    every non-public/non-chat workflow correctly excluded.
  - **The session bridge threads memory**: two `chat()` calls sharing a thread
    tag; the agent remembered the magic word (*bubbleup*, of course).
  - The **full stock chain** — `AiAssistantApiRunner::process()` → AgentRunner
    → zero-tools wrapper → our provider → n8n → back:
    *“Hello Dr K! I arrived here via a Drupal AI provider handing my messages
    to an n8n agent.”*
  - The **DeepChat controller** driven exactly as the browser drives it →
    `{"html": "..."}`, and a fresh thread correctly got a fresh, isolated
    session.
  - Placed `ui_suite_bootstrap_n8n_chat` (bottom-right, admin-only, streaming
    off). **Awaiting Dr K's click** — the only unverified inch is the browser.
  - POC caveats, honestly: code is in the pod via `dev.sh push` (**ephemeral**
    — a pod restart reverts to v0.1.0's placeholder reply; config entities and
    the block persist and are harmless against it); no error mapping yet; no
    caching (discovery hits n8n twice per message); no tests yet. That is
    Stop 1–3's work, now with a working reference implementation.
- **2026-07-16 (Dr K maps the model, and is right twice).** He asked whether the
  Execute Workflow Trigger is even callable from outside n8n and said *"I'm
  pretty sure we need the webhook for tools."* **Both correct, and both against
  what I'd written the night before.** The live instance's OpenAPI spec has **no
  execute endpoint** (405; you can retry an execution but never start one), and
  MCP's `execute_workflow` supports only Webhook/Chat/Form/Schedule triggers.
  So §1.5's "lead with the Execute Workflow Trigger" was dead on arrival —
  corrected in §1.5a. Chasing it turned up the bigger thing: **n8n instance-level
  MCP** and **`drupal/mcp_client`** (by `drupal/ai`'s own maintainer) already do
  the tool path, auto-discovering MCP tools as AI function calls — so **Fork H
  became a probe and a README section instead of a subsystem** (§1.5b), and the
  module's identity sharpened to the one thing nobody else does: **Chat Trigger
  → model.** Also cleared up a confusion of my own making: **assistants are not
  deprecated** — the *actions-based* assistant is, and the non-agent *runtime
  path* is what §1.0 drops.
- **2026-07-15 (later, Dr K's two calls).** **(1) "New API only, no backwards
  compatibility."** Pruned the chapter hard: Fork F and the whole legacy path
  deleted, Fork G collapsed, **Stop 5 struck for impossibility** (§1.3a). Then
  the probes: the real `AiAgentEntityWrapper`, driven on the live pod with a spy
  provider, proved the zero-tools passthrough is **one** `chat()` call (§1.1a) —
  and caught that **the session-bridge tag Chapter 1 designed around does not
  exist on this path** (§1.1b: `ai_agents_thread_`, not `ai_assistant_thread_`).
  Broke a config entity on the live site on the way (§1.1c) and cleaned it up;
  the break is now a documented trap Stop 6 would have hit. **(2) "Register
  workflows like webhooks as tools"** → Fork H (§1.5), researched to the
  attribute + deriver + `return_directly` level: additive, native to the new
  API, and provisionally Stop 7. Still no module code written. **Still one sip
  owed.**
- **2026-07-15 (Dr K opens the chapter).** Research day. Drupal internals
  re-read against the pod's real versions (`ai` 1.4.4 / `ai_agents` 1.3.2) —
  found the assistants-are-agents shift (§1.1) before it found us. n8n chat
  contract proven live with a temporary probe workflow (created, exercised,
  deleted): webhookId in the REST payload, `/chat` URL shape, metadata
  passthrough, the `public` trap, the NDJSON streaming format, the empty-body
  trap. Dr K's kickoff brainstorm answered block by block (§2), forks updated,
  Fork G opened, route drafted (§4). Not one line of module code written.
  **Still only one sip owed, and it's Stop 2's.**
