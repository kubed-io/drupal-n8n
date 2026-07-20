# n8n for Drupal

Use your n8n AI agents as Drupal AI Assistants. Your agent — with its own model, its own memory, and its own tools — answers in Drupal's chat box like it lived there all along.

[![🧪 Tests](https://github.com/kubed-io/drupal-n8n/actions/workflows/tests.yml/badge.svg)](https://github.com/kubed-io/drupal-n8n/actions/workflows/tests.yml)
[![🛡️ Quality](https://github.com/kubed-io/drupal-n8n/actions/workflows/quality.yml/badge.svg)](https://github.com/kubed-io/drupal-n8n/actions/workflows/quality.yml)
[![🔗 Integration](https://github.com/kubed-io/drupal-n8n/actions/workflows/integration.yml/badge.svg)](https://github.com/kubed-io/drupal-n8n/actions/workflows/integration.yml)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](LICENSE.txt)
[![Drupal](https://img.shields.io/badge/Drupal-10--11-0678BE?logo=drupal&logoColor=white)](https://www.drupal.org)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A58.3-777bb4?logo=php&logoColor=white)](composer.json)

> **Status — this README is the specification.** It was written before the code, and
> it describes the finished product so the code has something to be measured against.
> Today the **fundamental base is built and proven**: the connection, the site tag,
> model discovery, chatting with an n8n agent through Drupal's chat block, the session
> bridge, and the Drupal signature all work on a real cluster and are covered by a
> live integration suite that runs against a real ephemeral n8n. What remains before a
> `1.0` release is hardening — error mapping and discovery caching — and whatever the
> next stops on the road turn up. The whole story, with receipts, is in
> [`saga/Chapter_2_The_Drive.md`](saga/Chapter_2_The_Drive.md).

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

### Tag your agents for the site

Drupal needs to know which of your n8n agents belong to this site — you may have dozens of chat workflows and want only some of them here. That's the **site tag**: one n8n workflow tag, set once in the connection settings. Tag a workflow `mysite` in n8n, put `mysite` in the setting, and its chat agents are this site's models. Leave the setting empty and every qualifying workflow is offered — the friendly default for a fresh install.

It's the same idea as the sibling [nextcloud-n8n](https://github.com/kubed-io/nextcloud-n8n), where one tag maps to one folder. Here **one tag maps to one site.**

### Your n8n agents appear as models

With the tag set, your chat agents become selectable models, listed by name right next to `gpt-4o`. Nobody presses a sync button: the list is read **live** from n8n's REST API every time it's built. Tag an agent in n8n and it's there; rename it and the dropdown follows; untag or delete it and it's gone.

A workflow's trigger qualifies when **all three** are true — and each rules out a different way of not being able to answer:

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

### Different agents per site (multisite)

If you run several sites off one Drupal with the [Domain](https://www.drupal.org/project/domain) module, each subsite can have its **own** tag — a customer-facing site sees the `shop` agents, the intranet sees the `staff` ones. You set a domain-specific override of the site tag, and because the module reads the tag through Drupal's config system, the right agents appear per domain with no extra wiring. The default site just uses the global tag, and behaves identically whether or not Domain is installed.

### Make an assistant — this module does not do it for you

Turning a model into a chat box is **your** call, not something this module automates, because it's a real design decision: one agent can back several assistants with different audiences and instructions (see [One agent, many personas](#one-agent-many-personas)). Automating it would guess wrong.

The setup is short:

1. **Configuration → AI → AI Assistants → Add assistant.**
2. Set **AI Provider** to `n8n`.
3. Pick your agent as the **Model** — the list is your tagged chat triggers.
4. Choose an **Allow history** mode — who owns the transcript, Drupal or n8n (see [Where the conversation is remembered](#where-the-conversation-is-remembered)) — then pick the **roles** who may chat and write the greeting and error messages.
5. Place the **AI Chatbot** block (Block layout) and point it at this assistant.

A visitor's message now goes to your agent's Chat Trigger and the answer comes back in the chat box. Everything the agent does in between — which model it calls, which tools it fires, what it remembers — is invisible to Drupal, deliberately.

📋 spec: [`features/assistant-chat.feature`](features/assistant-chat.feature) · 🛠 [`modules/ai_provider_n8n/src/Plugin/AiProvider/N8nProvider.php`](modules/ai_provider_n8n/src/Plugin/AiProvider/N8nProvider.php)

### The agent remembers

Each Drupal conversation maps to one n8n **session**. The module takes the assistant runner's thread key and sends it with every message as `sessionId`, so a memory node whose **Session ID** is *"from the connected Chat Trigger node"* threads the conversation automatically.

**This is exactly what n8n's own embed widget, [`@n8n/chat`](https://www.npmjs.com/package/@n8n/chat), does** — it generates a `sessionId` and sends it with each message. The only difference is where the id is kept: `@n8n/chat` stores it in the browser's `localStorage`; Drupal keeps its thread key in a server-side session store tied to the visitor's session cookie. Both mean *one session per browser*, and both let n8n's memory node do the remembering. You're getting the `@n8n/chat` experience, sourced from Drupal.

Wire your workflow like the n8n docs recommend: connect **one memory node to both the Agent and the Chat Trigger**. The Agent connection is what threads the conversation; the Chat Trigger connection is only for n8n's own chat UI, which Drupal doesn't use.

Two things always hold:

- **The same browser continues the same conversation** across page loads. Different browsers get different sessions; different assistants get different sessions.
- **Only the newest message is sent.** Drupal never replays history to the agent — n8n already has it, and replaying would make the agent count every message twice.

And you can size the memory from Drupal: set **History context length** on the assistant and it rides along as `metadata.context_window`, so your memory node's **Context Window Length** can be `={{ $json.metadata.context_window }}`.

📋 spec: [`features/session-memory.feature`](features/session-memory.feature) · 🛠 [`modules/ai_provider_n8n/src/Plugin/AiProvider/N8nProvider.php`](modules/ai_provider_n8n/src/Plugin/AiProvider/N8nProvider.php)

### Where the conversation is remembered

The agent always remembers through its own memory node — that's the recall it uses to answer. **Allow history** decides one narrower thing: who owns the **transcript the chat box shows** when a visitor reopens it. There are two owners, and it's your call per assistant.

| Allow history | Who owns the shown transcript | Needs an n8n memory node? |
|---|---|---|
| **Session** / **Session (same thread on reload)** | **Drupal** — stored in the visitor's session store | No, not for the chat box |
| **Session (from n8n memory)** | **n8n** — loaded live from the agent's memory | **Yes** — a *retrieving* memory on the Agent |

**Drupal owns it (the two Session modes).** Drupal keeps its own copy of every question and answer in the visitor's session and paints the box from that. This is self-contained: the box repaints correctly even if the workflow has no memory node at all. A memory node wired only to the **Chat Trigger** for n8n's own chat UI is simply **ignored** by Drupal and isn't needed — Drupal drives the webhook directly and never asks n8n to reload a session. (Put a memory node on the **Agent** if you want the agent to *recall* across turns — that's the agent's brain, a separate concern from what the box displays.)

**n8n owns it (Session from n8n memory).** Drupal stores no transcript of its own. When the box opens it asks the workflow to hand back the conversation for this `sessionId`, and paints the box from n8n's answer — so Drupal and n8n show **one** transcript instead of two kept loosely in sync. The catch is a hard requirement: it only works against a **retrieving memory** wired to the Agent — Postgres Chat Memory, or a memory that answers n8n's *Load Previous Session* request. With **Simple Memory** or **no memory node**, n8n returns nothing and the box opens empty. Choose this when a retrieving memory is the single source of truth and you don't want Drupal keeping a parallel copy.

> **Rule of thumb.** Want it to *just work* with any workflow? Use **Session**. Have a Postgres (or otherwise retrieving) memory and want n8n to be the one transcript? Use **Session (from n8n memory)** and make sure that memory is on the Agent.

📋 spec: [`features/session-memory.feature`](features/session-memory.feature) · 🛠 [`modules/ai_provider_n8n/src/N8nAssistantRunner.php`](modules/ai_provider_n8n/src/N8nAssistantRunner.php)

### Every message carries the Drupal signature

The conversation your agent sees carries exactly one thing: the visitor's newest message. Everything else Drupal knows rides along as **metadata** — the Drupal signature:

| Key | Carries |
|---|---|
| `source` | always `drupal` — how a workflow tells Drupal traffic from n8n's own chat |
| `site` | the site's name |
| `assistant` | which assistant is calling, so one agent serving several can tell them apart |
| `instructions` | the assistant's own Instructions field, clean — **absent entirely** when the assistant has none, so a zero-detail assistant is a pure passthrough. Offered as a variable, never injected into the conversation |
| `context_window` | the assistant's **History context length**, so a memory node can size its Context Window Length from Drupal — absent when zero or unset |
| `agents` | the Drupal **agents** this assistant may use, as MCP tool ids — ready to drop into an MCP Client Tool's *Tools to Include* (see [Lend your agent Drupal's own agents](#lend-your-agent-drupals-own-agents)) |
| `user` | the current visitor's username — **opt-in** |
| `user_roles` | the visitor's Drupal roles, as a **list** — **opt-in** |
| `allowed_roles` | the roles this assistant permits; Drupal has already enforced access before the message left, so this is context, not a gate |
| `path` | the page the chat box is on, e.g. `/about` or `/user/1` (see [Know what page they're on](#know-what-page-theyre-on)) |
| `entity` | that page's content as `{type, id}` when the page *is* a single node, term, or user — absent on listings and views |

**Everything in the signature is optional context, never an order.** Your agent's own system prompt lives in n8n and always wins; a workflow that ignores the metadata behaves exactly as it does in n8n's own chat window. But a workflow that *reads* it gets the module's distinctive trick: a live picture of who is asking, where they are, and what your site can do — and one generic agent becomes a different, site-aware persona per assistant.

Everything below this line is that same signature, feature by feature.

📋 envelope spec: [`features/drupal-signature.feature`](features/drupal-signature.feature) · 🛠 [`modules/ai_provider_n8n/src/Plugin/AiProvider/N8nProvider.php`](modules/ai_provider_n8n/src/Plugin/AiProvider/N8nProvider.php)

### Lend your agent Drupal's own agents

Drupal ships its own AI **agents** — for content, taxonomy, fields, and any you build — and the [MCP module](https://www.drupal.org/project/mcp) already exposes them as tools. This module lets you decide, **per assistant**, which of them your n8n agent may reach for: the assistant form's **Agents to use** checkboxes. Whatever you tick rides along as `metadata.agents`, already shaped as the exact MCP tool ids your site exposes (`aif_<agent>`) — so an **MCP Client Tool** node pointed at your site needs no glue. Set its *Tools to Include* to an expression:

```
={{ $json.metadata.agents }}
```

Now the **assistant** decides which Drupal agents its workflow may use, right on the assistant form, and two assistants on the same workflow can grant different ones. Nothing runs inside Drupal — your n8n agent does the calling, over MCP; the checkboxes just hand it the list. Tick none and the key is absent, and the workflow keeps whatever tools it already had.

This is the mirror of [Drupal answers back](#drupal-answers-back): there, your agent *can* reach into Drupal; here, the assistant says *which parts* it may reach.

📋 spec: [`features/agents-metadata.feature`](features/agents-metadata.feature)

### Know who's asking

Switch on user context and every message carries the visitor's `user` name and `user_roles` — a **list**, because a Drupal user holds several roles at once. Your agent can greet an editor differently from an anonymous visitor, or decline a request politely when the roles don't fit. It's **opt-in**: a username is personal data, and forwarding it to n8n should be a choice, not a default.

Beside them travels `allowed_roles` — the roles the assistant itself permits. Drupal has *already* enforced that gate before the message left, so this one is purely informational: log it, branch on it, or ignore it.

📋 spec: [`features/user-context.feature`](features/user-context.feature)

### Know what page they're on

The chat box knows which page it's sitting on — and now your agent does too. `metadata.path` carries that page's path (`/about`, `/blog/how-we-work`, `/user/1`), and when the page *is* a single piece of content, `metadata.entity` carries its `{type, id}`. So a visitor reading a blog post can ask "summarise this," and your agent — using the Drupal agents above — can fetch that exact node over MCP. On a listing or a view, where there's no single entity, `entity` is simply absent.

These facts come from Drupal's **chat context** — a small bundle the chat block already sends with every message. Today that bundle carries the **page path and nothing else**; Drupal may grow it over time, and as it does, more page context can ride the signature. For now, `path` is the one to count on, with `entity` derived from it.

📋 spec: [`features/page-context.feature`](features/page-context.feature)

### One agent, many personas

That signature is what makes assistants **overrideable implementations** of one agent. Nothing on the assistant form has to be filled in, and its name doesn't have to match the workflow's — but each assistant that points at the same model sends its own signature. A formal persona on the support page, a playful one on the blog: same workflow, two assistants, `metadata.instructions` doing the differentiating. Or ignore all of it and run one assistant per agent — both are first-class.

📋 spec: [`features/assistant-instructions.feature`](features/assistant-instructions.feature) · 🛠 [`modules/ai_provider_n8n/src/Plugin/AiProvider/N8nProvider.php`](modules/ai_provider_n8n/src/Plugin/AiProvider/N8nProvider.php)

### Start from the Drupal Assistant template

You don't have to build the n8n side from scratch. This repo ships a ready-to-import workflow, [`workflow.json`](workflow.json) — the **Drupal Assistant** — a generic n8n agent wired to read the *entire* signature: an OpenAI chat model, Postgres chat memory sized by `metadata.context_window`, a system prompt that folds in `metadata.instructions` and the visitor and page context, and a Drupal **MCP Client Tool** whose *Tools to Include* is `={{ $json.metadata.agents }}`. Import it, add your credentials and your site's MCP URL, tag it, and it answers as a Drupal-aware assistant driven entirely by what Drupal tells it.

It's a **starting point, not a cage.** Swap the model, change the memory, add your own tools, rewrite the prompt — the only part worth keeping is how it consumes the metadata. Everything else is yours to bend to your own requirements.

And it's **entirely optional.** The metadata is an offer, not a requirement: point an assistant at *any* n8n agent that ignores the signature and it still works — a zero-detail assistant is a plain passthrough. The template shows the ceiling; the passthrough is the floor; the integration you actually want is somewhere between, and the signature is how you dial it in.

### Not an agent brain (on purpose)

n8n appears wherever an **assistant** picks a provider. It appears **nowhere** an **agent** does — not in `ai_agents`, not in AI-powered field automators, not in the CKEditor AI plugins. Those surfaces need a raw model with function calling; an n8n agent isn't one.

If you need Drupal-side agents, keep a real LLM provider (OpenAI, Anthropic, …) configured alongside. The two coexist happily — and your n8n agent can *call* those Drupal agents through MCP, which is the right direction for that trick anyway.

📋 spec: [`features/agent-exclusion.feature`](features/agent-exclusion.feature)

### Failures surface, they don't hang

If n8n is unreachable, the key is wrong, or a workflow isn't active, the chat box shows the assistant's configured error message rather than spinning. Requests are timeout-capped. Errors land in Drupal's log with the n8n status code, so "the bot is broken" is a five-second diagnosis.

An **inactive workflow** is the most common one: n8n only serves a production chat webhook while the workflow is active.

📋 spec: [`features/assistant-chat.feature`](features/assistant-chat.feature) — the failure edges of the round trip

### Drupal answers back

Nothing in this repo implements the n8n → Drupal direction, because Drupal already ships it. Enable the [MCP module](https://www.drupal.org/project/mcp), then add an **MCP Client Tool** node to your agent pointing at `https://your-site/mcp/post`. Your agent can now read and write content, query JSON:API, run a RAG search over your site, and invoke Drupal's own AI agents — while it's answering in Drupal's chat box.

That's the loop closing: Drupal asks n8n, n8n asks Drupal. And you can steer it **per assistant** — the [Agents to use](#lend-your-agent-drupals-own-agents) selection travels as `metadata.agents`, so the assistant decides which of Drupal's agents its workflow may call.

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
| **Site tag** | The n8n workflow tag that marks an agent as belonging to this site — one tag per site. Only tagged workflows contribute models. Leave it empty and every qualifying workflow is offered, which is the friendly default for a fresh install. With the [Domain](https://www.drupal.org/project/domain) module, each subsite can override this with its own tag. |
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

The AI Assistant form is shared across all providers, so it shows a field that n8n, not Drupal, controls. It's **inert** under n8n — not broken, not "coming soon". n8n already owns what it configures:

| Field | Why it's ignored | Configure instead |
|---|---|---|
| **LLM Configuration** (temperature, etc.) | The model lives in n8n. | The Chat Model node in n8n |

Several fields that *look* inert are not — they never touch the conversation, but they ride along as metadata for your workflow to read if it wants (see [the Drupal signature](#every-message-carries-the-drupal-signature)):

| Field | Rides as | What a workflow can do with it |
|---|---|---|
| **Instructions** | `metadata.instructions` | extend a generic agent into a persona |
| **History context length** | `metadata.context_window` | size an n8n memory node's window |
| **Agents to use** | `metadata.agents` | list the Drupal agents the n8n agent may call over MCP |

Fill them in and they're forwarded; leave them empty and the key is simply absent. **None of them makes Drupal *do* anything** — which is exactly why "Agents to use" is safe here: Drupal never runs those agents, it just hands the list to n8n, and your n8n agent does the calling.

> **Rule of thumb:** if a setting describes *how the agent thinks*, it belongs in n8n. If it describes *who can talk to it, where, and what site context to carry along*, Drupal offers it as metadata — and n8n decides whether to read it.

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

### Set the site tag

```sh
# Only workflows carrying this n8n tag are offered as models for this site
drush n8n:set-tag mysite
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
