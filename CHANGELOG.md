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

Nothing has shipped yet. The module installs and registers itself with Drupal's AI
module, but no agent is behind it — `chat` returns a placeholder. See
`saga/Chapter_1_Packing_the_Van.md` Phase 2 for what comes next.

### Added

- An `n8n` provider for Drupal's AI module: your n8n agents will be selectable as models for an AI Assistant, and are deliberately never offered where a raw model is required.
- An n8n connection shared by every n8n submodule — base URL plus an API key held by the Key module, with a Test connection button, at Configuration → Web services → n8n.
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
- PHPStan reports through code scanning, so findings show up in the Security tab and as PR annotations.
