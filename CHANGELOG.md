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
- The installed package no longer carries the test suite, the CI config or the saga — 71% smaller.
- Tests run against the source using Drupal core's own PHPUnit config, the way drupal.org runs contrib.
- The README says plainly which parts are built and which are still specification.
- PHPStan reports through code scanning, so findings show up in the Security tab and as PR annotations.
