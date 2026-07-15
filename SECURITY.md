# Security Policy

This module sits between a Drupal chat box that may face anonymous visitors and an
n8n agent that may hold credentials and write to your site. That's a security-relevant
position, and this document is specific about it rather than generic.

---

## Supported versions

| Version | Supported |
|---|---|
| `main` (pre-release) | ✅ — the only supported code today |
| tagged releases | none yet |

**This module is not covered by Drupal's security advisory policy.** That coverage
requires a project on drupal.org with a stable release and an opted-in maintainer; we
have neither yet. Until then, treat this as you would any unreviewed contrib: read
the code, pin a commit, and don't run it somewhere it can hurt you. If that changes,
this table changes with it.

---

## Reporting a vulnerability

**Do not open a public issue.**

Use GitHub's private reporting: **Security → Report a vulnerability** on this repo.
That opens a private advisory only maintainers can see.

Please include:

- what the issue is, and the impact you think it has;
- steps to reproduce, ideally a minimal case;
- the versions involved — this module's commit, Drupal core, `drupal/ai`, and n8n;
- whether it's exploitable by an **anonymous visitor**, an **authenticated user**, or
  only an **admin**. That distinction drives our severity more than anything else.

If you're unsure whether something counts, report it anyway and let us decide.

---

## What to expect

| Stage | Target |
|---|---|
| Acknowledgement | 3 working days |
| Initial assessment | 7 working days |
| Fix or mitigation plan | depends on severity; we'll tell you the plan |
| Credit | offered by default — tell us if you'd rather not be named |

This is a small project maintained by volunteers. We'd rather promise
honestly than promise fast.

---

## Scope

**In scope** — anything in this repo:

- The provider plugin and how it builds requests to n8n.
- The connection settings and how the API key is handled.
- The drush commands.
- The webform handler.
- Our CI workflows.

**Out of scope** — report these upstream:

- **`drupal/ai`, `ai_assistant_api`, `ai_chatbot`** — the assistant pipeline and the
  chat UI are theirs. If the bug is in how the chat block renders, it's upstream.
