# AGENTS.md

Cold-start orientation for AI coding agents (and humans skimming) working in this
repo. Read this before your first edit.

## What this repo is

**n8n for Drupal** — an umbrella repo whose headline is an **AI Provider plugin**
that makes each n8n chat-trigger workflow look like a *model* to Drupal's AI module,
so **n8n agents become Drupal AI Assistants**.

| Module | Job |
|---|---|
| `n8n` | The connection: base URL, API key via the **Key** module, REST client, drush commands. No features of its own. |
| `modules/ai_provider_n8n` | The headline. One `Plugin/AiProvider/`. Supports `chat`, nothing else. |
| `modules/n8n_webform` | Webform submissions → n8n. Subclasses Webform's own Remote Post handler. |

## What this repo is **not**

- **Not a chat widget.** We ship **zero JavaScript**. The chat UI is `ai_chatbot`,
  which already exists inside `drupal/ai`.
- **Not a fork of [`n8n_chat`](https://www.drupal.org/project/n8n_chat).** That's a
  jQuery widget that bypasses Drupal AI entirely. Different job. Don't look at it
  for patterns.
- **Not an AI framework.** `drupal/ai` is the framework. We're one plugin in it.
- **Not the n8n → Drupal direction.** That already works via the deployed **Drupal
  MCP server** at `/mcp/post`. We write nothing for it.

## Read first

- [features/](features/) — **the requirements.** Gherkin, written before the code.
  If you're implementing something, its spec is here. **Start here.**
- [README.md](README.md) — the product as a user sees it.
- [saga/Chapter_1_Packing_the_Van.md](saga/Chapter_1_Packing_the_Van.md) — the why.
  §2 is *verified environment* — **treat it as fact, do not re-derive it.**
- [CONTRIBUTING.md](CONTRIBUTING.md) — process, CI, changelog rules.

## First moves on any task

1. **Find the scenario.** Nearly everything is already specified in `features/`. If
   what you're asked to do contradicts a scenario, **stop and say so** — don't
   silently pick one.
2. **Check saga §2 before researching.** The assistant-vs-agent model, the capability
   filtering, the session bridge and the known hazards are all recorded with file and
   line citations. Re-discovering them wastes a session.
3. **Ask what stock mechanism already does this.** The answer is usually "Drupal or
   n8n already does". See the "don't write it" table in saga §3.
4. **Then write the smallest thing that satisfies the scenario.**

## Architectural non-negotiables

These are not style preferences. Violating one breaks the product's thesis.

- **n8n owns the brain.** Model, system prompt, memory, tools, RAG — all n8n's.
  Drupal owns the chat box and the door. **Never add a Drupal setting for something
  n8n owns.** When Drupal hands us a system prompt, we **drop it**; when it offers
  history, we **ignore it**. This is specified in
  [`features/prompt-ownership.feature`](features/prompt-ownership.feature).
- **n8n is an assistant, never an agent.** The provider supports `chat` and
  **declines the `ChatTools` capability**. Drupal's own capability filtering then
  keeps us out of `ai_agents`, CKEditor AI, and field automators — because those ask
  for the `chat_with_tools` / `chat_with_complex_json` **pseudo operation types**,
  which resolve to `chat` filtered by capability.
  **If you are writing a `hook_form_alter` to hide n8n from somewhere, you have
  broken the capability contract instead of fixing it.** See
  [`features/agent-exclusion.feature`](features/agent-exclusion.feature).
- **Send only the newest message.** n8n's memory node already holds the history,
  keyed by the session id we send. Replaying Drupal's history makes the agent see
  every message twice.
- **Zero JavaScript.** If a task seems to need JS, it's the wrong task.
- **The secret is the Key module's.** Never log it, echo it, or copy it into config.
- **Least code wins.** If your diff grows the surface, justify it in the PR.

## Hard-won gotchas

Each of these cost someone a session. Don't re-pay.

- **The session bridge is a *tag*.** `AiAssistantApiRunner` passes
  `'ai_assistant_thread_' . $threadId` in the `$tags` array to `provider->chat()`.
  With `allow_history == 'session_one_thread'` the id is deterministic:
  `assistant_thread_<assistant>_<user>`. That's the n8n `sessionId`. **No core patch
  needed** — read it out of the tags.
- **Drupal force-overrides the system prompt.** `AiAssistantApiRunner::process()`
  replaces the assistant's `system_prompt` with the module's own bundled
  `resources/system_prompt.txt` unless the site setting
  `ai_assistant_custom_prompts` is on. You cannot switch this off per-assistant. We
  ignore the prompt entirely, so it doesn't matter — but don't waste time trying to
  make the field work.
- **A `promptJsonDecoder` sits between the provider and the response**
  (`AiAssistantApiRunner`, after `chat()`). A JSON-shaped agent answer may be parsed
  as an actions array. This is the sharpest edge in the design — saga §5 Fork F.
- **Behat: literal `(` `)` in step text** becomes a regex group → the step goes
  undefined and **the suite fails while looking green**.
- **n8n's API key has no headless mint.** It's `POST /rest/login` →
  `POST /rest/api-keys`. Two traps: n8n's cookie attributes make **curl's cookie jar
  drop it** (read `Set-Cookie` off the headers and replay verbatim), and the key
  **label must be unique** (`UNIQUE(userId,label)` → duplicates 500).
- **n8n owner provisioning needs a *bcrypt hash*, not a plaintext password**
  (`N8N_INSTANCE_OWNER_PASSWORD_HASH`). It fails quietly otherwise.
- **Integration fixtures are LLM-free** — `Chat Trigger → Code → responseMode:
  lastNode`. **Never add an LLM-backed fixture.** If a scenario needs a real model,
  it's testing n8n, not us.
- **Prefer `responseMode: lastNode`** over a Respond-to-Webhook node — Chat Trigger +
  Respond to Webhook has known rough edges in n8n.
- **`drupal.org/project/n8n_chat` and `/project/n8n` both exist and are both dead.**
  Neither is a source of patterns. See saga §2.3–2.4.

## Reference implementations

When you need "how does Drupal do this?", look here **before inventing**:

| Need | Look at |
|---|---|
| The provider plugin shape | `ai_provider_openai` — same job, ~5 files. **The reference for this repo.** |
| A minimal `AiProvider` + `ChatInterface` | `drupal/ai`'s `tests/modules/ai_test` → `EchoProvider`. |
| Testing a provider with no network | `ai_provider_openai`'s Kernel tests — Guzzle `MockHandler` + `Middleware::history()` asserts the **outgoing payload**. |
| The sibling project's conventions | [nextcloud-n8n](https://github.com/kubed-io/nextcloud-n8n) — same ownership split, applied to files. Its `mint-n8n-key.sh` and `N8nApiTrait` port here. |

## Core commands

```sh
composer run test:unit      # PHPUnit Unit + Kernel
composer run cs:fix         # Drupal coding standards
composer run stan           # PHPStan

cd tests/integration && vendor/bin/behat --config behat.dist.yml

drush n8n:test              # headless "Test connection"
drush n8n:models            # what Drupal can see
drush n8n:chat <id> "hi"    # smoke-test one agent
```

## Process — short version

1. **Branch + PR** to `main`. Squash-merge.
2. **Add a `CHANGELOG.md` line** under `## [Unreleased]` — CI fails without it.
   One line, never a paragraph. **Only ever edit `[Unreleased]`**; versioned
   sections are immutable.
3. **Never bump a version in a feature PR** — `publish.yml` owns versioning.
4. **CI green + one approval** are hard gates.
5. **Docs-only?** Saga/README/markdown changes can go straight to `main` or fold
   into the nearest PR. No ceremony.
6. **Verify action versions** with `gh api repos/<o>/<r>/releases/latest` before
   pinning a `uses:`. LLMs reach for stale majors.
7. **No `${{ }}` inside `run:` bash** — bind to `env:`, read `$VAR`.

## Shape of a feature change

Touch all five, or explain why not:

1. **Spec** — `features/*.feature`
2. **Code** — the smallest change that satisfies it
3. **Unit/Kernel test** — assert the outgoing payload with a mocked transport
4. **Integration step** — `tests/integration/`
5. **Docs** — README entry with `📋 spec:` + `🛠` links, and a CHANGELOG line

## Principles for AI work here

- **Scope creep is the failure mode of this repo.** Your instinct is to be helpful by
  adding options; this module's instinct is to have fewer. When those conflict, the
  module wins. The best PR here usually deletes something.
- **Don't assert what the code does — assert what the spec says.** The spec is in
  `features/`. First-pass AI tests get this backwards.
- **Cite, don't guess.** Saga §2 has file:line citations for every load-bearing
  claim about Drupal AI internals. If you're about to state how `ai` works, check.
- **Say when you're unsure.** A flagged unknown is worth more than a confident wrong
  answer — saga §9 is a list of things we deliberately admitted we hadn't proven.

## When stuck

- Behaviour question → `features/`
- "Why is it like this?" → `saga/Chapter_1_Packing_the_Van.md`
- "How does Drupal do X?" → `ai_provider_openai`, then `drupal/ai` itself
- "How does n8n do X?" → the n8n MCP tools, or the live homelab instance
- Still stuck → say so in the PR/issue rather than inventing a mechanism
