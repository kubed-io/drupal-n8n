# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

<!--
  These ARE the release notes. One line per entry, written for a user — never a
  paragraph. Length tracks impact: functional changes get the most words (still
  one line); refactors/types/tests stay short; CI/devops are shortest. Only
  **BREAKING:** may stretch. Deeper detail lives in the saga or the PR, not here.

  ONLY EVER EDIT THE [Unreleased] SECTION. Every section below it carries a
  version number and is IMMUTABLE — those notes shipped with a release and must
  never be reworded, reordered, or removed. Add new work under [Unreleased].
  See CONTRIBUTING.md / AGENTS.md.
-->

## [Unreleased]

### Added

- The Drupal signature carries the assistant's selected **Agents to use** as `metadata.agents` (MCP tool ids like `aif_<agent>`), ready to drop into an n8n MCP Client Tool's Tools to Include — so the assistant decides which of Drupal's own agents its n8n workflow may call back over MCP.
- The Drupal signature carries the assistant's display name as `metadata.assistant_name` (its Drupal label) beside the machine id in `metadata.assistant`, so a workflow can greet or log by the name an admin gave the assistant.
- Opt-in visitor context: `metadata.user` and `metadata.user_roles` (a list), plus the assistant's own `metadata.allowed_roles`, so a workflow can tailor its answer to who is asking.
- Page context: `metadata.path` — and `metadata.entity` (`{type, id}`) when the page is a single node, term, or user — so an agent can look up the very content the visitor is viewing.
- A shareable **Drupal Assistant** template (`workflow.json`): a generic n8n agent — OpenAI chat model, Postgres memory, Drupal MCP tool — wired to read the whole signature, as a starting point you bend to your own needs.
- A third Allow history mode, **Session (from n8n memory)**: instead of Drupal keeping its own transcript, the chat box is rehydrated live from the n8n agent's memory, so Drupal and n8n show one conversation — requires a retrieving memory node (e.g. Postgres Chat Memory) on the agent.
- Session sizing from Drupal: an assistant's History context length rides along as `metadata.context_window`, so an n8n memory node can set its Context Window Length from Drupal — like `@n8n/chat`, sourced from Drupal's session instead of the browser's localStorage.

### Changed

- The agents and page-context specs are wired and running end to end: the Echo Agent proves the selected agents arrive as `aif_` ids without a second provider call, and that a content page forwards its path and entity while a listing forwards only the path.
- The session-memory spec is wired and running: the thread key becomes n8n's session id, only the newest message travels, and the history length reaches n8n — proven end to end. The README explains the `@n8n/chat` parallel and that the chat trigger's Load Previous Session setting is n8n's own concern, not Drupal's.
- Instructions and History context length are documented as forwarded context, not inert settings.
- Refactor: the signature's five entity reads share one guarded loader — DRY, no behaviour change.

## [0.1.1] - 2026-07-18

### Added

- Chat with an n8n agent through Drupal's chat block: `chat()` posts the newest message to the workflow's chat webhook with a stable session id, so the agent's own memory threads the conversation.
- Model discovery: every active workflow whose chat trigger is publicly available appears as a model, one model per trigger — a workflow with two public chat triggers is two models, labelled by door.
- Site tag: one n8n workflow tag per site scopes which agents become models, set from the settings form or `drush n8n:set-tag`, and read through the config factory so the Domain module gives each subsite its own tag for free. Empty tag offers every qualifying workflow.
- The Drupal signature: every message carries `metadata.{source, site, assistant, instructions}` — the conversation stays clean while a workflow can identify Drupal traffic and read per-assistant context, so one generic agent can serve many assistants.
- The integration suite runs for real: fixture workflows preloaded through n8n's own API, live Behat coverage for the connection, model discovery incl. the site tag and two-door workflows, the provider surfaces, the signature, and a multisite scenario driving a real per-domain tag override.
- The signature carries the assistant's clean instructions — a zero-detail assistant forwards none, an extended assistant forwards exactly its Instructions field, both proven by driving a real assistant end to end.

### Fixed

- `metadata.instructions` no longer leaks the agent loop's per-turn runtime framing; it now carries the assistant's own instructions, read from the agent entity, so it is stable and clean.

### Changed

- **BREAKING (spec only):** the module targets agent-backed assistants exclusively — the AI module's current architecture; no legacy-path support, and no streaming, which that pipeline cannot deliver.
- The README, features and agent docs now reflect the proven core loop, the trigger-is-the-door model, and the README-first spec flow; the parked webform feature moved out of the spec.

### Removed

- `features/webform-submit.feature` — parked; Webform's stock Remote Post handler plus ECA already cover the use case.
- The assistant-sync idea (auto-generating assistants from tagged workflows) — turning a model into an assistant is the admin's design choice, not something this module should automate.

## [0.1.0] - 2026-07-15

### Fixed

- `drush n8n:test` no longer fails with a TypeError while reporting a connection that actually worked — which broke unattended installs on 0.0.2.

### Changed

- The drush commands are unit tested at last: CI had no drush, so nothing under `Drush/` could be covered.
- `dev.sh` now updates the copy Drupal loads; on a site pinning a release, pushing to `modules/custom` changed nothing.

## [0.0.2] - 2026-07-15

Nothing has shipped yet. The connection is real — install it, set a URL and a key,
and Test connection works — but no agent is behind it: `chat` returns a
placeholder. Chatting, model discovery and the session bridge are specified in
`features/` and arrive in Chapter 2. See `saga/Chapter_1_Packing_the_Van.md`.

### Added

- An `n8n` provider for Drupal's AI module: your n8n agents will be selectable as models for an AI Assistant, and are deliberately never offered where a raw model is required.
- An n8n connection shared by every n8n submodule — base URL plus an API key held by the Key module, with a Test connection button, at Configuration → Web services → n8n.
- `drush n8n:set-url`, `n8n:set-key` and `n8n:test`, so a deployment can bake and verify the connection with nobody at a form.
- The executable specification: 27 Gherkin scenarios across eight `.feature` files, plus LLM-free fixture workflows the tests own.
- A README describing the product as users will meet it — the assistant-vs-agent model, who owns what, the chat block, admin settings, and the drush commands.
- Contribution rules: `CONTRIBUTING.md`, `AGENTS.md`, `SECURITY.md`, and GPL-2.0-or-later licensing.
- The design record: `saga/Chapter_1_Packing_the_Van.md`.
- CI: PHPUnit on PHP 8.3 and 8.4, Drupal coding standards, PHPStan, and a Behat suite against an ephemeral n8n.
- `dev.sh` — push the working copy into the live Drupal pod and probe it without a commit.

### Changed

- CI now targets PHP 8.5, the version the production pod runs.
- Dependabot PRs are assigned and skip the changelog gate.
- Dependabot can now resolve the Drupal dependencies.
- A Copilot setup workflow, so the coding agent gets a working Drupal to reason against.
- Saga: domain-aware configuration is now in scope, and how domain overrides actually behave is recorded.
- CI steps declare where they run instead of hiding it in a `cd`.
- Docs each own one thing and link to whoever owns the rest, section by section.
- Messages shown to admins are now translatable, and n8n's failures have their own log channel.
- Docs and examples no longer carry anyone's personal details or private workflow names.
- The installed package no longer carries the test suite, the CI config or the saga — 71% smaller.
- Tests run against the source using Drupal core's own PHPUnit config, the way drupal.org runs contrib.
- The README says plainly which parts are built and which are still specification.
- PHPStan reports through code scanning, so findings show up in the Security tab and as PR annotations.
