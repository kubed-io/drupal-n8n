# Chapter 1 — Packing the Van

> Somebody said there's a guy out past the county line who makes the ultimate
> smoothie. Nobody's seen him. We're going anyway.
>
> **The smoothie** is the blend: an n8n agent — its own model, its own memory,
> its own toolbelt — answering in a Drupal chat box like it lived there all
> along. **The van** is this repo. **The passengers** are the submodules.
> **The road** is Drupal's AI plumbing, and it turns out to be paved.
> **Dr K** is the one who heard about the smoothie man, owns the van, and decides
> when we have actually arrived.
>
> This chapter is everything before we drive. **Not one sip of smoothie** — we
> don't taste anything until Chapter 2. What we do here is get a van that starts,
> that other people would climb into, and that knows where n8n lives.
>
> It is also the part where you keep running back inside. Everyone's in the van, and
> then — *the phone charger.* Then — *did anyone lock the back door?* Each trip back
> feels like a delay and is actually the reason the trip works. **Every edge case in
> this chapter arrived that way**, and each one is a thing we'd have discovered at
> two in the morning on a hard shoulder instead.

---

## Where we are — 2026-07-15 (evening)

> **The engine turned over.** On the live cluster, from the install Job:
>
> ```
> setup/n8n.php: Key 'n8n_api_key' already current.
> + drush n8n:set-url 'http://n8n.internal:5678'
>  [success] n8n base URL set to http://n8n.internal:5678
> + drush n8n:set-key 'n8n_api_key'
>  [success] n8n API key will be read from the n8n_api_key key.
> + drush n8n:test
>  [success] Connected to n8n.
> setup/n8n.php: connected to n8n at http://n8n.internal:5678.
> ```
>
> A real Drupal, a real 1Password-sourced read-only key, a real n8n. Test
> connection is green in the UI. **We are not out of the driveway — but the van
> starts, and it knows the address.**

---

## The longer version

