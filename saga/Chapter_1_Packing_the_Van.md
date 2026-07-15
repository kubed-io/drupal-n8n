# Chapter 1 — Packing the Van

> Somebody said there's a guy out past the county line who makes the ultimate
> smoothie. Nobody's seen him. We're going anyway.
>
> **The smoothie** is the blend: an n8n agent — its own model, its own memory,
> its own toolbelt — answering in a Drupal chat box like it lived there all
> along. **The van** is this repo. **The passengers** are the submodules.
> **The road** is Drupal's AI plumbing, and it turns out to be paved.
>
> This chapter is everything before we drive: the map, the van starting, one
> sip to prove the smoothie is real, and then making the van roadworthy enough
> that other people would get in it.

---

## 0. What this is (and what it is NOT)

This is **not** a chat widget. It is **not** a fork of
[`n8n_chat`](https://www.drupal.org/project/n8n_chat). It is **not** a new AI
framework for Drupal.

It is a **thin wrapper**: an **AI Provider plugin** that makes each n8n
chat-trigger workflow look like a *model* to Drupal's AI module, so that
**n8n agents become Drupal AI Assistants** — selectable from the same dropdown
that currently says `openai`, usable by every surface that already speaks to a
provider.

The governing constraint, stated once and enforced everywhere below:

> **Least code possible. Every line we write is a line Drupal already wanted to
> write for us. If a stock Drupal mechanism does the job, we use it and we do
> not improve on it.**

**Decision (locked):** `n8n_chat` (drupal.org) is **rejected as a base**. It is a
jQuery widget that posts from the browser straight to a webhook; Drupal AI is
never in the loop. It shares zero code with a provider plugin. See §2.3 for the
autopsy. We are not forking it and we are not competing with it — it answers a
different question.

---

## 1. Ownership split (the key architectural decision)

The same split that keeps `nextcloud-n8n` small applies here, and it is what
makes "thin wrapper" true rather than aspirational.

### The module owns
1. **The connection** — n8n base URL + API key (via the **Key** module), and a
   **Test connection** button. Shared by every submodule.
2. **The provider plugin** — `chat` operation only. Translate Drupal's
   `ChatInput` → n8n's chat-trigger contract, and n8n's `{output}` → `ChatOutput`.
3. **Model discovery** — list n8n workflows over the REST API, filter to those
   with a chat trigger, present them as models.
4. **The session bridge** — map Drupal's assistant thread id → n8n's `sessionId`.
5. **The webform handler** (`n8n_webform`) — a subclass of Webform's own Remote
   Post handler that knows what an n8n endpoint is.

### The user owns (NOT our code)
- **The agent itself.** Its model, its system prompt, its memory, its tools, its
  RAG. All of that lives in n8n and is configured in n8n. We do not proxy it, we
  do not mirror it, and we **do not offer Drupal settings that duplicate it**.
- **The conversation.** n8n's Postgres Chat Memory holds the history, keyed by
  the `sessionId` we hand it. Drupal stores nothing.

This is the crux: **the intelligence is not ours, and neither is its state.** We
own a translation layer and a dropdown entry. That's the whole product.

---

## 2. Verified environment (checked live — do not re-discover)

Everything in this section was read from source or the live cluster on
**2026-07-15**. Treat it as fact and do not spend a second re-litigating it.

### 2.1 The cluster
- **Drupal**: multisite on `wodby/drupal:11`, ns `cloud`, image `kubed/drupal:11`.
  Contrib installed by `hooks/install.sh` — relevant lines:
  `drupal/ai`, `drupal/ai_agents`, `drupal/ai_provider_openai`,
  `drupal/ai_provider_anthropic`, `drupal/gemini_provider`, `drupal/key`,
  `drupal/mcp:^1.0`, `drupal/search_api`, `drupal/ai_vdb_provider_postgres`,
  **`drupal/webform:^6.3@dev`**, **`drupal/eca:^3`**, `drupal/bpmn_io:^3`.
- **`ai_chatbot` + `ai_assistant_api` are already in the image** — they ship
  inside `drupal/ai` as submodules. The chat UI needs **no new contrib**, only
  `drush en`. Dependency chain: `ai_chatbot` → `ai_assistant_api` → `ai`.
- **n8n**: ns `flow`, `https://n8n.kellyferrone.com`, API key in vault at
  `N8N/API/key`. Has a dedicated **`n8n-chat` Postgres database**
  (`apps/n8n/components/db/chat-db.yaml`) backing the Postgres Chat Memory node.
- **A canonical agent already exists** — `N8N Workflow Agent` (`ttCS4UYKg9Q2RSsy`):
  `Chat Trigger → Config → AI Agent`, with `Anthropic Chat Model`,
  `Postgres Chat Memory`, and an `n8n MCP` tool. Others tagged `agent`:
  `Homelab Project Manager`, `Observer Agent`, `Chat With inventory`, `Movie Buff`.
  **These are the fixtures.** No new n8n build needed to start.
- **Drupal MCP is live** at `/mcp/post` with `ai_agent_calling`, `jsonapi`,
  `content`, `ai_function_calling` plugins enabled, and **`ai_search_rag_search`
  is exposed as a tool**. Auth is `Basic <base64-of-bare-token>` (no colon —
  see `apps/drupal/README.md`; `Bearer` is rejected).

### 2.2 Drupal AI internals — the four findings that define the design

**(a) Assistant ≠ Agent, and the assistant is what we want.**
The `ai_assistant` config entity (`ai_assistant_api.schema.yml`) carries exactly
the fields Kelly spotted in the UI: `llm_provider`, `llm_model`,
`llm_configuration` (the "Provider Configuration" block), plus `system_prompt`,
`pre_action_prompt`, `instructions`, `allow_history`, `history_context_length`,
`actions_enabled`, `use_function_calling`, `roles`, and one optional `ai_agent`.
An **assistant is the orchestrator that holds a provider**; an **agent is a
tool-using unit an assistant delegates to**. So:

> **n8n agent ≡ Drupal assistant.** A Drupal *agent* is a much smaller thing —
> roughly "use one tool". Our n8n agents should never be Drupal agents.

**(b) Blocking n8n from the agent surface is FREE.** This is the finding the
whole design rests on. `ai_agents` never asks for `chat`. It asks for
`chat_with_tools` and `chat_with_complex_json`:

```
ai_agents/src/PluginBase/AiAgentEntityWrapper.php:457
    getDefaultProviderForOperationType('chat_with_tools')
ai_agents/src/PluginBase/AiAgentBase.php:190
    getDefaultProviderForOperationType('chat_with_complex_json')
```

Those are **pseudo operation types** (`ai/src/Utility/PseudoOperationTypes.php:54`)
— `actual_type: chat`, filtered by an `AiModelCapability`:

| Pseudo type | actual_type | filter capability |
|---|---|---|
| `chat_with_tools` | `chat` | `ChatTools` |
| `chat_with_complex_json` | `chat` | `ChatJsonOutput` |
| `chat_with_structured_response` | `chat` | `ChatStructuredResponse` |
| `chat_with_image_vision` | `chat` | `ChatWithImageVision` |

Meanwhile the **assistant's** dropdown is built by
`AiProviderFormHelper::generateAiProvidersForm($form, $form_state, 'chat', 'llm', …)`
→ `getAiProvidersOptions('chat')` → `isUsable('chat')` — **no capability filter**.

> **Therefore:** a provider that supports `chat` and declines the `ChatTools`
> capability is **visible to Assistants and invisible to Agents**, through the
> framework's own mechanism. Kelly's requirement — "block the n8n provider from
> use in the agents" — costs **zero lines**. It is a `return FALSE`.

**(c) There is a clean session bridge, and it needs no core changes.**
`AiAssistantApiRunner::assistantMessage()` tags every provider call:

```php
// AiAssistantApiRunner.php:508-519
$tags = [
  'ai_assistant_api',
  'ai_assistant_api_assistant_message',
  'ai_assistant_api_assistant_message_' . $this->assistant->id(),
  'ai_assistant_thread_' . $this->threadId,      // ← the bridge
];
$response = $provider->chat($input, $connect['model_id'], $tags);
```

And when `allow_history == 'session_one_thread'` the thread id is
**deterministic** (`AiAssistantApiRunner.php:174`):

```php
$this->threadId = 'assistant_thread_' . $this->assistant->id() . '_' . $this->currentUser->id();
```

> **Therefore:** our `chat()` reads the `ai_assistant_thread_` tag, strips the
> prefix, and hands it to n8n as `sessionId`. **n8n's Postgres Chat Memory owns
> the conversation**; Drupal stores nothing. Stable per user, per assistant.

**(d) The complication, stated honestly.** `AiAssistantApiRunner::process()`
(line 340) does this:

```php
$system_prompt = $this->assistant->get('system_prompt');
if (!$this->settings->get('ai_assistant_custom_prompts', FALSE)) {
  // Overrides YOUR prompt with the module's bundled one.
  $system_prompt = file_get_contents($path . 'system_prompt.txt');
}
if ($system_prompt) {
  $return = $this->assistantMessage(TRUE);       // pre-prompt pass
  if ($return instanceof ChatOutput) {
    return $return;                              // ← the happy path
  }
  // …otherwise iterate $return['actions']
}
```

Three consequences we must **prove, not assume** (§8, Phase 2):
1. The assistant **always builds a system prompt** and we will **throw it away** —
   `$input->getSystemPrompt()` is ignored, because n8n's agent has its own. You
   cannot switch this off from the assistant's own config; it's a site setting
   (`ai_assistant_custom_prompts`).
2. With **no actions and no `ai_agent`**, it is a **single** `provider->chat()`
   call and the `ChatOutput` returns directly. That is genuinely thin. Good.
3. A `promptJsonDecoder->decode()` sits between our output and the return
   (line 527). If an n8n agent answers with something JSON-shaped, it may be
   mis-parsed as an actions array. **This is the sharpest edge in the design.**

### 2.3 The `n8n_chat` autopsy (why we start clean)

Read at `git.drupalcode.org/project/n8n_chat`, tag `1.0.0-alpha1`:

| Signal | Finding |
|---|---|
| Releases | `1.0.0-alpha1`, one tag |
| Commits | **5, all on 2025-06-19** — silent ~13 months |
| Install base | ~10 sites |
| Flags | "Minimally maintained", "Maintenance fixes only", **not** security-covered |
| `src/` | **2 classes**: a settings form and a block plugin |
| `drupal/ai` | **`suggest` only** — not a dependency |
| Architecture | browser → jQuery `$.ajax` → n8n webhook. Drupal AI never involved. |
| `ai_integration`, `user_context` | **Dead config.** Written by the form, saved, **read by nothing** (grepped: only tests reference them). The project page advertises "Integration with Drupal's AI Chatbot module"; it does not exist. |

Its webhook contract is nonetheless **useful intelligence** — it confirms the
n8n chat-trigger shape from a second source (`js/n8n-chat.js:175,249`):
`{action: 'sendMessage'|'getHistory', sessionId, chatInput}`.

### 2.4 The name — there's an abandoned gas station called `n8n`

`drupal.org/project/n8n` — **"N8N connector (triggers & webhooks)"**, wouters_f
+ daften (Dropsolid), created 2021-06-03, updated 2026-06-16. Status:
**Unsupported** *and* **Obsolete**. 8 stars, 1 open issue. Its own notice reads:

> "This module is obsolete and no longer maintained. The Drupal ecosystem now
> has more robust and actively maintained alternatives … including ECA … Flowdrop
> … and the built-in JSON:API module."

So the project id `n8n` is **taken but derelict**. Drupal.org has a formal
process for adopting abandoned projects. **Option, not a decision** — parked for
a later chapter (§9). Worth knowing now because it shapes the machine names we
pick today.

---

## 3. Drupal mechanisms to bank on (the "don't write it" table)

Reference module studied for patterns — the `integration_openai` of this project:
**`ai_provider_openai`**. Its entire shape is 5 files + tests. That is our
ceiling, not our floor.

| Concern | Stock Drupal mechanism | Notes / what we do NOT write |
|---|---|---|
| Provider plugin | `#[AiProvider]` attribute + `AiProviderClientBase` + `ChatInterface` | One class. Base class gives config, key repo, http client. |
| Secret storage | **Key module** (`key_select` form element) | Already in the image; already how `openai` does it. **No custom secret handling.** |
| Settings form | `ConfigFormBase` + `config/schema/*.yml` | One form: URL + key select. Under `admin/config/ai`. |
| HTTP client | `\Drupal::httpClient()` / injected Guzzle via base | **No custom client abstraction.** |
| Model list | `getConfiguredModels()` → n8n `GET /api/v1/workflows` | Cache it. n8n is the source of truth; we never store a model list. |
| Chat UI | **`ai_chatbot`** (already in image) | **We build no frontend.** Zero JS. |
| Assistant config | **`ai_assistant_api`** (already in image) | The assistant entity is the config surface. We add none of our own. |
| Session/thread | the `ai_assistant_thread_` **tag** (§2.2c) | No storage, no schema, no session handling. |
| Blocking agents | decline the `ChatTools` capability (§2.2b) | `return FALSE`. No form alters, no hooks. |
| Webform → n8n | extend **`RemotePostWebformHandler`** | Webform already POSTs to a URL. We add an endpoint picker + auth. |
| Drupal ← n8n | **existing Drupal MCP** at `/mcp/post` | Already live. **We write nothing for this direction.** |
| Defaults | `definitions/api_defaults.yml` | Same file `ai_provider_openai` ships. |

Count the "we write nothing" rows. That is the thesis of the whole project.

---

## 4. The two directions, concretely

```
 Drupal ──(ai_provider_n8n: ChatInput → {chatInput, sessionId})──▶  n8n agent   (this repo, Ch1)
 Drupal ◀───────────────(n8n agent's MCP Client Tool → /mcp/post)──  n8n agent   (ALREADY WORKS)
 Drupal ──(n8n_webform: submission → form/webhook)──────────────▶  n8n         (this repo, later)
```

The middle line matters and is easy to miss: **n8n → Drupal already works
today.** Your `N8N Workflow Agent` carries an MCP Client Tool node; point it at
`https://drupal.kellyferrone.com/mcp/post` and the agent can already drive
Drupal's *agents*, JSON:API, content, and RAG search. Kelly's requirement —
"n8n agents would be able to use the drupal agents async from the drupal mcp
server" — **is already satisfied by deployed infrastructure.** It needs a config
line, not a module.

So the only missing direction is the first one. That's Chapter 1.

---

## 5. The forks (decide per area; pros/cons = the escape hatches)

### Fork A — What is a "model"?
- **A1. One model per chat-trigger workflow** *(recommended)*: `getConfiguredModels()`
  lists n8n workflows, filters to those containing a `chatTrigger`, returns
  `{workflowId: name}`. Kelly's agents appear by name next to `gpt-4o`.
  - ➕ Exactly the mental model Kelly asked for. ➖ Needs an API call; must cache.
- **A2. One model, workflow chosen in settings**: single "n8n" model; the target
  workflow is a config value.
  - ➕ No API dependency. ➖ Defeats the point — one agent per site.
- **A3. Manual list in config**: admin types workflow ids.
  - ➕ No API. ➖ Drifts; user does the machine's job.

### Fork B — The webhook URL for a given model
- **B1. Derive from the chat trigger's `webhookId`** *(preferred)*: read it from
  the workflow JSON the REST API already returns.
  - ➖ **Unverified.** Phase 2 must confirm the REST payload exposes it.
- **B2. Convention** — `<n8n_url>/webhook/<id>`.
  - ➖ Brittle if n8n changes routing or the trigger sets a custom path.
- **B3. Admin maps model → URL**.
  - ➕ Always works. ➖ Manual. The fallback if B1 fails.

### Fork C — What we send as `chatInput`
- **C1. Last user message only** *(recommended)*: n8n owns memory (§2.2c), so
  replaying history would **double-count** it against the Postgres Chat Memory.
  - ➕ Correct given the session bridge. ➖ Drupal's `history_context_length` becomes a lie → we must document that it does nothing.
- **C2. Flattened transcript**: send the whole `ChatInput`.
  - ➖ Double memory. n8n's agent sees its own history *and* Drupal's copy.
- **C3. Configurable**.
  - ➖ Two code paths, one of which is wrong. Refuse.

### Fork D — The system prompt Drupal insists on building (§2.2d)
- **D1. Ignore it** *(recommended)*: drop `$input->getSystemPrompt()`. n8n's agent
  has its own.
  - ➕ Honest; keeps n8n authoritative. ➖ Drupal's assistant prompt fields do nothing → **must be documented loudly**.
- **D2. Forward it** as an extra field to n8n and let the workflow decide.
  - ➕ Kelly's "maybe we can just use that info" idea. ➖ Only useful if the user's agent reads it; risks two prompts fighting.
- **D3. Forward it only when the user opts in**, per model.
  - Probably where we land eventually. **Not in the POC.**

### Fork E — Streaming
- **E1. Non-streaming first** *(recommended)*: n8n `responseMode: lastNode` → `{output}`.
- **E2. Streaming** via `StreamedChatMessageIterator`.
  - ➖ Real work, and the churn-prone part. **Chapter 2 or later, on purpose.**

### Fork F — Response decoding hazard (§2.2d.3)
The `promptJsonDecoder` may eat a JSON-shaped agent answer.
- **F1. Prove the blast radius in Phase 2** *(required)*: ask an agent to reply
  with JSON and watch what the chatbot renders.
- **F2. If it bites** — the escape hatch is likely wrapping/escaping our
  `ChatOutput` text so it can't parse as an actions array.

---

## 6. Repo & module architecture

```
drupal-n8n/                        (repo — "the van")
├── n8n.info.yml                   base module: connection only
│   └── src/
│       ├── Form/N8nSettingsForm.php     URL + key_select + Test connection
│       └── N8nClient.php                REST: list/get workflows
├── modules/
│   ├── ai_provider_n8n/           passenger 1 — agents as assistants  (Ch1)
│   │   └── src/Plugin/AiProvider/N8nProvider.php
│   └── n8n_webform/               passenger 2 — submissions → n8n     (later)
│       └── src/Plugin/WebformHandler/N8nRemotePostWebformHandler.php
├── features/                      the executable spec (§7)
├── saga/                          this
└── README.md                      the UX spec (§7)
```

**Why an umbrella:** both passengers need the *same connection*. This is exactly
how `drupal/ai` ships (`modules/ai_chatbot`, `modules/ai_assistant_api`) — the
native contrib shape, so it costs us nothing in strangeness budget.

**Machine names** (decided): base `n8n`, provider **`ai_provider_n8n`** (matches
`ai_provider_openai` / `ai_provider_anthropic` exactly, package `AI Providers`),
handler `n8n_webform`. Repo is `drupal-n8n`. The base id `n8n` collides with the
derelict project in §2.4 — **fine locally, a Chapter-N problem if we ever
publish.**

---

## 7. The map is the spec (TDD, and the README drives the UX)

Kelly's call, and it inverts the usual order: **the `.feature` files and the
README are written first and are the requirements.** Code exists to make them
true. This is lifted straight from `nextcloud-n8n`, where "the `.feature` files
*are* the requirements" and the README links each feature to its executable spec.

**The testing stack** (researched, not assumed — and corrected once):

**The decision (settled): PHPUnit for unit; Behat + gherkin for functional and
integration.** Same split `nextcloud-n8n` runs.

| Layer | Tool | Lives in | Why |
|---|---|---|---|
| **Unit + Kernel** | **PHPUnit** | `tests/src/Unit`, `tests/src/Kernel` | Drupal's native story; what `ai_provider_openai` ships. Guzzle `MockHandler` + `Middleware::history()` asserts the **outgoing payload** with no network. |
| **Functional + integration** | **vanilla Behat + Guzzle** | `tests/integration/` (own composer) | The `.feature` files **are** the requirements. Drives `drush` + HTTP against a **real, ephemeral n8n**, asserting both sides. |

Drupal's PHPUnit `Functional` / `FunctionalJavascript` tiers are **skipped** — Behat
covers that layer.

**Rejected (do not re-open):** `drupal/drupalextension` (Behat + **Mink** + Selenium
browser-driving, its own step vocabulary, aimed at whole-site acceptance testing;
articles all 2015–2021, revived 2026 by DrevOps but niche, **no AI-ecosystem
precedent**). **Codeception + gherkin** (native gherkin, but a second framework, no
Drupal-AI precedent). **Drupal Test Traits** — PHPUnit against an existing site;
notable because it exists *since its authors found Behat "increasingly awkward"* —
the strongest anti-Behat datapoint in the ecosystem, but it has no gherkin.

**Note on standards:** PHPUnit is the contrib norm and drupal.org's GitLab CI runs
only it — so if we ever adopt `drupal.org/project/n8n` (§2.4), the Unit/Kernel suite
is what runs there, and Behat stays in **our** GitHub CI. Exactly how `nextcloud-n8n`
splits it. Gherkin is not un-Drupal: the contributor guide's "writing automated
tests" skill reads *"Write testing scenarios in Gherkin … using a framework like
Behat or Codeception."*

### 7.1 The ephemeral n8n (proven recipe — port it, don't reinvent it)

`nextcloud-n8n` already solved "a real n8n, fresh, per CI run". Read
`.github/workflows/integration.yml`, `tests/integration/bin/mint-n8n-key.sh`, and
`bin/preload-n8n.sh`. **The n8n half ports verbatim.**

1. **n8n as a GHA `services:` container** — `docker.n8n.io/n8nio/n8n:latest`, port
   `5678`, health-gated on `wget -q -O - /healthz | grep -q ok` (interval 5s, retries
   40, start-period 20s). Fresh per job ⇒ ephemeral by construction, no teardown.
2. **No signup wizard** — pre-provision the owner via env:
   `N8N_INSTANCE_OWNER_MANAGED_BY_ENV: "true"`, `..._EMAIL`, `..._FIRST_NAME`,
   `..._LAST_NAME`, and **`N8N_INSTANCE_OWNER_PASSWORD_HASH`** — a **bcrypt hash, not
   plaintext**. Generate: `php -r 'echo password_hash("n8npassword", PASSWORD_BCRYPT);'`.
   Also `N8N_DIAGNOSTICS_ENABLED: "false"`, `N8N_HIRING_BANNER_ENABLED: "false"`.
3. **The API key has NO headless mint** — the two-step recipe is the way:
   `POST /rest/login` → `POST /rest/api-keys` → `{data:{rawApiKey}}`. Two hard-won
   traps, both already paid for: n8n's cookie attributes make **curl's cookie jar drop
   it**, so read `Set-Cookie` from the response headers and replay it verbatim; and
   the key **label must be unique** (`UNIQUE(userId,label)` → a duplicate 500s), so
   stamp it with a timestamp. Mask it (`::add-mask::`), pass via step output, store
   nowhere. **Fail the job loudly if no key** — never run the suite without one.
4. **Preload is a CONTROL CASE** — fixtures are created through **n8n's own public
   API**, never through the app under test, so scenarios act on genuinely
   pre-existing workflows.
5. **`docker-compose.yaml` is dev/devcontainer only** — CI does not use it. `.env.example`
   mirrors the same canonical values by hand.

**What changes on our side** — only the Drupal half:

| `nextcloud-n8n` | `drupal-n8n` |
|---|---|
| checkout `nextcloud/server`, mount app into `apps/` | `composer create-project drupal/recommended-project`, add a **path repo** for this module + `drupal/ai` + `drupal/key` |
| `occ maintenance:install --database=sqlite` | `drush site:install --db-url=sqlite://…` |
| `php -S localhost:8080` for WebDAV | `php -S localhost:8080 -t web` for the chat + admin surface |
| `OccTrait` | `DrushTrait` |
| `WebDavTrait` | `ChatTrait` |
| **`N8nApiTrait`**, `mint-n8n-key.sh`, the `services:` block, EnricoMi reporter, `~@todo` filter | **verbatim** |

### 7.2 The fixture problem — and why it resolves into the ownership split

`nextcloud-n8n`'s fixtures are trivial (`manualTrigger → Set`). **Ours can't be**:
our scenarios need **chat-trigger** workflows. And a real AI Agent node needs an LLM
credential — which CI must not have (cost, flakiness, secrets, non-determinism).

**Resolution — and it falls straight out of §1:** *we test the transport, not the
intelligence.* The agent's brain is explicitly **not ours** (§1, "The user owns"). So
every fixture is **LLM-free**:

```
Chat Trigger  →  Code  →  (responseMode: lastNode)
```

Deterministic, free, instant, credential-less — and it exercises the exact contract
we care about: `{action, sessionId, chatInput}` in, `{output}` out. Planned fixtures:

| Fixture | Serves |
|---|---|
| `echo-agent` | echoes `chatInput` + `sessionId` back → most of `assistant-chat`, `prompt-ownership`, `session-memory` |
| `canned-agent` | replies with a fixed string → "replies with the answer is 42" |
| `json-agent` | replies with a JSON object → **Fork F**, the `promptJsonDecoder` hazard |
| `empty-agent` | replies with nothing → the no-results path |
| `slow-agent` | a Wait node → the timeout scenario |
| `failing-agent` | errors mid-run → the failure path |
| `no-chat-trigger` | a webhook/manual workflow → proves the model filter excludes it |
| `inactive-agent` | chat trigger, left inactive → proves the active filter |

> **Consequence for the spec — flagged, not hidden.** `session-memory.feature`'s
> *"the assistant replies with an answer mentioning Kelly"* tests **n8n's memory
> node**, not our module. Our real contract is *"both messages reached n8n with the
> same session id"* — which `echo-agent` proves exactly. That scenario should be
> reframed or tagged `@todo` when the steps are written. **Don't build an
> LLM-backed fixture to satisfy it.**

**Caveat to verify in Phase 2:** Chat Trigger + `Respond to Webhook` has known rough
edges in n8n. Prefer `responseMode: lastNode` so the last node's output is the
response — no Respond node at all.

**Behat trap** (paid for once on `nextcloud-n8n`): a literal `(` `)` in step text
becomes a regex group → the step goes undefined and the suite fails *while looking
green*. **Verified clean across all 54 scenarios.**

**Precedent to steal (Phase 3):** `drupal/ai` ships **`tests/modules/ai_test`** with
an `EchoProvider` (a reference `AiProvider` + `ChatInterface` — effectively our Phase
1 skeleton) and **YAML request/response fixtures** at
`tests/resources/ai_test/requests/chat/*.yml`.

**"Can gherkin drive Drupal's own functional tests?"** — asked, researched,
answered: **no, not `BrowserTestBase`.** Its model is fresh-install-per-test-class,
in-process, on an isolated DB prefix; there is no gherkin front-end for it and
nobody marries the two. Gherkin *with Drupal internals* has three real paths:

| Path | What it buys | Why not |
|---|---|---|
| Behat `api_driver: drupal` | Behat **bootstraps Drupal**; steps call the real API. Closest to "gherkin + functional". | Drags in Mink + Drupal Driver + a second step vocabulary. |
| Behat `drush` driver | Steps shell out to drush. **This is what `nextcloud-n8n` already does with `occ`.** | — it's what we're doing. |
| Codeception + gherkin | Native gherkin across acceptance/functional/unit. | A whole second framework; no Drupal-AI precedent. |

Gherkin is **not** un-Drupal: the contributor guide's "writing automated tests"
skill explicitly reads *"Write testing scenarios in Gherkin or other testing
language, or write tests in PHP … using a framework like Behat or Codeception."*

**Honest counterpoint on the record:** [Drupal Test Traits](https://packagist.org/packages/weitzman/drupal-test-traits)
(Moshe Weitzman) runs PHPUnit against an *existing* site and exists **because** its
authors "used Behat for over a year and found it getting more and more awkward."
It's the strongest argument against Behat in Drupal — and it has no gherkin, so it
fails the actual requirement. Noted so nobody re-opens it as a discovery.

**Decision:** vanilla Behat + Guzzle + a `DrushTrait`. Of the 54 scenarios in
`features/`, nearly all assert **CLI + the n8n API**, not pixels. The one that
tempts toward `drupalextension` is `agent-exclusion` — but "which providers are
offered for `chat_with_tools`" is a **capability contract, not a UI**: it belongs in
a **Kernel test** for precision, with the gherkin step driving `drush php:eval`
against the `ai.provider` service. Zero new dependencies.

**Escape hatch:** if UI-shaped scenarios pile up, add `drupalextension` in
**blackbox + drush** mode later — no Selenium needed, these are plain forms. It's
an additive change, not a rewrite.

**Behat trap to avoid** (learned the hard way on `nextcloud-n8n`): a literal `(` `)`
in step text becomes a regex group → the step goes undefined and the suite fails
*while looking green*. Keep parentheses out of step text. **Verified clean across
all 54 scenarios.**

**Fixtures already exist** — the n8n agents in §2.1. No fixture-building phase.

The opening feature set (each becomes `features/*.feature`):

| Feature | The use case |
|---|---|
| `admin-connection` | Admin points Drupal at n8n, stores the key, tests the connection. *The "I'm logged in" gate — prerequisite to everything, exactly as in `nextcloud-n8n`.* |
| `model-discovery` | The n8n agents appear as models; non-chat workflows do not. |
| `assistant-chat` | Chatting with an assistant backed by an n8n agent returns the agent's answer. |
| `session-memory` | A follow-up message reaches the same n8n session — the agent remembers. |
| `agent-exclusion` | **n8n never appears where a Drupal *agent* picks a provider.** (§2.2b) |
| `prompt-ownership` | Drupal's system prompt does not reach the agent; n8n's does. (Fork D) |
| `connection-failure` | n8n down / bad key / **deactivated workflow** → a real error in the chat box, not a hang. |
| `webform-submit` | **@todo** — a Drupal form's submissions start an n8n workflow, target picked from a list. Parked until Phase 4 answers "does stock Remote Post already do this?" |

---

## 8. Phased delivery (prove each step before the next)

Kelly: *"I want to go step by step and prove things before each step."* Each
phase ends on a **falsifiable** exit criterion. Chapter 2's whole "first-class
project" arc is folded in here as Phase 3 — we already paid for those lessons on
`nextcloud-n8n` and don't need to rediscover them.

### Phase 0 — The map *(risk: none — it's writing)*
- **Goal:** the spec exists before the code.
- **Scope:** `README.md` (UX, use cases, functional requirements) + the
  `features/*.feature` set from §7. No PHP.
- **Exit:** Kelly reads the README and recognises the product he asked for.
- **Depends on:** this chapter.

### Phase 1 — The van starts *(risk: low — "hello world install")*
- **Goal:** the perfect skeleton, **zero logic**. Kelly's explicit ask.
- **Scope:** `n8n.info.yml` + `ai_provider_n8n` with an `#[AiProvider]` plugin that
  supports `chat`, returns **one hardcoded fake model**, and whose `chat()`
  returns a canned `ChatOutput` ("hello from the van"). Settings form with URL +
  `key_select`. Install into the image via `hooks/install.sh`.
- **Exit — all four, or the phase isn't done:**
  1. Module enables cleanly; no log errors.
  2. **`n8n` appears in the Assistant's AI Provider dropdown.**
  3. **`n8n` does NOT appear anywhere `ai_agents` picks a provider** (§2.2b).
  4. An `ai_chatbot` block backed by that assistant renders the canned string.
- **Decides:** packaging into the image; that the assistant surface accepts us at
  all. **Depends on:** P0.

### Phase 2 — First sip *(risk: medium — the POC, and where the design gets tested)*
- **Goal:** one real round-trip. Kelly: *"a POC of the source code before we do
  any cicd."*
- **Scope:** `N8nClient` (list workflows); `getConfiguredModels()` filtered to
  chat triggers (Fork A1); resolve the webhook URL (Fork B — **B1 unverified,
  B3 is the fallback**); `chat()` = last user message (C1) + thread-id tag →
  `sessionId` (§2.2c) + POST + `{output}` → `ChatOutput`; ignore the system
  prompt (D1).
- **Exit — the falsifiable list:**
  1. `N8N Workflow Agent` appears **by name** in the model dropdown.
  2. A message in Drupal's chat box is answered **by that agent**.
  3. A **follow-up shows the agent remembered** → proves the session bridge.
  4. The n8n execution log shows **one** execution per message → proves no double-call.
  5. **Fork F probed:** ask the agent for JSON; record whether `promptJsonDecoder`
     mangles it. *Record the answer here even if it's ugly.*
  6. **Anonymous session isolation proven** (§9, and `SECURITY.md`): two anonymous
     visitors must **not** share an n8n session. Drupal derives the thread id from
     `$this->currentUser->id()`, which is **`0` for every anonymous visitor** — if
     that reaches n8n unmodified, every anonymous conversation lands in one memory
     session and leaks across visitors. `shouldStoreSession()` *may* assign a unique
     key first (`AiAssistantApiRunner.php:170`), which would save us. **Unproven —
     prove it.** If it does collapse, derive anonymous sessions from the PHP session
     instead of the user id.
- **Decides:** Forks A, B, C, D, F. **Depends on:** P1.

### Phase 3 — Roadworthy *(risk: low — folded-in Chapter 2)*
- **Goal:** a repo a contributor could pick up cold. Only **after** the POC proves
  the idea is real — no ceremony around a thing that might not work.
- **Scope:** public GitHub repo under `kubed-io`; PHPUnit Unit+Kernel green in CI;
  Behat wired to the live n8n + a Drupal test site, driving the §7 features;
  `CONTRIBUTING.md` / `AGENTS.md` / `SECURITY.md` / `CHANGELOG.md`; PHPCS
  (Drupal coding standards) + PHPStan; `publish.yml` with `duplocloud/version-bump`;
  Dependabot; branch protection.
- **Exit:** the §7 features run green in the pipeline; a cold contributor can get
  a local stack up from `CONTRIBUTING.md` alone.
- **Depends on:** P2. **Explicitly not before it.**

### Phase 4 — The second passenger *(risk: medium)*
- **Goal:** `n8n_webform` — Drupal forms submit to n8n.
- **Scope:** subclass `RemotePostWebformHandler`; endpoint picker fed by
  `N8nClient` (form triggers + webhooks); auth header; response → tokens.
- **Open question:** how much does stock Remote Post already give us? **If the
  honest answer is "all of it", we ship documentation instead of a module** and
  that is a win, not a failure.
- **Depends on:** P2 (the client + connection).

### Phase 5 — Stretch
- Streaming (Fork E2). Forwarding the system prompt as opt-in (Fork D3).
- Adopting `drupal.org/project/n8n` (§2.4).
- A `link`-style "open this agent in n8n" affordance.

**Critical path:** P0 → P1 → P2 → P3. P4 forks off after P2.

---

## 9. Risks / open questions

- **Anonymous session collision — the one that could bite a real user.** Drupal's
  deterministic thread id is `assistant_thread_<assistant>_<uid>`, and **every
  anonymous visitor is `uid 0`**. If that reaches n8n as the `sessionId`, all
  anonymous visitors share one memory session and can read each other's
  conversation. `shouldStoreSession()` may assign a unique key first — **unverified**
  (`AiAssistantApiRunner.php:170-175`). Specified by `session-memory.feature`'s "Two
  visitors do not share a conversation"; a **P2 exit criterion**; documented in
  `SECURITY.md`. **Block any anonymous-facing deployment until this is proven.**
  Fallback: derive anonymous sessions from the PHP session, not the user id.
- **The `promptJsonDecoder` hazard (Fork F)** — the sharpest edge. An agent that
  answers in JSON may be parsed as an actions array. **Probe in P2.**
- **The thrown-away system prompt (Fork D1)** — Drupal's assistant UI will show
  prompt fields that **do nothing**. This is a *documentation* obligation, not a
  bug; the README must say so plainly or users will file issues forever.
- **`history_context_length` is likewise a lie** under C1. Same obligation.
- **Webhook id discovery (Fork B1) is unverified.** If the REST payload doesn't
  expose the chat trigger's webhook id, we fall to B3 (admin maps it) and the UX
  gets worse. **Cheap to check in P2 — check it early.**
- **n8n agents are black boxes.** No token accounting, no model introspection, no
  moderation hook. Drupal's `ai_logging` / `ai_external_moderation` will see very
  little. Accept and document.
- **We are a `chat` provider that is not an LLM.** Anything expecting raw-model
  behaviour (`ai_ckeditor`, `ai_automators`, field-level AI) will either filter us
  out via capabilities (good) or behave oddly (document). **P1 exit criterion 3
  is what keeps this honest.**
- **`@dev` webform** — the image pins `drupal/webform:^6.3@dev`. P4 inherits that
  risk.
- **README must document:** that n8n owns the prompt, the model, the memory, and
  the tools; that Drupal's corresponding settings are inert; the
  assistant-vs-agent distinction (§2.2a) and *why* n8n is deliberately absent from
  the agent dropdown; and the session-id contract.

---

## 10. Verdict: effort & maintenance

Kelly's framing — *"you seem to think stuff like this is easy but it will
probably take a week or two"* — is the right prior, and the research mostly
supports it, though not evenly:

- **Genuinely small (days):** the provider plugin itself. `ai_provider_openai` is
  ~5 files. Ours is smaller — no streaming, no vision, no tools, no moderation.
  The session bridge is reading a string out of an array. The agent-blocking is
  `return FALSE`. **This part really is a thin wrapper.**
- **The actual cost is not the code.** It's (a) the `.feature` spec + Behat
  harness, (b) the CI/CD story, (c) proving the three unknowns — webhook-id
  discovery, the JSON decoder, and whether one chat call really is one chat call.
  Those are Phase 2/3, and they're where the week or two goes.
- **What stays cheap forever:** the agent is the user's. Its model, memory, tools,
  and prompt are n8n's problem. We have **no frontend** — `ai_chatbot` is the UI.
  **The churn-prone layer that sank Phase 5 of `nextcloud-n8n` does not exist
  here**, because we're not writing any JS at all.

**Bottom line:** the surface is smaller than `nextcloud-n8n` by a lot, and the
riskiest layer (frontend) is absent by construction. What's left is a translation
function and the discipline to not add features to it. The danger isn't
difficulty — it's scope creep dressed up as helpfulness.

---

## 11. What "done" looks like for this chapter

1. The README and the `.feature` files describe a product Kelly recognises.
2. `n8n` is in the Assistant provider dropdown and **absent** from the agent one.
3. Kelly types into a Drupal chat box and **his own n8n agent answers**.
4. He types again and **it remembers**.
5. The repo is public, CI is green, and the features run in the pipeline.
6. Nobody has written a line of JavaScript.

Then we find out whether the smoothie man is real.
