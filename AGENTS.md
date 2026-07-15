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
| What ships | `n8n` (connection) + `ai_provider_n8n` (the headline) + `n8n_webform` (later). | [README § modules in this repo](README.md#modules-in-this-repo) |
| What we deliberately are **not** | Not a widget, not a fork of `n8n_chat`, not an AI framework, not the n8n→Drupal direction. | [README § not this module](README.md#not-this-module) |
| Where the work stands | The **connection is live**; everything past it is specification. | [saga § where we are](saga/Chapter_1_Packing_the_Van.md) |

---

## First moves — in this order

1. **Find the scenario.** Almost everything is already specified in
   [features/](features/). If your task contradicts one, **stop and say so** — do
   not silently pick a side.
2. **Read [saga §2](saga/Chapter_1_Packing_the_Van.md) before researching anything
   about Drupal AI.** It is *verified environment* with file:line citations.
   Re-deriving it wastes a session.
3. **Ask what stock mechanism already does this.** The answer is usually "Drupal or
   n8n already does" — see the "don't write it" table in saga §3.
4. **Then write the smallest thing that satisfies the scenario.**

---

## Non-negotiables

Violating one breaks the product's thesis, not just its style.

- **n8n owns the brain** — model, prompt, memory, tools. Never add a Drupal setting
  for something n8n owns. → [CONTRIBUTING § n8n owns the brain](CONTRIBUTING.md#n8n-owns-the-brain--never-take-it-back)
  · spec: [prompt-ownership.feature](features/prompt-ownership.feature)
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

**Drupal AI internals** → [saga §2.2](saga/Chapter_1_Packing_the_Van.md)

- The session bridge is a **tag** — `ai_assistant_thread_<id>` in `$tags`, not a
  parameter. No core patch needed.
- Drupal **force-overrides the assistant's system prompt** with its own bundled
  file. You cannot switch it off per-assistant. We ignore the prompt entirely, so
  do not try to make that field work.
- A **`promptJsonDecoder`** sits between the provider and the response. A
  JSON-shaped answer may be parsed as an actions array. Sharpest edge in the design
  — saga §5 Fork F.

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
  → [features/README § fixtures](features/README.md#fixtures--the-tests-own-their-workflows)

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
drush n8n:test
```

**Those three drush commands are all that exist.** The README also documents
`n8n:models` and `n8n:chat` — that file is the **specification**, so it describes
the finished product. They arrive with model discovery in Chapter 2. Do not cite
them as working.

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

## Working with Kelly

- **He will grill you, and he is usually right.** When he is not, **push back with
  evidence** — the ecosystem's own source, not assertion. He is explicit that he
  wants the correction rather than compliance.
- **Scope creep is this repo's failure mode.** Your instinct is to help by adding
  options; this module's instinct is to have fewer. The best PR here usually deletes
  something.
- **Never declare a chapter finished.** Scope grows as edge cases surface — that is
  the process working. Only Kelly closes a chapter.
- **Say when you are unsure.** A flagged unknown beats a confident wrong answer;
  saga §9 is a list of things we deliberately admitted we had not proven.
