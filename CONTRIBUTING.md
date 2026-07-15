# Contributing

Thanks for stopping by. This is **n8n for Drupal** — an umbrella repo that makes
n8n AI agents usable as Drupal AI Assistants. It lives under the
[kubed-io](https://github.com/kubed-io) GitHub org, shares workflow plumbing with
the rest of that org, and has a deliberate process around getting changes in.
Please read this whole doc before you push code — most of the "why is my PR
stuck?" questions are answered below.

If you only have time for one paragraph: **the `.feature` files are the
requirements — read them before you write code. Prefer opening an issue first so
the work can be scoped, then open a PR with tests and a clear changelog entry, and
verify your change on a real Drupal instance against a real n8n before asking for
review.**

---

## Repo tour

A quick map so you know where to look. Each entry has a one-liner; the file or
folder itself is the authoritative detail.

| Path | What lives here |
|---|---|
| [README.md](README.md) | User-facing docs: what it does, the assistant-vs-agent model, admin settings, drush commands. **Start here for "how does it work?"** |
| [features/](features/) | **The executable specification.** Gherkin `.feature` files that *are* the requirements. Written before the code. **Start here for "what should it do?"** |
| [saga/](saga/) | Long-form design narrative across chapters. Chapter 1 = packing the van (design, skeleton, POC, and making the repo first-class). **The "why" behind the code.** |
| [CHANGELOG.md](CHANGELOG.md) | Keep-a-Changelog format. Every PR adds a line under `## [Unreleased]`. |
| [CONTRIBUTING.md](CONTRIBUTING.md) | This file — process, conventions, dev loop. |
| [SECURITY.md](SECURITY.md) | How to report vulnerabilities. Read before filing a "security" issue publicly. |
| [AGENTS.md](AGENTS.md) | Cold-start orientation for AI coding agents. |
| [LICENSE.txt](LICENSE.txt) | GPL-2.0-or-later. Drupal's licence, not negotiable — see [Licence](#licence). |
| [.gitattributes](.gitattributes) | `export-ignore` decides what ships. Composer's `vcs` repo downloads a **zip from GitHub's API** rather than cloning, so this file is the only thing keeping our tests, CI and saga out of `web/modules/contrib/n8n` on someone's production site. **Adding a dev-only file? Add it here too.** |
| [composer.json](composer.json) | Declares the **drupal.org composer repository**. Modules hosted *on* drupal.org omit this because their packaging resolves `drupal/*` for them — we are on GitHub, so we must say where those packages come from, or Dependabot cannot resolve them and the update job errors out. Composer only honours `repositories` from the **root** package, so this is invisible to a site installing the module. |
| `n8n.info.yml`, `src/` | The **base module**: the n8n connection, the REST client, drush commands. Ships no features of its own. |
| `modules/ai_provider_n8n/` | The headline: n8n agents as Drupal AI Assistants. One `Plugin/AiProvider/`. |
| `modules/n8n_webform/` | Webform submissions → n8n. Subclasses Webform's own Remote Post handler. |
| `tests/src/{Unit,Kernel}/` | PHPUnit. Drupal-native, runs without a booted site. |
| `tests/integration/` | Behat suite (own `composer.json`), fixtures, and the ephemeral-n8n scripts. |
| `.github/workflows/` | `pr.yml`, `tests.yml`, `quality.yml`, `integration.yml`, `publish.yml`. |

---

## Principles

Internalize these. They are the difference between a PR that merges and one that
spirals.

### Least code wins

This module is a **thin wrapper**, and that is a hard constraint, not a vibe. The
whole thesis is that Drupal and n8n already do the work:

- The chat UI is `ai_chatbot`. **We ship no JavaScript.** Do not add any.
- The assistant is `ai_assistant_api`. We add no config entity of our own.
- The secret is the **Key** module's. We never hold a raw credential.
- Forms already POST to URLs — `n8n_webform` *subclasses* `RemotePostWebformHandler`
  rather than reimplementing it.
- n8n → Drupal is the **existing MCP server**. We write nothing for that direction.

Before adding a class, ask what stock mechanism you're about to duplicate. If a PR
grows the surface, it needs to justify why in the description.

### Do things the Drupal way

This is a **Drupal module**, not "a PHP project that happens to run inside Drupal."
When you can pick between a Drupal-native primitive and a generic one, pick the
Drupal one — every time:

- HTTP out → the injected `http_client` / Guzzle, not `curl`.
- Secrets → the **Key** module (`key_select`), not config, never a plain field.
- Config → config entities + `config/schema/*.yml`, with real schema.
- Settings UI → `ConfigFormBase` under `admin/config/ai`, not a bespoke route.
- CLI → drush commands, so a deployment lifecycle can bake the setup.
- Plugins → attributes (`#[AiProvider]`), not annotations.

If a Drupal-native path isn't obvious, look at how a mature module does it before
inventing. **`ai_provider_openai` is the reference for this repo** — it's the same
job, done well, in about five files. `drupal/ai`'s own `tests/modules/ai_test`
(`EchoProvider`) is the reference for the plugin shape.

### n8n owns the brain — never take it back

The agent's model, prompt, memory, tools and RAG live in n8n. Drupal owns the chat
box and the door. **A PR that adds a Drupal setting for something n8n already owns
will be rejected**, however convenient it seems. When Drupal hands us a system
prompt, we drop it. When Drupal offers history, we ignore it. See
[`features/prompt-ownership.feature`](features/prompt-ownership.feature) — that
behaviour is specified, not incidental.

The corollary: **n8n must never be selectable where a raw model is required.** The
provider supports `chat` and declines the `ChatTools` capability, and Drupal's own
capability filtering does the rest. If you find yourself writing a `hook_form_alter`
to hide n8n from somewhere, stop — you've broken the capability contract instead of
fixing it. See [`features/agent-exclusion.feature`](features/agent-exclusion.feature).

### The spec comes first

The `.feature` files are the requirements. They were written before the code, and
they drive both the README and the integration suite.

- **Changing behaviour? Change the `.feature` file in the same PR.** A behaviour
  change with no spec change is a bug report waiting to happen.
- **New feature? Write the scenarios first**, get them agreed, then implement.
- **A scenario you can't implement yet** gets tagged `@todo` and is skipped by the
  runner — documented, not deleted.
- Every feature in the README links to its spec and its code. Keep those links true.

### Validate against a real Drupal and a real n8n

CI green is necessary, not sufficient. **Every change must be tried by a human on a
real Drupal with a real n8n behind it.** Send a message; watch the n8n execution
appear. State explicitly in your PR description **what you tested, where, and how.**

The integration suite runs against an ephemeral n8n, and that's a strong net — but
it deliberately uses **LLM-free fixture workflows** (see [Testing](#testing)), so it
proves the transport, not that a real agent behaves.

### When AI writes code, validate harder

AI assistance is welcome — most of this repo was built with it — but the quality bar
does not move. If an agent wrote it:

- **Nitpick everything.** Names, signatures, defaults, error paths, the lot.
- **Read the surrounding code before trusting the diff.** Agents will happily invent
  helpers that already exist or misuse an API on the line next to the one they changed.
- **Re-derive the assertion before the test.** First-pass AI tests assert what the
  code happens to do, not what the spec says. The spec is in `features/` — check it.
- **Watch for scope creep.** An agent's instinct is to be helpful by adding options.
  This module's instinct is to have fewer. These conflict.
- **Verify external references.** Action versions, module versions, API endpoints —
  all of it. LLMs reach for stale majors. Check `gh api repos/<o>/<r>/releases/latest`.

The human submitting the PR owns the diff. "An agent wrote it" is not a defense.

---

## The flow: issue → PR → merge

Steps 1–2 are **strongly encouraged but not hard-gated** — they exist so non-trivial
work gets scoped before code is written, not to bureaucratize a typo fix. Steps 3
onward are the actual gates.

1. **Prefer opening an issue first.** Describe the problem and what "done" looks
   like. For obvious small fixes you can skip straight to a PR.
2. **Let a maintainer weigh in** before writing code on anything non-trivial. A
   short comment or a label is enough — there's no sign-off ceremony.
3. **Branch from `main`**, work, push, **open a PR** targeting `main`. Link the issue.
4. **Update [`CHANGELOG.md`](CHANGELOG.md)** under `## [Unreleased]` for any
   user-visible change. Enforced in CI by
   [`tarides/changelog-check-action`](https://github.com/tarides/changelog-check-action).
   Internal-only refactors still get a one-liner under `Changed`.
5. **CI must pass.** All required workflows green — see [What CI expects](#what-ci-expects).
   Hard gate.
6. **Get at least one approval** from a maintainer. Hard gate via branch protection.
   Address review by pushing more commits — don't force-push over a review.
7. **Squash-merge** once green and approved. The PR title becomes the commit message.
8. **Release** is a separate, manual step, not on every merge.

> **Docs-only exception.** Saga chapters, README polish and other markdown-only
> changes don't need PR ceremony — push them to `main`, or fold them into the
> nearest related PR.

---

## Anatomy of a feature change

Most features here follow one repeatable shape. Touch all five:

1. **The spec** — add or amend the scenarios in [`features/`](features/).
2. **The code** — the smallest change that satisfies them. Usually the provider
   plugin or the client.
3. **The unit/kernel test** — assert the outgoing payload with a mocked transport.
4. **The integration step** — wire the gherkin steps in `tests/integration/`.
5. **The docs** — README feature entry with its `📋 spec:` + `🛠` links, and a
   `CHANGELOG.md` line.

If a change touches the ownership split (what n8n owns vs Drupal), it also belongs
in the **saga** — that's where the "why" lives.

---

## Getting set up

You need PHP 8.3+, Composer, and Docker (for the n8n container).

```sh
# A Drupal site to develop against
composer create-project drupal/recommended-project drupal-dev
cd drupal-dev

# Point it at your working copy. The package is named drupal/n8n so composer's
# installer places it at web/modules/contrib/n8n — the directory name has to match
# the module name or Drupal will not find it.
composer config repositories.n8n path /path/to/drupal-n8n
composer require drupal/n8n:@dev drupal/ai drupal/key

# Install on SQLite — fast, disposable
vendor/bin/drush site:install --db-url=sqlite://sites/default/files/.sqlite -y
vendor/bin/drush en n8n ai_provider_n8n ai_assistant_api ai_chatbot -y
```

An n8n to talk to:

```sh
# Ephemeral n8n, no signup wizard, owner pre-provisioned
docker run --rm -p 5678:5678 \
  -e N8N_INSTANCE_OWNER_MANAGED_BY_ENV=true \
  -e N8N_INSTANCE_OWNER_EMAIL=owner@example.com \
  -e N8N_INSTANCE_OWNER_FIRST_NAME=Test \
  -e N8N_INSTANCE_OWNER_LAST_NAME=Owner \
  -e N8N_INSTANCE_OWNER_PASSWORD_HASH='<bcrypt hash>' \
  docker.n8n.io/n8nio/n8n:latest
```

The password hash must be **bcrypt, not plaintext** — n8n silently refuses to
provision the owner otherwise:

```sh
php -r 'echo password_hash("n8npassword", PASSWORD_BCRYPT), PHP_EOL;'
```

Then wire it up — every admin action has a drush equivalent, which is exactly how a
deployment bakes it:

```sh
vendor/bin/drush n8n:set-url http://localhost:5678
vendor/bin/drush n8n:set-key n8n_api_key
vendor/bin/drush n8n:test
vendor/bin/drush n8n:models
```

### The fast loop: iterate against the live cluster

`./dev.sh` pushes this working copy straight into the running Drupal pod, so you can
see whether something works in **seconds** instead of waiting on a pipeline. The pod
already has `ai`, `ai_assistant_api`, `ai_chatbot` and a live `openai` provider
installed, which is a environment CI cannot cheaply reproduce.

```sh
./dev.sh enable            # push the code + enable the modules
./dev.sh probe my.php      # run a PHP file inside the bootstrapped site
./dev.sh drush cr          # any drush command
./dev.sh remove            # uninstall + delete the copy
```

> ⚠️ **Read `dev.sh`'s header before using `enable`.** The code lands on the pod's
> **ephemeral image filesystem**, but `drush en` writes to the **database**. If the
> pod restarts, the site has the module enabled with no code on disk and will fatal.
> `./dev.sh heal` puts the code back; `./dev.sh remove` when you walk away. This is
> the one way this loop is sharper than the Nextcloud equivalent, where `custom_apps`
> is PVC-backed and survives restarts.

PHPUnit deliberately does not run in the pod — it is a production image built with
`--no-dev`, so there is no phpunit there. Use the pod to probe runtime behaviour;
use CI or a local dev site for the suite.

---

## Testing

Two layers, deliberately. The split and the rejected alternatives are argued in
[saga/Chapter_1_Packing_the_Van.md §7](saga/Chapter_1_Packing_the_Van.md).

| Layer | Tool | Where | What belongs there |
|---|---|---|---|
| **Unit + Kernel** | PHPUnit | `tests/src/` | Provider logic with a mocked transport. Guzzle `MockHandler` + `Middleware::history()` asserts the **outgoing payload** with no network — the same technique `ai_provider_openai` uses. |
| **Functional + integration** | Behat | `tests/integration/` | The `.feature` files, driven against a real Drupal + an **ephemeral real n8n**. |

Drupal's PHPUnit `Functional` / `FunctionalJavascript` tiers are **not used** —
Behat covers that layer.

```sh
# From your Drupal root — we use CORE's phpunit config, not our own. Its bootstrap
# is relative to core/ so it is always right, and its suites already glob contrib
# at ../modules/*/**/tests/src/*. --group n8n scopes it to us.
./vendor/bin/phpunit -c web/core --group n8n

composer run cs:fix             # Drupal coding standards
composer run stan               # PHPStan

cd tests/integration && composer install
vendor/bin/behat --config behat.dist.yml
```

### Fixtures are LLM-free, and must stay that way

The integration fixtures are chat-trigger workflows shaped
`Chat Trigger → Code → responseMode: lastNode`. **No AI Agent node, no LLM
credential.** This is not a shortcut — it follows from the ownership split: *we test
the transport, not the intelligence.* It also keeps CI free, deterministic and fast.

**Do not add an LLM-backed fixture** to satisfy a scenario. If a scenario can only
pass with a real model, the scenario is testing n8n rather than us — reframe it or
tag it `@todo`.

### Behat gotcha

A literal `(` or `)` in step text becomes a regex group — the step goes undefined
and **the suite fails while looking green**. Keep parentheses out of step text.

---

## What CI expects

| Workflow | Trigger | Jobs | Must pass? |
|---|---|---|---|
| `pr.yml` (🔀 PR) | PR only | Auto-assign author + changelog check | yes |
| `tests.yml` (🧪 Tests) | PR + push to `main` | PHPUnit Unit + Kernel | yes |
| `quality.yml` (🛡️ Quality) | PR + push to `main` | `composer audit` + PHPCS (Drupal standard) + PHPStan | yes |
| `integration.yml` (🔗 Integration) | PR + push to `main` | Behat against a real Drupal + ephemeral n8n | yes |
| `publish.yml` (🧬 Publish) | manual `workflow_dispatch` | release | n/a |

What the workflows look for:

- **`CHANGELOG.md` has a new entry** under `## [Unreleased]`.
- **PHPUnit green** — sticky PR comment + inline annotations via
  `EnricoMi/publish-unit-test-result-action`.
- **Behat green** — same reporter, separate check.
- **PHPCS clean** against the `Drupal` and `DrupalPractice` standards.
- **No new PHPStan findings** above the baseline. If your change touches a baselined
  line, fix it rather than re-baselining.
- **No new high-severity advisories** from `composer audit`.

### Workflow authoring conventions

- **Never put `${{ }}` inside `run:` bash.** GitHub interpolates before the shell
  runs — a script-injection hole and a mix of templating with logic. Bind it to
  `env:` and read `$VAR`:
  ```yaml
  - name: Do the thing
    env:
      VERSION: ${{ steps.bump.outputs.version }}
    run: echo "shipping $VERSION"     # not: echo "${{ steps.bump.outputs.version }}"
  ```
  `${{ }}` is fine in `with:`, `if:`, `name:`, `env:` values — just not woven into `run:`.
- **Prefer `env:` for static values too**, so each `run:` reads as its purpose.
- **Never `cd` in a `run:` block — set `working-directory:` on the step.** The step
  header should say where it runs; a `cd` buries that in the script and silently
  changes what every following line means. Use `working-directory: ${{ env.DIR }}`
  — an expression is fine there, it is only `run:` that must stay pure bash.
  The one exception is a step that *creates* the directory: `working-directory`
  cannot point at somewhere that does not exist yet, so split it — one step to
  create, the next with `working-directory` to work in it.
- **Invoke scripts with `bash path/to/x.sh`** rather than relying on the exec bit.
- **Provision first, act second.** Group setup up front (checkouts, runtimes,
  installs, service bring-up), then a readiness gate, then the work. Avoid
  "install A → use A → install B → use B".
- **Verify action versions** with `gh api repos/<owner>/<repo>/releases/latest`
  before pinning. (`duplocloud/version-bump` is first-party and floats on `@main`.)

---

## Commits, changelog, versions

- **Commits**: focused and descriptive. The squash-merge title is what lands in
  `main`'s history. Conventional Commits prefixes (`feat:`, `fix:`, `chore:`,
  `docs:`, `refactor:`, `test:`) are encouraged for the PR title.
- **Changelog**: every user-visible change adds an entry under `## [Unreleased]`,
  grouped `Added` / `Changed` / `Fixed` / `Removed` / `Deprecated` / `Security`.

  **The changelog is the release notes.** One line per entry — never a paragraph,
  nested bullet, or implementation detail. Write for someone reading "what's new,"
  not a maintainer reading git history. Length tracks user impact:

  - **Functional change** → the most detail you get, but still one line.
  - **Non-functional** (refactor, types, tests, lint) → short, often half a line.
  - **Tooling / CI not touching module code** → shortest, three or four words.
  - **`**BREAKING:**`** is the only thing that may stretch — what breaks, how to migrate.
  - The deeper why goes in the **saga** or the PR description.
  - When in doubt, write the line, then cut it in half.
  - **Only ever edit `## [Unreleased]`.** Versioned sections are **immutable** —
    those notes shipped; never reword, reorder or remove them. Convention only, so
    respect it.
- **Versioning**: SemVer. The release workflow owns it — **never bump a version in a
  feature PR.**
- **Tags**: `v<major>.<minor>.<patch>`, applied by `publish.yml` via
  `duplocloud/version-bump`.

---

## Licence

**GPL-2.0-or-later**, and this one is not a preference. Drupal is GPL-2.0-or-later,
so anything that runs inside it must be compatible — this is a hard requirement of
the platform and of drupal.org. Note this differs from our sibling
[nextcloud-n8n](https://github.com/kubed-io/nextcloud-n8n), which is AGPL because
Nextcloud is: **the host platform picks the licence, not us.**

Practical consequences:

- New PHP files carry no per-file licence header requirement here, but
  `composer.json` must keep `"license": "GPL-2.0-or-later"`.
- **Do not add a dependency under an incompatible licence** (anything GPL-incompatible
  — proprietary, or Apache-2.0-only in the GPLv2 direction). If you need a library,
  check its licence first and say so in the PR.
- `LICENSE.txt` is the verbatim GPL v2 text. Don't edit it.

---

## Security

If you've found a vulnerability, **do not open a public issue.** Follow
[SECURITY.md](SECURITY.md).

Two things worth knowing before you touch related code: the n8n API key is held by
the **Key module** and must never be logged, echoed, or copied into config; and the
admin-set n8n base URL is a deliberate, documented SSRF surface — read
[SECURITY.md](SECURITY.md) before changing how it's fetched.

---

## Where to look next

- **"What should it do?"** → [features/](features/)
- **"How does it work?"** → [README.md](README.md)
- **"Why was it built this way?"** → [saga/Chapter_1_Packing_the_Van.md](saga/Chapter_1_Packing_the_Van.md)
- **"I'm an AI agent — where do I start?"** → [AGENTS.md](AGENTS.md)

Thanks for contributing. Be kind in reviews, validate against a real n8n, and
remember that the best PR here is usually the one that deletes something.
