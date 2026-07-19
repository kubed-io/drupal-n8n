# AGENTS.md

Cold-start protocol for AI coding agents. **This file is a memory, not a manual** —
every entry is a one-line fact plus a link to the section that owns it. If you find
yourself explaining something here, it belongs somewhere else and this should point
at it.

## The map

**Which file owns what.** If you are about to explain something, check whether one
of these already does — and link to it instead.

| Doc | Owns | Go there for |
|---|---|---|
| [README](README.md) | **the product** | what it does, and why anyone wants it |
| [features/](features/) | **the requirements** | exactly how anything must behave |
| [CONTRIBUTING](CONTRIBUTING.md) | **how to work here** | principles, PR flow, CI, testing, dev loop |
| [SECURITY](SECURITY.md) | **the threat model** | secrets, SSRF, prompt injection |
| [saga/](saga/) | **the why, with evidence** | why a decision is what it is |
| this file | **your protocol** | what to do first, what will bite you |

**The concepts, and where to read them properly.** Skim the one-liner; follow the
link before you act on it.

| Concept | One line | Read |
|---|---|---|
| What we are | An **AI Provider plugin**: each n8n chat-trigger workflow becomes a *model*, so n8n agents become Drupal **assistants**. | [README § how it works](README.md#how-it-works) |
| The whole thesis | n8n agent ≡ Drupal **assistant**. A Drupal *agent* is a much smaller thing. Confusing them is the one fatal mistake. | [README § the big idea](README.md#the-big-idea-an-n8n-agent-is-a-drupal-assistant) |
| The ownership split | n8n owns the model, prompt, memory, tools. Drupal owns the chat box and the door. **No third place.** | [README § who owns what](README.md#who-owns-what) |
| Why n8n is hidden from agents | We decline the `ChatTools` capability; Drupal's own filtering does the rest. Zero lines of code. | [README § why n8n is absent](README.md#why-n8n-is-deliberately-absent-from-the-agent-dropdown) |
| Settings that do nothing | The assistant form shows prompt/history/tools fields that are **inert** under n8n. This surprises everyone. | [README § settings that intentionally do nothing](README.md#settings-that-intentionally-do-nothing) |
| What ships | `n8n` (connection) + `ai_provider_n8n` (the headline). | [README § modules in this repo](README.md#modules-in-this-repo) |
| What we deliberately are **not** | Not a widget, not a fork of `n8n_chat`, not an AI framework, not the n8n→Drupal direction, not a tool bridge — MCP owns tools in both directions. | [README § not this module](README.md#not-this-module) |
| Where the work stands | The **core loop is proven live** — connection, discovery, chat, session bridge, signature. Next leg **scouted, not driven**: agents passthrough (Stop 8), extended signature (Stop 4), the shareable template (Stop 9). | [saga Ch2 §1.6](saga/Chapter_2_The_Drive.md) |
| How a feature becomes a feature | **README prose → due diligence → base case → few likely edges.** Never generate exhaustive scenario matrices. | [CONTRIBUTING § the spec comes first](CONTRIBUTING.md#the-spec-comes-first--and-the-readme-comes-before-the-spec) |

---

## First moves — in this order

1. **Find the scenario.** Almost everything is already specified in
   [features/](features/). If your task contradicts one, **stop and say so** — do
   not silently pick a side.
2. **Read [saga Ch2 §1](saga/Chapter_2_The_Drive.md) before researching anything
   about Drupal AI or the n8n chat contract.** It is *verified ground truth* with
   file:line citations and live-probe receipts, and it supersedes Chapter 1 where
   they disagree. **§1.6 is the next leg** (agents passthrough, extended
   signature, the template). Re-deriving any of it wastes a session.
3. **Ask what stock mechanism already does this.** The answer is usually "Drupal or
   n8n already does" — see the "don't write it" table in saga §3.
4. **Then write the smallest thing that satisfies the scenario.**

**The ritual for anything new** (the saga's spine, and the order is the point):
**plan it in the saga → research & _probe until it is falsifiable, never assert
from memory_ → README prose → `.feature` with the base case + a few _likely_
edges → code on a PR, tightening scenarios as the refactor loop surfaces the real
edges.** Two failure modes to refuse: skipping straight to code, and guessing an
exhaustive edge matrix up front (the guessed edges are the wrong ones). A weird
feature not built beats a weird feature built — kill ideas at the prose stage.

---

## Non-negotiables

Violating one breaks the product's thesis, not just its style.

- **n8n owns the brain** — model, prompt, memory, tools. Never add a Drupal setting
  for something n8n owns. → [CONTRIBUTING § n8n owns the brain](CONTRIBUTING.md#n8n-owns-the-brain--never-take-it-back)
  · spec: [drupal-signature.feature](features/drupal-signature.feature)
- **n8n is an assistant, never an agent.** We support `chat` and decline the
  `ChatTools` capability; Drupal's own filtering does the rest. **Writing a
  `hook_form_alter` to hide n8n means you broke the contract instead of fixing it.**
  → [README § why n8n is absent from the Agent dropdown](README.md#why-n8n-is-deliberately-absent-from-the-agent-dropdown)
  · spec: [agent-exclusion.feature](features/agent-exclusion.feature)
- **Zero JavaScript.** If a task seems to need JS, it is the wrong task.
  → [CONTRIBUTING § least code wins](CONTRIBUTING.md#least-code-wins)
- **Least code wins.** A diff that grows the surface must justify itself.
  → [CONTRIBUTING § least code wins](CONTRIBUTING.md#least-code-wins)
- **The secret is the Key module's.** Never log, echo, or copy it into config.
  → [SECURITY § secrets policy](SECURITY.md#secrets-policy)
- **The spec comes first.** Behaviour change ⇒ change the `.feature` in the same PR.
  → [CONTRIBUTING § the spec comes first](CONTRIBUTING.md#the-spec-comes-first)

---

## What will bite you

Each cost someone a session. One line each; follow the link before you act on it.

**Drupal AI internals** → [saga Ch2 §1.1](saga/Chapter_2_The_Drive.md) *(supersedes Ch1 §2.2 — the AI module moved)*

- **New API only.** Every assistant is agent-backed; this module supports no other
  mode. Do not add code for the legacy non-agent path, and do not promise
  streaming — it is structurally unreachable for agent-backed assistants
  (three closed gates, saga Ch2 §1.3a).
- The session bridge is a **tag** — **`ai_agents_thread_<key>`** in `$tags`.
  `ai_assistant_thread_` does NOT exist on this path; if you see it referenced,
  it is a stale memory from Chapter 1.
- The companion agent must have **zero tools** — that is what makes one message
  equal exactly one provider call (proven live). Tools on it = two brains fighting.
- Creating an `ai_assistant` in code? **`llm_configuration` and
  `specific_error_messages` must be `[]`, never omitted** — a null there makes the
  entity unloadable AND undeletable through the entity API (saga Ch2 §1.1c).
- A workflow is only a model when its chat trigger is **publicly available** —
  active alone still 404s. Fixtures too.
- `metadata.instructions` is the **agent entity's stored `system_prompt`**, not
  `$input->getSystemPrompt()` — the latter is the loop's per-turn runtime prompt
  with framing noise. Empty instructions ⇒ the key is absent (zero-detail
  passthrough). · spec: [drupal-signature.feature](features/drupal-signature.feature)
- The session id is the runner's thread key from the `ai_agents_thread_` tag,
  sent as n8n `sessionId` — the `@n8n/chat` model, sourced from Drupal's session.
  `session_one_thread` is **only stable per browser (web session)**, NOT across
  CLI processes — so tests prove the bridge deterministically, not per-browser
  stability. `metadata.context_window` = the assistant's `history_context_length`
  (absent when 0). The chat trigger's Load Previous Session is n8n's own concern;
  we never call `loadPreviousSession`. · spec: [session-memory.feature](features/session-memory.feature)

**Domain config** → [saga §9.1](saga/Chapter_1_Packing_the_Van.md)

- Overrides live in a config **collection** `domain.<id>`, **not** a config object.
- **`drush --uri` does NOT populate the domain context** — overrides never apply in
  CLI. Our commands must take `--domain` and hit the collection directly.
- Read/write via `domain.config_factory_override` →
  `getOverride()` / `getOverrideEditable()`.

**Testing + CI** → [CONTRIBUTING § testing](CONTRIBUTING.md#testing) · [§ what CI expects](CONTRIBUTING.md#what-ci-expects)

- **There is no `phpunit.xml.dist`** — we use core's. Do not add one back; that file
  is what caused the copy/symlink/restore mess.
- **`--group` does not scope core's config** — its suites load *every* contrib
  module's tests. Pass our module as a path.
- Kernel tests need **`SIMPLETEST_DB`** from the environment.
- **Behat: a literal `(` or `)` in step text** becomes a regex group → the step goes
  undefined and **the suite fails while looking green**.
- **Fixtures are LLM-free** and must stay so.
  → [CONTRIBUTING § fixtures are LLM-free](CONTRIBUTING.md#fixtures-are-llm-free-and-must-stay-that-way)

**n8n** → [saga §7.1](saga/Chapter_1_Packing_the_Van.md)

- The API key has **no headless mint**: `POST /rest/login` → `POST /rest/api-keys`.
  curl's cookie jar drops n8n's cookie — read `Set-Cookie` off the headers. The key
  label must be unique or it 500s.
- Owner provisioning needs a **bcrypt hash**, not a plaintext password. Fails quietly.
- Prefer **`responseMode: lastNode`** over a Respond-to-Webhook node.

**The mistake I keep making** — and the reason half this list exists:

> **Read the API before you call it.** Two live-cluster failures came from inventing
> methods that looked plausible (`DrushCommands` in-process; `Key::getKeyProviderSettings()`),
> and a domain-config probe "proved" a module was broken when it had proved my guess
> wrong. When something looks broken, first check you are driving it with its own API.

---

## Reference implementations

Look here **before inventing**:

| Need | Look at |
|---|---|
| The provider plugin shape | `ai_provider_openai` — same job, ~5 files. **The reference.** |
| A minimal `AiProvider` + `ChatInterface` | `drupal/ai`'s `tests/modules/ai_test` → `EchoProvider` |
| Testing a provider with no network | `ai_provider_openai`'s Kernel tests — Guzzle `MockHandler` + `Middleware::history()` |
| Our own conventions, applied to files | [nextcloud-n8n](https://github.com/kubed-io/nextcloud-n8n) — the sibling |

---

## Commands

```sh
./vendor/bin/phpunit -c web/core --testdox web/modules/contrib/n8n   # from a Drupal root
composer run cs:fix
composer run stan
vendor/bin/behat --config behat.dist.yml                             # from tests/integration/

drush n8n:set-url <url>
drush n8n:set-key <key_id>     # a Key entity's name, never the secret
drush n8n:set-tag <tag>        # scope discovery to this tag; empty clears it
drush n8n:test
```

**Those four drush commands exist**, and the **Site tag** field is on the settings
form. The README also documents `n8n:models` and `n8n:chat` — not yet built; that file
is the **specification**, so it describes the finished product. Do not cite those two as
working. The provider's `chat()`, model discovery, the **site-tag filter**, and the
**Drupal signature** ARE real as of Chapter 2, but live only in the working copy until
released.

**We do NOT generate assistants.** The old `n8n:sync` / `n8n:assistant` idea is
dropped — turning a model into an assistant is the admin's design choice (one model can
back several assistants). The module never touches `ai_assistant` entities. The site
tag's only job is scoping model discovery, one tag per site (Domain-overridable).

`./dev.sh` pushes this working copy into the live Drupal pod for runtime probing.
**Read its header before using `enable`** — the code is ephemeral but `drush en`
persists, so a pod restart breaks the site until `./dev.sh heal`.
→ [CONTRIBUTING § the fast loop](CONTRIBUTING.md#the-fast-loop-iterate-against-the-live-cluster)

---

## Process — the 20-second version

Full detail: [CONTRIBUTING § the flow](CONTRIBUTING.md#the-flow-issue--pr--merge).

1. Branch → PR → squash-merge. CI green + one approval are hard gates.
2. **Every PR adds a `CHANGELOG.md` line under `[Unreleased]`** — CI fails without
   one. One line, never a paragraph. Only ever edit `[Unreleased]`.
   → [§ commits, changelog, versions](CONTRIBUTING.md#commits-changelog-versions)
3. **Never bump a version in a feature PR** — `publish.yml` owns that.
4. **Docs-only?** Straight to `main`, or fold into the nearest PR. No ceremony.
5. Touching a workflow? **No `${{ }}` inside `run:`; no `cd` — use
   `working-directory:`; verify action versions with `gh api .../releases/latest`.**
   → [§ workflow authoring conventions](CONTRIBUTING.md#workflow-authoring-conventions)

**Shape of a feature change** — touch all five, or say why not:
spec → code → unit/kernel test → integration step → docs + changelog.
→ [CONTRIBUTING § anatomy of a feature change](CONTRIBUTING.md#anatomy-of-a-feature-change)

---

## Working with the maintainer

- **Expect to be grilled, and assume the review is right.** When it is not, **push
  back with evidence** — the ecosystem's own source, not assertion. A correction is
  wanted more than compliance.
- **Scope creep is this repo's failure mode.** Your instinct is to help by adding
  options; this module's instinct is to have fewer. The best PR here usually deletes
  something.
- **Never declare a chapter finished.** Scope grows as edge cases surface — that is
  the process working. Only the maintainer closes a chapter.
- **Say when you are unsure.** A flagged unknown beats a confident wrong answer;
  saga §9 is a list of things we deliberately admitted we had not proven.