**The repo is real and green.** [github.com/kubed-io/drupal-n8n](https://github.com/kubed-io/drupal-n8n),
GPL-2.0-or-later, main gated by a ruleset, four PRs merged.

What is **done**:

- **The spec** — 27 Gherkin scenarios across 8 files, keyed to LLM-free fixtures
  the tests own. The README describes the product as a user meets it. Both were
  written before a line of PHP.
- **The skeleton installs, and the thesis is proven on the live cluster.** Pushed
  into the running Drupal pod with `./dev.sh`, against the real `ai` module and the
  real `openai` provider:
  ```
  ASSISTANT dropdown(chat):    ["n8n","openai"]
  AGENT providers(chat+tools): ["openai"]
  ```
  n8n is offered to assistants and invisible to agents — §2.2b, working, for zero
  lines of code. `getSupportedCapabilities()` returning `[]` **is** the mechanism;
  the correct implementation was *not overriding it*.
- **The first-class project** — PHPUnit (8.3 floor + 8.5 production), Drupal coding
  standards, PHPStan as the PHP code scanner via SARIF, Behat against an ephemeral
  n8n, signed commits, required checks, Dependabot, a release workflow. All proven
  by watching each one fail and then pass.

What is **left** — see §8:

> **The connection, end to end.** An admin sets a URL, picks a key, clicks **Test
> connection**, and gets a real answer from a real n8n — with unit tests and the
> `admin-connection` feature running live in the pipeline. Then the same thing
> **per domain**, because a domain wants its own API key against the same n8n.

**No chat. No model discovery. No session bridge.** Those are Chapter 2.

> **This chapter does not close on a checklist.** The scope grows as we find things —
> domain-awareness (§8, Phase 4) did not exist as an idea until a stray button on the
> settings form led to it. That is the process working, not scope creep. **Only Dr K
> ends a chapter.**

---

## Why this chapter looks nothing like the sibling's Chapter 1

`nextcloud-n8n` went: Chapter 1 *make it work*, Chapter 2 *make it a real project*.
We inverted that on purpose, and it is the single biggest structural decision here.

That saga's Chapter 1 was **discovery** — nobody knew what the ownership split was,
whether external storage was viable, how Nextcloud metadata behaved. The project had
to exist before anyone could say what it was. Chapter 2 then paid the tax:
retrofitting CI, tests, docs and a release flow onto code that already worked.

We are not in that position. **We inherited the patterns**: the ownership split, the
saga form, spec-as-gherkin, the ephemeral-n8n recipe, the workflow conventions, the
changelog contract, even `mint-n8n-key.sh` verbatim. Discovery here was hours of
reading `drupal/ai`, not months of building.

So Chapter 1 folds Chapter 2's entire arc **up front**, and the payoff is
compounding rather than theoretical:

| | `nextcloud-n8n` | `drupal-n8n` |
|---|---|---|
| Ch1 | make it work | **spec + skeleton + the whole first-class project** |
| Ch2 | retrofit the project onto working code | **write the logic, into a finished harness** |
| Tests arrive | after the code, as a rescue | **before the code, as the requirement** |
| CI arrives | once there's something to protect | **before there's anything to break** |

The consequence for Chapter 2 is the point: **every line of real logic lands into a
repo that already has a spec to satisfy, a scanner watching, an ephemeral n8n to
test against, and a release button.** No side quests. That is what this chapter is
buying, and it is why the smoothie can wait.

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

### 2.1 The environment this was verified against

Facts about **versions and capabilities**, not about anyone's instance — the
findings in §2.2 are what matter and they are true of any Drupal running these
modules.

- **Drupal 11.4.2 on PHP 8.5**, `wodby/drupal:11` base. Contrib already present and
  relevant: `drupal/ai`, `ai_agents`, `ai_provider_openai`, `ai_provider_anthropic`,
  `gemini_provider`, `key`, `mcp`, `search_api`, `ai_vdb_provider_postgres`,
  `webform`, `eca`, `domain`.
- **`ai_chatbot` + `ai_assistant_api` ship INSIDE `drupal/ai`** as submodules. The
  chat UI needs **no new contrib**, only `drush en`. Chain:
  `ai_chatbot` → `ai_assistant_api` → `ai`.
- **n8n 1.x** with a chat-trigger workflow available, and a Postgres Chat Memory
  node backed by its own database.
- **Drupal's MCP server can be live** at `/mcp/post` with `ai_agent_calling`,
  `jsonapi`, `content` and `ai_function_calling` enabled, exposing
  `ai_search_rag_search` as a tool. That is what makes the n8n → Drupal direction
  free (§4). Auth is `Basic <base64-of-bare-token>` — no colon, and `Bearer` is
  rejected.

> **Fixtures are NOT anyone's real workflows.** An early draft of this chapter
> pointed at existing agents on a live instance and called them the fixtures. That
> was wrong twice over: it coupled the suite to one person's n8n, and a real agent
> needs an LLM credential CI must not have. The tests own their fixtures, and they
> are LLM-free — see §7.2.

### 2.2 Drupal AI internals — the four findings that define the design

**(a) Assistant ≠ Agent, and the assistant is what we want.**
The `ai_assistant` config entity (`ai_assistant_api.schema.yml`) carries exactly
the fields Dr K spotted in the UI: `llm_provider`, `llm_model`,
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
> framework's own mechanism. Dr K's requirement — "block the n8n provider from
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
today.** Your a chat-trigger workflow carries an MCP Client Tool node; point it at
`https://your-site/mcp/post` and the agent can already drive
Drupal's *agents*, JSON:API, content, and RAG search. Dr K's requirement —
"n8n agents would be able to use the drupal agents async from the drupal mcp
server" — **is already satisfied by deployed infrastructure.** It needs a config
line, not a module.

So the only missing direction is the first one. That's Chapter 1.

---

## 5. The forks (decide per area; pros/cons = the escape hatches)

### Fork A — What is a "model"?
- **A1. One model per chat-trigger workflow** *(recommended)*: `getConfiguredModels()`
  lists n8n workflows, filters to those containing a `chatTrigger`, returns
  `{workflowId: name}`. Dr K's agents appear by name next to `gpt-4o`.
  - ➕ Exactly the mental model Dr K asked for. ➖ Needs an API call; must cache.
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
  - ➕ Dr K's "maybe we can just use that info" idea. ➖ Only useful if the user's agent reads it; risks two prompts fighting.
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

Dr K's call, and it inverts the usual order: **the `.feature` files and the
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
> *"the assistant replies with an answer mentioning Dr K"* tests **n8n's memory
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

Dr K: *"I want to go step by step and prove things before each step."* Each phase
ends on a **falsifiable** exit criterion — something that can be shown false, not a
feeling. Status is current as of 2026-07-15.

**The chapter's scope, restated:** this chapter ends at **a production-quality repo
whose only feature is registering an n8n connection and testing it.** Nothing talks
to an agent yet. That is deliberate — see §8.5.

### Phase 0 — The map ✅ **DONE**
- **Goal:** the spec exists before the code.
- **Delivered:** `README.md` written as though the module ships, including the
  "Settings that intentionally do nothing" table. 27 scenarios across 8
  `.feature` files, pruned from a first draft of 54 — half the original set were
  guesses, and one ("two assistants can use two different agents") was testing that
  two is more than one. Fixtures are invented and LLM-free; no scenario references a
  real workflow from anyone's n8n.
- **Learned:** a scenario earns its place by pinning a decision that could
  plausibly go the other way. That rule is now in `features/README.md`.

### Phase 1 — The van starts ✅ **DONE**
- **Goal:** the perfect skeleton, **zero logic**.
- **Delivered:** base `n8n` module (connection settings, `N8nClient`, permission,
  service) + `modules/ai_provider_n8n` with an `#[AiProvider]` plugin. Repo root is
  the module, submodule under `modules/` — the shape `drupal/ai` itself uses.
- **Exit — met, and proven on the live cluster rather than argued:**
  1. ✅ Enables cleanly, no log errors.
  2. ✅ `n8n` appears in the Assistant's provider dropdown.
  3. ✅ `n8n` is **absent** everywhere `ai_agents` picks a provider.
  4. ◑ The `ai_chatbot` block render was not exercised — the provider chain was
     proven at the service level instead, which is the stronger check.
  ```
  ASSISTANT dropdown(chat):    ["n8n","openai"]
  AGENT providers(chat+tools): ["openai"]
  ```
- **Learned — the good kind:** the agent-exclusion needed **zero lines**.
  `AiProviderClientBase::getSupportedCapabilities()` already returns `[]`; the
  correct implementation was *not overriding it*. That is now a loud comment,
  because it is exactly the line a future agent would "helpfully" fill in.

### Phase 2 — Roadworthy ✅ **DONE** *(this is the folded-in Chapter 2)*
- **Goal:** a repo a contributor could pick up cold, before there is anything to
  break. Moved **ahead** of the POC on purpose — see §8.5.
- **Delivered:** public repo, GPL-2.0-or-later; PHPUnit (8.3 floor + 8.5
  production, full sweep on main, single on PR); PHPCS `Drupal`+`DrupalPractice`;
  **PHPStan as the PHP code scanner via SARIF**; Behat against an ephemeral n8n;
  `pr.yml` changelog gate; `publish.yml` via `duplocloud/version-bump`; Dependabot;
  signed commits; a ruleset with required checks; `dev.sh` for the live-pod loop.
- **Exit:** ✅ every check green on a real PR, and every mechanism proven by
  watching it **fail and then pass**.
- **Learned — seven bugs the pipeline found that reasoning did not:**
  1. `drupal/core-dev` unconstrained resolves to **8.0.0-beta15**, a Drupal 8-era
     metapackage with no dependencies. Composer backtracks to it rather than
     failing, so the install goes green with no phpunit. **Pin it.**
  2. Composer path repos **symlink** by default, so `phpunit.xml.dist`'s relative
     bootstrap resolves against the working tree, not the Drupal root.
     `symlink:false`.
  3. XML comments cannot contain a double hyphen — a CLI flag in a `phpcs.xml.dist`
     comment made phpcs reject the entire ruleset with exit 16.
  4. An **unmatched `ignoreErrors` entry** makes PHPStan report an error belonging
     to no file; SARIF renders it with no `locations`; code scanning rejects the
     whole upload. Only ignore what you have seen.
  5. Level 5 was a guess. `drupal/ai` and `ai_provider_openai` both run **level 1**.
  6. `drupal/*` **is not on Packagist** (404). Dependabot reads `composer.json` as a
     root package and cannot resolve `drupal/ai` or `drupal/key` without the
     drupal.org facade declared. CI never noticed, because it installs into
     `drupal/recommended-project`, which already has it.
  7. Dependabot PRs arrive **unassigned** — `addAssignees: author` cannot assign a
     bot — and cannot write a changelog, hence the `no changelog` label.
- **Learned — the scanner probe.** "PHPStan found nothing" and "PHPStan is broken"
  render identically: an empty Security tab. We planted a deliberate
  `method.notFound`, watched it surface as an alert with file and line **and** turn
  the merge gate red, then removed it. An empty tab is now trustworthy. **Do this
  again whenever a gate's healthy state is indistinguishable from its broken one.**

### Phase 3 — Knowing where n8n lives ◑ **THE CONNECTION WORKS; THE SPEC IS NOT WIRED**
- **Goal:** an admin registers an n8n instance and proves it works, from the UI and
  from the CLI. **The gate every other feature depends on** — every scenario in
  `features/` opens with "given the connection is configured and verified".
- **Shape — deliberately the sibling's:** a URL, a token, a **Test connection**
  button. `nextcloud-n8n` landed on that after real use; we copied the conclusion.
- **Delivered:**
  - `N8nClient` + settings form + `drush n8n:set-url|set-key|test`. The CLI is not
    a convenience: `apps/drupal`'s install Job bakes the connection with nobody at
    a form, and a non-zero exit is what lets it fail loudly.
  - **12 unit tests, 104 assertions**, driving the client over a Guzzle
    `MockHandler` + history middleware — so they assert the **outgoing request**,
    not just the parsed reply: that we ask for one workflow rather than all of
    them, that the key travels as `X-N8N-API-KEY` and not `Authorization`, that the
    timeout reaches the transport, that an unconfigured client makes no request.
  - **Live on the cluster**: `apps/drupal` ships the module, pulls the key from
    `the vault path in the site YAML`, and the install Job **verifies** the
    connection. Test connection is green in the UI.
- **Exit — 3 of 4:**
  1. ✅ Unit tests green, including every error path.
  2. ☐ `admin-connection.feature` running **live** in `integration.yml` — still
     `@todo`. **This is the last piece.**
  3. ✅ `drush n8n:test` exits 0 configured, non-zero broken — both observed.
  4. ✅ An admin does it by hand on the live pod and it works.
- **What it cost, and what that bought:** three deploys. Both failures were mine,
  and both were the same mistake — **guessing an API instead of reading it**:
  `N8nCommands::create($container)` called in-process (a `DrushCommands` logger only
  exists when Drush builds the command), then an invented
  `Key::getKeyProviderSettings()`. The same error as the domain-config probe in
  §9.1. **The verify step is why each cost minutes**: every failure landed loudly in
  a Job, against a real site, while the old pod kept serving. Nothing reached a
  visitor. If `setup/n8n.php` had only *configured*, the site would have booted
  green with a connection nobody had ever exercised.
- **Decides:** the admin-screen house style — now set.

### Phase 4 — The same thing, per domain ☐ **FOUND MID-CHAPTER**
- **How this got here:** Dr K asked what an "Enable/Remove domain configuration"
  button on our settings form was. It is `domain_config_ui`, site-wide, nothing to do
  with us — and pulling the thread found a real trap. **The scope of this chapter grew
  because we looked.** That is the process working.
- **The use case:** *a second domain wants its own API key against the same n8n
  instance.* An override carrying only `api_key`, with `base_url` falling through to
  global.
- **Verified on the live pod (§9.1) — do not re-derive:**
  - Overrides live in a config **collection** `domain.<id>`, not a config object.
  - Write with `domain.config_factory_override` → `getOverrideEditable($id, $name)`;
    read with `getOverride($id, $name)`; tear down with `->delete()`.
  - **Overrides apply for free in a request** — `N8nClient` reads through
    `configFactory`, so the Drupal way already paid for this.
  - **`drush --uri` does NOT populate the domain context.** Overrides never apply in
    CLI. Proven both ways.
- **The trap this exists to close:** an admin enables domain config and sets a
  domain-specific key; `drush n8n:test` reads **global**, connects to a **different**
  n8n, and reports success. The cluster bakes a connection the site ignores, and
  nothing says so.
- **Scope:**
  - `--domain=<id>` on the drush commands, reading/writing the collection directly —
    because `--uri` cannot do it for us.
  - `n8n:test` **warns when an override exists that it is not testing.** This is the
    whole point of the phase.
  - `domain` stays an **optional** dependency. `n8n.info.yml` must not require it;
    `--domain` errors clearly when Domain is not installed.
  - CI installs `domain` + `domain_config` and creates a second domain. The CI site
    is the `standard` profile with no Domain, so this is real Behat setup, not a flag.
  - New `@domain`-tagged scenarios in `admin-connection.feature`.
- **Exit:**
  1. A domain with an `api_key`-only override uses its own key against the same n8n.
  2. A domain with no override falls back to global.
  3. `drush n8n:test --domain=<id>` tests what that domain actually uses.
  4. `drush n8n:test` **warns** when an untested override exists.
  5. The `@domain` scenarios run live in the pipeline.
- **Depends on:** P3 green first — deliberately. Get the base case tested and merged,
  then add domain-awareness onto a green foundation.

### §8.5 — Why the POC moved out of this chapter

The original plan had **Phase 2 — First sip**: one real chat round-trip, before the
CI. Dr K re-scoped it, and the reasoning is worth keeping.

A POC proves the *idea*. We already proved the idea — the part that could have been
false was "will Drupal let n8n be an assistant and not an agent", and §2.2b answered
it on the live cluster in Phase 1, for zero lines. What a chat POC would prove now is
the *plumbing*, and plumbing is what Chapter 2 is for.

Meanwhile the connection is the **base case every scenario opens with**. Building it
last would mean every other feature was built on an untested foundation. Building it
now, with tests, means Chapter 2 starts from `Given the connection to n8n is
configured and verified` and that line is *already true*.

So Chapter 1 ends at a boring, complete, well-tested thing that does one job
perfectly. That is a better place to build from than a thrilling thing that half
works.

### Chapter 2 — the logic *(next)*
Everything with a fork attached: model discovery (Fork A), the webhook URL (Fork B —
**B1 unverified**), the session bridge, the thrown-away system prompt (Fork D), the
`promptJsonDecoder` hazard (Fork F), and the **anonymous-session collision** in §9 —
which `./dev.sh probe` can now answer in minutes.

### Later
Webform (§8's old Phase 4 — first answer "does stock Remote Post already do this?"),
streaming (Fork E2), adopting `drupal.org/project/n8n` (§2.4).

**Critical path:** P0 → P1 → P2 → **P3 closes the chapter**.

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

### 9.1 Domain config — verified on the live pod, do not re-derive

Found by pulling on an "Enable/Remove domain configuration" button that turned out to
be `domain_config_ui`, site-wide, nothing to do with us. What it led to:

| Question | Answer | How we know |
|---|---|---|
| Where does an override live? | config **collection** `domain.<id>` — or `domain.<id>.language.<lang>` | read `DomainConfigCollectionUtils` |
| Is it a config object named `domain.config.<id>.<name>`? | **No.** Writing that name creates something nothing reads. | a probe did exactly that and looked like a module bug |
| Write API | `domain.config_factory_override` → `getOverrideEditable($id, $name)` | proven live |
| Read API | `getOverride($id, $name)`; tear down `->delete()` | proven live |
| Partial override? | **Yes** — an override with only `api_key` lets `base_url` fall through to global. This is the whole use case. | proven live |
| Do overrides apply in a request? | **Yes, free.** `N8nClient` reads via `configFactory`. | the Drupal way paying out |
| Do overrides apply in CLI? | **Never.** | proven |
| Does `drush --uri` help? | **No** — `domainId: NULL` with and without it | proven both ways |
| Which service gates it? | `domain.negotiation_context` — **not** `domain.negotiator` | reading `loadOverrides()` |

That last row explains a probe that showed an active domain and
`overridden? false` at the same time: two different services, and only one of them
gates config.

**The consequence, and it is Phase 4's whole reason for existing:** because CLI never
sees an override, `drush n8n:test` reads global config while the site uses the
override. It connects to a different n8n and reports success. **Silent.**

**A lesson worth more than the finding:** the first probe "proved" the module was
broken. It had proved my guess was wrong. When a mechanism looks broken, check that
you are driving it with its own API before believing it.

---

## 10. Verdict: effort & maintenance

Dr K's framing — *"you seem to think stuff like this is easy but it will
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

## 11. Where the work stands

**This is not a closure checklist.** A chapter ends when Dr K says it ends.

We are at the door with our hand on the latch, and we keep going back inside. Phase 4
is *"wait — does the spare key work?"* — it exists because Dr K pointed at a button
nobody had looked at. There will be more, and each one will feel like a delay right up
until the moment it would have been a breakdown at 2am on a hard shoulder with no
signal.

So: expect the list below to grow. **Finding things you forgot is what packing is.**
Folding them in here, rather than deferring them to keep this chapter tidy, is the
whole point.

Standing, not closing:

1. ✅ The README and the `.feature` files describe a product Dr K recognises.
2. ✅ `n8n` is in the Assistant provider dropdown and **absent** from the agent one —
   proven on the live cluster, next to the real openai provider.
3. ✅ The repo is public, CI is green, and every gate has been proven by watching it
   fail and then pass.
4. ✅ Nobody has written a line of JavaScript.
5. ◑ **Phase 3** — an admin registers an n8n instance and it works. **The
   connection is live on the cluster and green in the UI**; 12 unit tests assert
   the outgoing request. What is left: dropping `@todo` from
   `admin-connection.feature` so the pipeline proves it too.
6. ☐ **Phase 4** — the same, per domain, with the drush/UI divergence made loud.

We are at the tail of this chapter, in the transition. The engine turned over; the
van has not left the driveway. What that means concretely: **the connection is a
finished thing, and everything after it is still a specification.**

---

## 12. The state of the van (2026-07-15)

For whoever picks this up cold.

**The repo**: [kubed-io/drupal-n8n](https://github.com/kubed-io/drupal-n8n) ·
GPL-2.0-or-later · main gated by ruleset `18987595` (signed commits, 1 approval,
strict status checks) · required checks are **PR Tasks · PHPUnit PHP 8.5 · PHP
Quality**.

> **`Integration Tests` is deliberately NOT required.** It has a `paths:` filter, so
> a docs-only PR would never report it and would hang forever. That is the trap that
> bit `nextcloud-n8n`. Same reason `PHPUnit PHP 8.3` is not required: it only runs on
> main.

**Merged so far** — `drupal-n8n`: #1 skeleton + spec + CI · #3 dependabot hygiene ·
#4 the drupal.org composer facade · #5 milestone docs · #6 the re-scope · #7
package hygiene + testing from source. (#2 was Dependabot's phpunit bump,
superseded — phpunit 13 needs PHP ≥8.4.1 and we support ≥8.3, so the constraint is
`^11.5 || ^12 || ^13` and phpunit majors stop being a decision.)

**Merged so far** — `kubed-io/drupal`: #16 wires the module into the image and the
site · #17 run the drush commands as subprocesses · #18 the Key entity's real API.

**Testing, as built** (§7 argued it; this is what exists): PHPUnit runs from
**source** via **core's** phpunit config — `./vendor/bin/phpunit -c web/core
web/modules/contrib/n8n`. We ship no phpunit.xml.dist: ours had a relative
bootstrap that only resolved in one layout, which forced `symlink:false`, which
forced a copy, which let `export-ignore` strip the tests, which needed a step to
copy them back. Core's bootstrap is relative to `core/` and always right. Deleting
one file collapsed the whole chain.

**Packaging, as built**: `.gitattributes` `export-ignore` decides what ships —
composer's `vcs` repo downloads a **zip from GitHub's API**, not a clone, so that
file is the only thing keeping our saga and tests out of `web/modules/contrib/n8n`
on production. 310K → 90K. `quality.yml` asserts it against `git archive`, because
CI deliberately dirties its own copy and would otherwise never notice a regression.
GitHub Packages was never an option — GitHub closed "Packages: PHP support" as
`not_planned` in 2023 — and `drupal/*` on Packagist belongs to the Drupal
Association. Neither turned out to matter.

**The fast loop**: `./dev.sh` pushes this working copy into the live Drupal pod.
`enable` · `probe <file.php>` · `heal` · `remove`.

> ⚠️ **The loop's one sharp edge.** Code lands on the pod's **ephemeral image
> filesystem**, but `drush en` writes to the **database**. A pod restart leaves the
> module enabled with no code on disk, and it fatals. `./dev.sh heal` fixes it;
> `remove` when you walk away. The Nextcloud equivalent is safe here because
> `custom_apps` is PVC-backed — this is not.

**Also true**: the pod is a production image (`--no-dev`), so **there is no phpunit
in it** — probe runtime behaviour there, run the suite in CI. `drush php:script`
does not work; use `drush php:eval 'require "/tmp/x.php";'`, and the file **must**
start with `<?php` or require silently echoes it at you.

**Left on the live site** from Phase 1's proof: modules `n8n` + `ai_provider_n8n`
enabled, a dummy Key entity `n8n_dev_key`, and `n8n.settings` pointed at
`http://n8n.internal:5678`. Clean up with `./dev.sh remove` when it stops
being useful.