- **n8n itself**, and **whatever your agent does.** If your agent has a tool that
  deletes your database and someone talks it into doing so, that's your agent's
  design, not this module — see [Prompt injection](#prompt-injection-and-agent-authority).
- **Drupal core**, the **Key** module, **Webform**.
- Anything requiring an already-compromised admin account.

---

## Secrets policy

The n8n API key is the only secret this module touches, and **it deliberately never
belongs to us**:

- **The Key module owns it.** We store a *reference* to a Key entity, never the
  secret. That's why it can live in a file, an env var, or a secrets manager.
- **Never logged, never echoed.** The settings form does not render the raw key back
  to the browser — guarded by
  [`features/admin-connection.feature`](features/admin-connection.feature)
  ("The API key is never echoed back to the browser"). Error paths log the n8n status
  code, never the request headers.
- **`drush n8n:set-key` takes a Key entity name, not a raw secret** — so a key never
  lands in shell history or a process list.
- **CI masks it.** The integration suite mints a throwaway key against an ephemeral
  n8n and masks it (`::add-mask::`). It is never stored, never committed, and dies
  with the container.

If you find a path where the key is logged, echoed, or persisted outside the Key
module, that's a vulnerability — please report it.

---

## Network egress and local addresses (deliberate)

**This module will fetch a URL an administrator gives it, including private and
link-local addresses. That is intended, and it is a documented trade-off.**

The normal deployment has Drupal and n8n in the same cluster, so the base URL is
something like `http://n8n.internal:5678` or `http://localhost:5678`.
Refusing private ranges would break the primary use case.

The consequence, stated plainly: an administrator who can set the n8n base URL can
make the site issue requests to internal hosts — a classic SSRF shape, including
cloud metadata endpoints like `169.254.169.254`.

We accept this because:

- Setting the base URL requires **admin permission**. An actor who has that can
  already do far worse in Drupal.
- The response is not rendered back to the attacker verbatim; it's parsed as an n8n
  API payload.

We are **not** relying on that second point for safety. If you find a way to make
this module fetch an attacker-chosen URL **without** admin permission — via a
webform handler, a drush command reachable another way, or a config import path —
**that is a real vulnerability**, and the "deliberate" framing above does not apply.
Report it.

---

## Prompt injection and agent authority

This is the risk most specific to this module, and it deserves more than a footnote.

The chain is: **a visitor's text → your n8n agent → your agent's tools.** If you have
followed the README and connected your agent back to Drupal via the MCP server, those
tools may include *writing content, reading private data, or invoking Drupal agents.*

**An n8n agent will do what it is talked into doing.** That is what LLM agents are.
This module faithfully carries a visitor's message to it — which means:

> **If you place a chat block backed by a write-capable agent in front of anonymous
> visitors, you have given anonymous visitors that agent's authority.**

This module cannot fix that, and does not try. What it gives you:

- **Assistant roles** — each AI Assistant declares which roles may use it. Use them.
- **Block visibility** — the chat block is a normal Drupal block.
- **The ownership split** — the agent's tools are configured in n8n, deliberately, by
  you. There is no way for this module to widen them.

Practical guidance:

- **Start read-only.** An anonymous-facing agent should have read-only tools.
- **Scope the MCP credential.** The Drupal MCP token your agent holds should have the
  narrowest permissions that work — not admin.
- **Separate the agents.** Use one agent for the public chat and a different, more
  capable one for an editors-only assistant.
- **Assume every message is hostile.** Because eventually one is.

A prompt injection that makes *your* agent misbehave is not a bug in this module. A
bug in this module that lets a visitor reach an agent they shouldn't be able to
reach — bypassing assistant roles or block visibility — **is**, and we want to hear
about it.

---

## Session isolation

Each conversation maps to an n8n session id derived from the assistant and the
current user, and n8n's memory node threads on it. That makes session isolation a
**privacy boundary this module is responsible for**:

- Two different users must never share a session id.
- Two different assistants must never share a session id, even against one agent.

Both are specified in
[`features/session-memory.feature`](features/session-memory.feature) ("Two visitors
do not share a conversation", "Two assistants against one agent are separate
conversations").

> ⚠️ **Known open question — anonymous visitors.** Drupal derives its thread id from
> the current user's id, and every anonymous visitor has user id `0`. If that id
> reaches n8n unmodified, **all anonymous visitors would share one memory session**
> and could read each other's conversation. Whether that happens depends on a code
> path we have not yet proven (`AiAssistantApiRunner::shouldStoreSession()` may
> assign a unique key first). **This must be resolved before any anonymous-facing
> deployment**, and it's tracked as a Phase 2 exit criterion in
> [saga/Chapter_1_Packing_the_Van.md](saga/Chapter_1_Packing_the_Van.md). If it does
> collapse to a shared session, the fix is to derive anonymous sessions from the PHP
> session rather than the user id.

If you find any other way to make one conversation leak into another, report it.

---

## Security-related CI gates

| Gate | What it does |
|---|---|
| `composer audit` | Fails on known advisories in dependencies. |
| PHPStan | Static analysis; findings above baseline fail. |
| PHPCS (`Drupal`, `DrupalPractice`) | Catches unsafe patterns the Drupal standard encodes. |
| Dependabot | Keeps actions and dependencies current. |
| `::add-mask::` on the minted n8n key | The integration key never appears in logs. |

Green CI is not a security guarantee. It's a floor.

---

## Disclosure timeline

We follow coordinated disclosure:

1. You report privately.
2. We acknowledge, assess, and agree a timeline with you.
3. We fix, release, and publish an advisory crediting you.
4. **90 days** is our default maximum before public disclosure, whether or not a fix
   has shipped. If you need it public sooner, say so and we'll work it out.

If this module is ever covered by Drupal's security advisory policy, the Drupal
Security Team's process supersedes this document.
