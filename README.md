# n8n for Drupal

Use your n8n AI agents as Drupal AI Assistants. Your agent — with its own model, its own memory, and its own tools — answers in Drupal's chat box like it lived there all along.

[![🧪 Tests](https://github.com/kubed-io/drupal-n8n/actions/workflows/tests.yml/badge.svg)](https://github.com/kubed-io/drupal-n8n/actions/workflows/tests.yml)
[![🛡️ Quality](https://github.com/kubed-io/drupal-n8n/actions/workflows/quality.yml/badge.svg)](https://github.com/kubed-io/drupal-n8n/actions/workflows/quality.yml)
[![🔗 Integration](https://github.com/kubed-io/drupal-n8n/actions/workflows/integration.yml/badge.svg)](https://github.com/kubed-io/drupal-n8n/actions/workflows/integration.yml)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](LICENSE.txt)
[![Drupal](https://img.shields.io/badge/Drupal-10--11-0678BE?logo=drupal&logoColor=white)](https://www.drupal.org)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A58.3-777bb4?logo=php&logoColor=white)](composer.json)

> **Status — this README is the specification.** It was written before the code, and
> it describes the finished product so that the code has something to be measured
> against. Today the **core loop is proven live**: the connection, model discovery,
> chatting with an n8n agent through Drupal's chat block, and the session bridge all
> work on a real cluster — see the proof-of-concept in
> [`saga/Chapter_2_The_Drive.md`](saga/Chapter_2_The_Drive.md). What remains before a
> release is hardening: error mapping, caching, tests, and the tag-sync convenience.
> Follow along in [`saga/`](saga/).

---

## How It Works

This module registers n8n as an **AI Provider** in Drupal's [AI module](https://www.drupal.org/project/ai). Every n8n workflow that starts with a **Chat Trigger** shows up as a *model* — so picking your `Support Triage` agent in Drupal is the same gesture as picking `gpt-4o`.

```
  Drupal                                              n8n
  ──────                                              ───
  Chat block  ──▶  AI Assistant  ──▶  ai_provider_n8n  ──▶  Chat Trigger
  (ai_chatbot)     (ai_assistant_api)        │                    │
                                             │                    ▼
                                             │               AI Agent ──▶ Chat Model
                                             │                    │
                                             │                    ├──▶ Postgres Chat Memory
                                             │                    │    (keyed by sessionId)
                                             │                    │
                                             │                    └──▶ MCP Client Tool
                                             │                              │
  Drupal MCP  ◀────────────────────────────────────────────────────────────┘
  (/mcp/post)      the agent calls back into Drupal: content, JSON:API, RAG, agents
```

The round trip matters. Drupal asks n8n a question; the n8n agent can turn around and ask **Drupal** for content, run a RAG search, or drive a Drupal agent — over the MCP server Drupal already exposes. Neither direction needs custom glue.

---

## The Big Idea: an n8n agent is a Drupal *assistant*

Drupal's AI ecosystem has two words that sound alike and mean very different things. Getting this straight explains every design decision below.

| | What it is | n8n equivalent |
|---|---|---|
| **AI Assistant** | What a user talks to. Holds a provider + model, a history policy, and permissions — the chat box's identity. | **An n8n AI Agent** ✅ |
| **AI Agent** | The orchestration unit behind an assistant: a system prompt plus tools, driven against a raw model. | The *inside* of an n8n agent |

> **An n8n agent is a Drupal assistant.** Its brain is never a Drupal agent's brain.

Your n8n agent already has a model, a memory, and a toolbelt. Handing it to Drupal's agent machinery as a raw model would mean two agents fighting over one conversation — so this provider makes sure that can't happen.

One mechanical note, because current versions of the AI module create a **companion agent entity** behind every assistant: with n8n as the provider that companion is deliberately **empty** — no tools, a passthrough. Your message goes through it untouched, in exactly one call, and the n8n agent does all the thinking. Don't attach Drupal tools to it; that's the one way to recreate the two-brains fight.

### Who owns what

The division is absolute, and it's what keeps this module small:

| Concern | Owner | Where you configure it |
|---|---|---|
| The model (Anthropic, OpenAI, …) | **n8n** | The Chat Model node |
| The system prompt | **n8n** | The AI Agent node |
| Conversation memory | **n8n** | The Postgres Chat Memory node |
| Tools / function calling | **n8n** | Tool nodes on the agent |
| RAG / knowledge | **n8n** | Vector store nodes (or Drupal's RAG, via MCP) |
| Which agent answers | **Drupal** | The assistant's **model** dropdown |
| Who may chat | **Drupal** | The assistant's **roles** |
| Where the chat appears | **Drupal** | Block layout |

Drupal owns the chat box and the door. n8n owns the brain. There is no third place where behaviour hides.

### Why n8n is deliberately absent from the Agent dropdown

An n8n agent is a **black box that already did its own tool-calling**. Handing it to Drupal's `ai_agents` — which expects a raw model it can drive with function calls — would mean two agents fighting over one conversation.

So this provider supports the `chat` operation and **declines the "tools" capability**. Drupal does the rest by itself: `ai_agents` asks for `chat_with_tools`, gets no n8n models, and never offers n8n as an option. Assistants ask for plain `chat` and see it immediately.

You don't configure this. It isn't a checkbox. It's what the module *is*.

---

## Features

This is a high-level showcase. Each feature links to its **executable specification** — a Gherkin `.feature` file under [`features/`](features/) that describes the exact behaviour in plain language and drives the integration tests — and to the **code** that implements it. Docs, tests, and code stay aligned: the `.feature` files *are* the requirements.

### Your n8n agents appear as models

Point Drupal at n8n, and your chat agents become selectable models, listed by name right next to `gpt-4o`. Nobody presses a sync button: the list is read **live** from n8n's REST API every time it's built. Make an agent in n8n and it's there; rename it and the dropdown follows; delete it and it's gone.

A workflow qualifies when **all three** are true — and each rules out a different way of not being able to answer:

| Requirement | Why |
|---|---|
| It starts with a **Chat Trigger** | You can't hold a conversation with a cron job |
| The workflow is **active** | n8n only serves a production webhook while a workflow is active |
| The trigger's **Make Chat Publicly Available** is on | Otherwise n8n registers no webhook at all, and it answers 404 |

That last one is the trap worth knowing: **an active workflow can still have no reachable chat webhook.** "Active" is not enough. Use `drush n8n:models --all` to see every workflow with the reason it was included or left out.

Precisely speaking, a "model" is a **chat trigger** — the trigger is the door, the workflow is the building. One workflow can carry several public chat triggers, and each becomes its own model, labelled by its door: build one agent with a `— Front Door` for customers and an `— Admin Door` for staff, and place them as two different assistants. The everyday one-trigger workflow just shows up under its own name.

A model doesn't have to contain an AI Agent node, either. This module never looks inside. A chat trigger wired to a Code node is a perfectly valid model.

Nothing about your workflows is copied into Drupal's config. The only trace is the workflow id in the assistant's **model** field — exactly where `gpt-4o` would sit.

📋 spec: [`features/model-discovery.feature`](features/model-discovery.feature) · 🛠 [`modules/ai_provider_n8n/src/Plugin/AiProvider/N8nProvider.php`](modules/ai_provider_n8n/src/Plugin/AiProvider/N8nProvider.php), [`src/N8nClient.php`](src/N8nClient.php)

### Tag it in n8n, chat with it in Drupal

Picking a model still means making an assistant by hand. If you'd rather publish an agent to your site by **tagging it**, set an **Assistant tag** in the connection settings and tag your workflows in n8n. `drush n8n:sync` then makes sure there's an assistant for each one.

Sync decides **which assistants exist** — and nothing else. It never copies behaviour in either direction: your roles, greeting and error messages are never pushed to n8n, and n8n's prompt, model, memory and tools are never pulled into Drupal. Same ownership split as everywhere else, applied to lifecycle.

| What you do | What happens |
|---|---|
| Tag a workflow | An assistant appears, wired to it |
| Rename it in n8n | The assistant's label follows — matched on workflow id, so it's never duplicated |
| **Remove the tag** | The assistant is **disabled, not deleted** — its chat box stops rendering, and everything you configured survives |
| Re-add the tag | It's enabled again, settings intact |
| Delete the assistant in Drupal | The workflow is **untagged** in n8n so it stays gone. The workflow itself is untouched |

Two rules keep this predictable:

- **Sync only manages assistants it created.** They're stamped with the workflow they came from. An assistant you built by hand is never disabled, deleted, or overwritten by sync — even if it points at the same workflow.
- **Sync never overwrites what Drupal owns.** Change the roles or the greeting on a synced assistant and the next sync leaves them alone.

📋 spec: [`features/assistant-sync.feature`](features/assistant-sync.feature)

### Chat with an n8n agent

Create an AI Assistant, set its **AI Provider** to `n8n`, pick one of your agents as the **model**, and place the chat block. That's the whole setup. A visitor's message goes to your agent's Chat Trigger; the agent's answer comes back in the chat box.

Everything the agent does in between — which model it calls, which tools it fires, what it remembers — is invisible to Drupal, and deliberately so.

📋 spec: [`features/assistant-chat.feature`](features/assistant-chat.feature) · 🛠 [`modules/ai_provider_n8n/src/Plugin/AiProvider/N8nProvider.php`](modules/ai_provider_n8n/src/Plugin/AiProvider/N8nProvider.php)

### The agent remembers

Each Drupal conversation maps to one n8n **session**. The module derives a stable session id from the assistant and the current user and sends it with every message, so your agent's **Postgres Chat Memory** node threads the conversation exactly as it would in n8n's own chat window.

Two consequences worth understanding:

- **The memory lives in n8n, not Drupal.** Drupal stores no transcript. Clearing Drupal's caches doesn't forget your conversation; clearing the n8n memory table does.
- **The same user talking to the same assistant continues the same conversation** across page loads. Different users get different sessions. Different assistants — even against the same agent — get different sessions.

Only the newest message is sent. Drupal never replays history, because n8n already has it: replaying would make the agent see every message twice.

📋 spec: [`features/session-memory.feature`](features/session-memory.feature) · 🛠 [`modules/ai_provider_n8n/src/Plugin/AiProvider/N8nProvider.php`](modules/ai_provider_n8n/src/Plugin/AiProvider/N8nProvider.php)

### n8n owns the prompt

Your agent's system prompt lives in n8n, on the AI Agent node. Drupal's assistant has prompt fields of its own — **this module ignores them**, because your agent already has instructions and two sets would fight.

This is the single most surprising thing about the module, so it is stated plainly here and in the UI: see [Settings that intentionally do nothing](#settings-that-intentionally-do-nothing).

📋 spec: [`features/prompt-ownership.feature`](features/prompt-ownership.feature)

### One agent, many personas *(planned)*

Because several assistants can point at the same model, one n8n agent can serve many faces of your site — and the **metadata** each message carries is what tells them apart. Every chat POST already includes metadata; the plan is to let each assistant contribute its own (its name, its instructions, custom key/values), so a *generic* agent flow in n8n can read `metadata.instructions` and adapt: one workflow, a formal persona on the support page, a playful one on the blog.

This is also where the assistant form's inert fields earn their keep, optionally: fill in **Instructions** and it travels as metadata for your workflow to use — or leave it empty and the workflow's own prompt stands alone. Nothing is forwarded unless you switch it on.

> **Status:** the metadata channel is proven end-to-end; the per-assistant configuration is designed but not yet specified. This paragraph is the idea's front door, per [how a feature becomes a feature](features/README.md#how-a-feature-becomes-a-feature).

### Not an agent brain (on purpose)

n8n appears wherever an **assistant** picks a provider. It appears **nowhere** an **agent** does — not in `ai_agents`, not in AI-powered field automators, not in the CKEditor AI plugins. Those surfaces need a raw model with function calling; an n8n agent isn't one.

If you need Drupal-side agents, keep a real LLM provider (OpenAI, Anthropic, …) configured alongside. The two coexist happily — and your n8n agent can *call* those Drupal agents through MCP, which is the right direction for that trick anyway.

📋 spec: [`features/agent-exclusion.feature`](features/agent-exclusion.feature)

### Failures surface, they don't hang

If n8n is unreachable, the key is wrong, or a workflow isn't active, the chat box shows the assistant's configured error message rather than spinning. Requests are timeout-capped. Errors land in Drupal's log with the n8n status code, so "the bot is broken" is a five-second diagnosis.

An **inactive workflow** is the most common one: n8n only serves a production chat webhook while the workflow is active.

📋 spec: [`features/connection-failure.feature`](features/connection-failure.feature)

### Drupal answers back

Nothing in this repo implements the n8n → Drupal direction, because Drupal already ships it. Enable the [MCP module](https://www.drupal.org/project/mcp), then add an **MCP Client Tool** node to your agent pointing at `https://your-site/mcp/post`. Your agent can now read and write content, query JSON:API, run a RAG search over your site, and invoke Drupal's own AI agents — while it's answering in Drupal's chat box.

That's the loop closing: Drupal asks n8n, n8n asks Drupal.

---

## The Chat Experience

The frontend is Drupal's own — this module ships **no JavaScript**. The chat window is the [`ai_chatbot`](https://www.drupal.org/project/ai) block, which comes with the AI module.

### Placing the chat

`ai_chatbot` provides an **AI Chatbot** block. Place it in a region (Block layout → place block), choose the assistant it should talk to, and set the usual block visibility conditions — pages, content types, roles.

Because it's a normal Drupal block, everything you already know applies: put it site-wide in a footer region, restrict it to `/support/*`, show a different agent to editors than to anonymous visitors. Multiple chat blocks pointing at different n8n agents on different sections of the site is a supported, boring configuration.

### Who can chat

Access is the assistant's, not the module's. Each AI Assistant declares which **roles** may use it, and the block respects Drupal's normal block visibility on top. To expose an agent to anonymous visitors, grant the anonymous role on the assistant *and* leave the block unrestricted.

Sessions are per-user, so two visitors never see each other's conversation. Anonymous visitors share a session per browser session.

### What visitors see

A chat launcher, a message list, and an input box — styled by your theme. Visitors see the assistant's label and its configured greeting; they never see which n8n workflow answers, its model, or its tools. To them it is simply your site's assistant.

---

## Administration

### n8n Connection

Configure at **Configuration → AI → n8n** (`/admin/config/ai/n8n`). Shared by every submodule in this repo.

| Setting | Description |
|---|---|
| **n8n URL** | Base URL of your n8n instance, e.g. `https://n8n.example.com`. No trailing slash. |
| **API Key** | Your n8n API key, selected from the [Key](https://www.drupal.org/project/key) module. Sent as `X-N8N-API-KEY`. Stored by Key, never echoed back. Used to list workflows — *not* to post chat messages. |
| **Assistant tag** | Optional. The n8n tag that marks a workflow as meant for this site. Leave it empty and every qualifying workflow is offered as a model. Set it and the list narrows to tagged workflows — and `drush n8n:sync` can keep an assistant per tag. |
| **Test connection** | Calls `GET /api/v1/workflows?limit=1` and reports what it finds. Verifies the URL and the key in one click. |

The API key is managed by the **Key** module rather than by this module, so it can live in a file, an environment variable, or a secrets manager — whatever your site already uses. This module never handles a raw secret of its own.

---

### Provider and models

Configure at **Configuration → AI → Settings** (`/admin/config/ai/settings`), the AI module's own page.

| Setting | Description |
|---|---|
| **Default provider for `chat`** | Set this to `n8n` only if you want *every* chat operation to hit n8n. Most sites leave the default on a real LLM and select `n8n` per assistant instead. |

The n8n provider registers for the **`chat`** operation and no other. It advertises **no** capabilities — no tools, no vision, no JSON mode, no streaming — which is what keeps it out of the surfaces that need a raw model.

---

### The Assistant

Configure at **Configuration → AI → AI Assistants** (`/admin/config/ai/ai-assistant`).

| Setting | Description |
|---|---|
| **AI Provider** | Choose `n8n`. |
| **Model** | Choose one of your chat-trigger workflows. This is the agent that answers. |
| **Allow history** | Set to **one thread per session**. This gives the module a stable session id to hand n8n, so your agent's memory threads correctly. |
| **Roles** | Who may use this assistant. |
| **Assistant message / Error messages** | Shown by the chat UI. These are Drupal's, and they work normally. |

---

### Settings that intentionally do nothing

The AI Assistant form is shared across all providers, so it shows fields that are meaningless when n8n is the provider. They are **inert** — not broken, not "coming soon". n8n already owns what they configure:

| Field | Why it's ignored | Configure instead |
|---|---|---|
| **Instructions** | They feed the companion agent's system prompt — which this provider drops, because your agent has its own. | The AI Agent node in n8n |
| **History context length** | Only the newest message is sent; n8n holds the history. | The Chat Memory node in n8n |
| **Agents to use / RAG** | The n8n agent does its own tool calling. Attaching Drupal tools here is the one misconfiguration that makes two brains fight — leave them off. | Tool nodes in n8n |
| **LLM Configuration** (temperature, etc.) | The model lives in n8n. | The Chat Model node in n8n |

> **Rule of thumb:** if a setting describes *how the agent thinks*, it belongs in n8n. If it describes *who can talk to it and where*, it belongs in Drupal.

---

## Drush Commands

Every admin action is available over `drush`, so the whole connection can be automated from a container lifecycle hook or a CI job — the same operations as the settings form. All commands exit `0` on success and non-zero on error.

### Configure the connection

```sh
# Point Drupal at your n8n instance
drush n8n:set-url https://n8n.example.com

# Point the module at a Key entity holding your n8n API key
drush n8n:set-key n8n_api_key

# Verify it all works — the headless "Test connection" button
drush n8n:test
```

### Inspect what Drupal can see

```sh
# List the workflows that qualify as models (chat trigger + active + public)
drush n8n:models

# List every workflow n8n returns, with the reason each was included or filtered out
drush n8n:models --all
```

### Publish tagged agents as assistants

```sh
# Set the tag that marks a workflow as meant for this site
drush n8n:set-tag drupal

# Make sure there is an assistant for every tagged workflow.
# Creates what is missing, re-enables what came back, disables what lost its tag.
drush n8n:sync

# Preview without writing anything
drush n8n:sync --dry-run

# Make one assistant from one workflow, tag or no tag
drush n8n:assistant <workflow-id>
```

### Smoke-test an agent

```sh
# Send one message to a workflow and print the reply — no assistant, no block, no browser
drush n8n:chat <workflow-id> "what can you do?"

# Reuse a session id to prove memory threads
drush n8n:chat <workflow-id> "and what did I just ask?" --session=my-test-session
```

---

## Modules in this repo

| Module | What it does | Depends on |
|---|---|---|
| **`n8n`** | The connection: URL, API key, assistant tag, REST client, Test connection, drush commands. Ships no features of its own. | `key` |
| **`ai_provider_n8n`** | n8n agents as Drupal AI Assistants. The headline. | `n8n`, `ai` |

Enable only what you need; everything shares the one connection.

---

## Requirements

| | Version | Notes |
|---|---|---|
| **Drupal** | 10.3+ / 11 | |
| **PHP** | 8.3+ | |
| **[AI](https://www.drupal.org/project/ai)** | ^1.4 | Required by `ai_provider_n8n`. |
| **[AI Agents](https://www.drupal.org/project/ai_agents)** | ^1.1 | The AI module requires it to create assistants — every assistant is agent-backed now. |
| **[Key](https://www.drupal.org/project/key)** | ^1.0 | Stores the n8n API key. |
| **n8n** | 1.100+ / 2.x | Needs at least one **active** workflow whose Chat Trigger is **publicly available**. |

For the chat UI, enable `ai_chatbot` and `ai_assistant_api` — both ship **inside** the AI module, so there's nothing extra to download.

---

## Not this module

A few things people reasonably expect that this module does **not** do, with what to use instead:

| Expectation | Reality |
|---|---|
| Embed n8n's own chat widget | That's [`n8n_chat`](https://www.drupal.org/project/n8n_chat) — a JS widget that talks to n8n from the browser and bypasses Drupal AI. Different tool for a different job. |
| Use an n8n agent for CKEditor AI, field automators, or Drupal agents | Not supported by design — those need a raw LLM. Keep a real provider configured alongside. |
| Let a Drupal assistant call n8n workflows as **tools** | That's MCP's job, not ours: give the workflow an [MCP Server Trigger](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-langchain.mcptrigger/) and point the [MCP Client](https://www.drupal.org/project/mcp_client) module at it. |
| Stream tokens as they generate | Answers arrive complete. Drupal's assistant pipeline does not stream agent-backed assistants — a structural upstream limit, not a missing toggle here. |
| Approval buttons / human-in-the-loop mid-run | n8n's Chat node only supports that on its **hosted** chat, not embedded clients like Drupal. Have the agent ask in plain text instead — its memory keeps the thread. |
| Report token usage or cost | n8n runs the model; Drupal never sees the accounting. |
| Trigger n8n from Drupal events — node saved, form submitted, user created… | Use [ECA](https://www.drupal.org/project/eca), or Webform's own Remote Post handler pointed at a webhook. Both already do this well. |

---

## References

- [AI (Artificial Intelligence)](https://www.drupal.org/project/ai) · [Develop an AI Provider](https://project.pages.drupalcode.org/ai/1.1.x/developers/writing_an_ai_provider/)
- [Key](https://www.drupal.org/project/key) · [Webform](https://www.drupal.org/project/webform) · [MCP](https://www.drupal.org/project/mcp)
- [n8n Chat Trigger](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-langchain.chattrigger/) · [n8n Form Trigger](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.formtrigger/) · [n8n API](https://docs.n8n.io/api/)
- [Model Context Protocol](https://modelcontextprotocol.io/)
- Sibling project: [nextcloud-n8n](https://github.com/kubed-io/nextcloud-n8n) — the same ownership split, applied to files.
