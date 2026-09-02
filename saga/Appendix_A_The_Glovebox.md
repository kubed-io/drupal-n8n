# Appendix A — The Glovebox

> The little details you don't want in the cabin but would hate to be without on
> a hard shoulder at two in the morning.
>
> This is the salvage from `features/old/` — ten `.feature` files, 692 lines,
> deleted on **2026-09-02**. The files went because they were the wrong shape
> (see [Why the folder went](#why-the-folder-went)); the *contracts written in
> their comment headers* were mostly right, hard-won, and in several cases proven
> live against a real n8n. Those are here, one section per concern, phrased as
> rules rather than as scenarios.
>
> **This is a ledger, not a spec.** Nothing here is executable and nothing here
> is authoritative over the code. When a rule below is re-expressed as a scenario
> in the new `features/`, the scenario wins and the entry here becomes history.

---

## Why the folder went

The nextcloud siblings (`nextcloud-n8n`, `nextcloud-penpot`, `nextcloud-grafana`)
have **gestures** — rename, move, copy, delete, restore. Each is a real action
with an observable before and after on both sides, so each earns a feature file.

This module has exactly **one** gesture in the whole product: *someone types a
message and an answer comes back.*

Everything else the README calls a feature — the site tag, model discovery, the
signature, agents metadata, user context, page context, instructions — is not a
behaviour. It is a **dimension of the assistant's configuration** that changes
what rides along with that one gesture. `features/old/` gave each of those its
own file, and inside each file gave each metadata key its own scenario. So
`user-context.feature` was "the `user` key is present when the switch is on" — a
serialisation assertion wearing a feature's clothes.

**The rule that replaced it:** the assistant definition is the `Given`, as a
table. One visitor message is the `When`. The `Then` shows that definition
arriving at n8n as the envelope. Combinations of the definition are `Examples`
rows, never scenarios. Two files — `connection.feature` and `assistant.feature`
— are the whole suite.

Nothing in `old/` was ported. It was read, mined for this appendix, and deleted.

---

## 1. The connection — the module's "I'm logged in" gate

*from `admin-connection.feature`*

- The connection is the **prerequisite to every other behaviour**. Base URL plus
  an API key, then a test.
- The API key is held by the **Key module** (the drupal.org `key` project, a hard
  dependency), so the secret can live in a file, an env var, or a secrets
  manager. Drupal holds only the **key entity's name** — this module never
  handles a raw secret of its own.
- **Obtaining** an n8n API key is out of scope; that is the n8n admin's job. In
  the suite it is minted against the ephemeral n8n and provided as setup.
- Two failures the test must report: an **invalid API key** and an **unreachable
  host**.
- **Security boundary:** the raw key belongs to the Key module and must never
  come back out through our form. Cross-referenced in `SECURITY.md`. This was
  `@todo` in the old suite — it needs a *rendered page*, which the harness could
  not serve.
- The whole connection must be **bakeable by a deployment lifecycle** with no
  human clicking a form: `drush n8n:set-url`, `n8n:set-key`, `n8n:test`. Zero
  exit on success; **a non-zero exit is the thing that lets an install script
  fail loudly.**

---

## 2. What a model *is* — the single most load-bearing definition in the module

*from `model-discovery.feature`*

**A model is a CHAT TRIGGER, not strictly a workflow.** The trigger is the door,
the workflow is the building. One workflow may carry several public chat
triggers, each its own webhook and its own session space, and each becomes its
own model labelled by its door (`Two Doors — Front Door`, `Two Doors — Admin
Door`). Proven live. The everyday single-door case keeps the plain workflow id,
so nothing gets uglier for it.

**Three gates, and each rules out a different way of not being able to answer:**

| Gate | What it rules out |
|---|---|
| It starts with a **chat trigger** | you cannot hold a conversation with a cron job |
| The workflow is **active** | n8n serves a production webhook only while a workflow is active |
| The trigger's **Make Chat Publicly Available** is on | otherwise no webhook is registered at all, and it answers 404 |

The third is **the trap, proven live: active is not enough.** An active workflow
can still have no reachable chat webhook. `drush n8n:models --all` exists to show
every workflow with the reason it was included or filtered out.

**A model does not have to contain an AI Agent node.** We never look inside. A
chat trigger wired to a Code node is a perfectly valid model.

**The site tag** is how Drupal knows which n8n agents belong to this site — one
tag per site, mirroring the sibling `nextcloud-n8n`'s one-tag-per-folder, except
here it is one tag per **site**. Empty means every qualifying workflow is
offered, which is the friendly default for a fresh install.

**n8n stays the source of truth.** The list is read **live** from n8n's REST API
every time it is built — nobody presses a sync button. n8n owns the name: rename
a workflow and the dropdown follows, and the old name is gone. And **nothing
about the workflows is written to Drupal's configuration** — no workflow id
appears in config; the only trace is the id in the assistant's `model` field,
exactly where `gpt-4o` would sit.

**Multisite comes for free** from the Domain module: because the client reads the
tag through the **config factory**, a per-domain override of the tag gives each
subsite its own set of agents, with the default site falling through to the
global tag. Two consequences worth keeping:

- The default site behaves **identically whether or not Domain is installed** —
  so a test that never sets up a domain is a faithful test of the no-Domain case.
- **The CLI never negotiates a domain.** A test that wants a domain-specific tag
  must activate it through **`domain.negotiation_context`** — the service that
  actually gates config overrides.

**We do not generate assistants.** Turning a model into a chat box is the admin's
choice — one model can back several assistants with different roles and metadata
— so it is deliberately a human design decision, not something the module
automates. Automating it would guess wrong.

---

## 3. Assistant, never agent — the rule the framework enforces for us

*from `agent-exclusion.feature`*

An n8n agent is a **black box that already did its own tool calling**. Handing it
to `ai_agents` — which expects a raw model it can drive with function calls —
would mean **two agents fighting over one conversation**. So n8n must appear
wherever an **assistant** picks a provider, and **nowhere an agent does**.

**The inventory of provider-selection surfaces — verified live, 2026-07-16.**
Every one of them resolves through the same two service calls, which is why a
handful of assertions covered all of them:

- `/admin/config/ai/settings` lists ~17 operation types. n8n appears on exactly
  **one** row: plain **Chat**. We implement no other operation interface, so
  speech-to-text, embeddings and the rest never offer us.
- The same page's **capability** rows — Chat with Tools, Complex JSON, Structured
  Response, Image Vision — are plain chat *filtered by capability*. n8n is absent
  from all four, because we declare **no capabilities**.
- The **assistant form's** provider dropdown asks for plain chat: n8n present.
- Anything asking the default-provider service for a **capability-filtered** chat
  (`ai_agents`, CKEditor AI, the field automators) gets the same filter: absent.

**We do not implement this with form alters or hooks.** The provider supports the
`chat` operation and declines the tools capability; **Drupal's own capability
filtering does the rest**, because `ai_agents` asks for the `chat_with_tools`
pseudo-operation, which resolves to chat filtered by capability. It isn't a
checkbox. It's what the module *is*.

**Setting n8n as the site-wide default chat provider is possible and legal** —
but then every plain-chat consumer on the site talks to one workflow, which is
why the README steers people to select n8n **per assistant** instead.

**The companion agent nuance.** Since the AI module made every assistant
agent-backed, Drupal creates a companion agent entity per assistant. That is
scaffolding, not a contradiction: the companion is an **empty passthrough**, and
the rule here is about the **provider surface** — n8n is never offered where
something needs a raw model it can drive with tools.

**Why this was worth a test at all:** to prove the framework keeps its side of
the bargain. If a future Drupal release changes capability filtering, this is the
assertion that goes red — and it should, loudly.

`@todo` in the old suite: **the two provider kinds coexisting** — a real LLM for
the site's Drupal-side agents *and* n8n for its assistants, at the same time,
without interfering. It needed a second provider module in the harness.

---

## 4. The session bridge — and who owns the transcript

*from `session-memory.feature`*

**Where the session id comes from.** Drupal's assistant runner mints a thread key
and tags every provider call `ai_agents_thread_<key>`. We **strip the prefix**
and send the key to n8n as `sessionId`. A memory node whose Session ID is *"from
the connected chat trigger"* then keys on it automatically.

**This is exactly what `@n8n/chat` does.** n8n's own embed widget generates a
`sessionId` and sends it with every message. The only difference is where the id
is kept: `@n8n/chat` uses the browser's `localStorage`, Drupal keeps its thread
key in a server-side session store tied to the visitor's session cookie. Both
mean **one session per browser**, and both let n8n's memory node do the
remembering. *We are the `@n8n/chat` widget, sourced from Drupal.*

**The memory lives in n8n, not Drupal.** Drupal replays **no** history — it sends
the newest message plus the session id. Replaying would make the agent see every
message twice, because n8n already has it. What Drupal *can* send is a hint: the
assistant's **History context length** rides in `metadata.context_window`, so a
memory node can size its Context Window Length from Drupal's setting. A length of
**zero forwards nothing**, and the memory node keeps its own default.

**Who owns the shown transcript — a per-assistant choice.** `Allow history`
decides one narrow thing: who owns the transcript **the chat box repaints on
reopen**. It is not the agent's recall; that always comes from the memory node
wired to the **agent**.

| Mode | Owner of the shown transcript | Needs an n8n memory node? |
|---|---|---|
| **Session** / **Session (same thread on reload)** | Drupal — the visitor's session store | No, not for the box |
| **Session (from n8n memory)** | n8n — loaded live from the agent's memory | **Yes**, a *retrieving* one on the agent |

- On the two **Session** modes, Drupal keeps its own copy, and a memory node
  wired **only to the chat trigger** (for n8n's own chat UI) is simply
  **ignored** — the box repaints correctly even with no memory node at all.
- **Session (from n8n memory)** flips ownership: Drupal stores no transcript, and
  on open asks the workflow to hand back the conversation for this session, so
  Drupal and n8n show **one** transcript instead of two loosely in sync. **This
  is the only place Drupal drives n8n's `loadPreviousSession`**, and it works
  **only** against a **retrieving** memory on the agent (Postgres Chat Memory, or
  a workflow that answers the call by hand). Against Simple Memory or no memory
  node, n8n returns nothing and **the box opens empty**.

**Load Previous Session, when it is ours.** The chat trigger's *Load Previous
Session* option (From Memory / Manually) is how n8n rehydrates history. On the
two Session modes Drupal never calls it, so the setting does not affect us. On
n8n-memory mode Drupal posts `loadPreviousSession` with the session id and paints
the box from n8n's `{data:[…]}` reply. Threading of the agent's own recall still
comes from the memory node on the **agent**, independent of this display choice.

**Sizing applies on the load side too**, exactly as it bounds a Drupal-stored
transcript: a history length of **1** keeps **3** messages; the **default length
of 2** keeps the **last 5** messages of a six-message transcript.

**The regression worth never losing.** `getMessageHistory` feeds the provider as
well as the box, so in n8n-memory mode it **has to end with the new message** —
otherwise the workflow is asked nothing and **never runs at all**. Sourcing the
transcript from n8n must not swallow the live question.

**Deliberately not tested.** Whether the same browser keeps the same session
across page loads is Drupal's server-side session behaviour — the equivalent of
`@n8n/chat`'s `localStorage`, and not reproducible in a headless suite. It is
**documented, not asserted**. Sessions are per-user, so two visitors never see
each other's conversation (cross-referenced in `SECURITY.md`); anonymous visitors
share a session per browser session.

---

## 5. The signature — the envelope on every Drupal-originated message

*from `drupal-signature.feature`*

**The contract.** The conversation carries exactly one thing: the visitor's
newest message. **Everything else Drupal knows rides in metadata.**

The **always-on envelope** is `source` (always `drupal`, which is how a workflow
tells Drupal traffic from n8n's own chat), the **site name**, and the
**assistant's id**. Beside the machine id rides the assistant's **display name**,
so a workflow can greet or log by the name an admin actually gave it.

**The rule that governs every optional key, throughout:** metadata is something
Drupal **offers**, never an order. A key is **absent** when there is nothing to
say. n8n owns the brain; Drupal offers context. A workflow that ignores the
metadata behaves exactly as it does in n8n's own chat window.

The rest of the signature was one optional key per old feature file —
`instructions`, `context_window`, `agents`, `user` / `user_roles` /
`allowed_roles`, `path` / `entity` — which is precisely the fan-out that got the
folder deleted. The contracts for each are in the sections below.

---

## 6. Instructions — one agent, many personas

*from `assistant-instructions.feature`*

- An assistant is an **overrideable implementation** of a model's chat trigger.
  Several assistants can back one model, each with its own instructions — which
  is exactly why turning a model into a persona is a real design choice and not
  something the module automates.
- Filled in, the Instructions travel as `metadata.instructions` — **clean admin
  text**, offered as a variable for a generic n8n agent to fold into its prompt.
  Empty, the key is **absent**, and a zero-detail assistant is a **pure
  passthrough** where n8n owns the whole agent.
- **Clean, not the runtime prompt.** `instructions` is the admin's *stored*
  Instructions text, **never** the agent loop's per-turn runtime framing (*"This
  is the first time this agent has been run."*). That framing is noise and must
  not leak.
- This absorbed the old **prompt-ownership** concern: *"Drupal's prompt never
  reaches the conversation"* and *"the prompt travels as metadata"* are two sides
  of one contract.

---

## 7. Agents passthrough — the assistant says which Drupal agents n8n may call

*from `agents-metadata.feature`*

- Drupal's **MCP server** (`drupal/mcp`) already exposes each Drupal agent as a
  tool. The AI Assistant form already has an **Agents to use** section. This
  joins the two, and nothing more.
- Whatever is ticked travels on every message as `metadata.agents` — an array of
  the **exact MCP tool ids n8n sees**: `aif_<agent_id>`, the AI Function Calling
  exposure, which mirrors the tool Drupal itself would call natively. A workflow
  drops the array straight into an **MCP Client Tool** node's *Tools to Include*
  as `={{ $json.metadata.agents }}`, **with no transformation.**
- **Drupal never runs them.** The selection is not executed on the Drupal side;
  the n8n agent is a passthrough and does its own tool calling over MCP. The
  checkboxes are only a clean way to hand n8n the list — so **ticking agents must
  not turn the one-call passthrough into two.** That was the load-bearing
  assertion of the whole file.
- **Source of the ids.** The selection is stored on the assistant's **companion
  agent** as tools keyed `ai_agents::ai_agent::<agent_id>`; the provider reads
  those, keeps the agent entries, and emits `aif_<agent_id>`. An empty selection
  means the key is absent, and the workflow keeps whatever tools it already had.
- This is the mirror of *Drupal answers back*: there, the agent **can** reach into
  Drupal; here, the assistant says **which parts** it may reach.

---

## 8. Page context — where the visitor is

*from `page-context.feature`*

- `metadata.path` carries the page the chat box is sitting on — `/about`,
  `/blog/how-we-work`, `/user/1`.
- `metadata.entity` carries that page's `{type, id}` **when the page *is* a
  single piece of content**. On a listing, a view, the front page, or an admin
  route — where no single entity owns the page — **`entity` is absent**.
- **Where the fact comes from, and why it is not like the others.** Unlike the
  visitor's identity, the page is **not known to the provider directly** — when
  `chat()` runs it is handling the chat POST, not the page. The path arrives
  through **Drupal's chat context**, the bundle the chat block already sends,
  handed over by **`AiAssistantPassContextToAgentEvent`** just before the message
  goes out. `entity` is then **derived server-side from that path**.
- **Today the chat-context bundle carries the page path and nothing else.** The
  old spec covered `path` and its derived `entity` and **invented nothing**. If
  Drupal grows the context upstream, new keys earn their place then — not before.
- Outstanding at the time of deletion: **verify the caching caveat** in Chapter 2
  §1.6.2.

---

## 9. User context — who is asking, behind one opt-in switch

*from `user-context.feature`*

- One switch, **Forward visitor identity to n8n**, turns the whole block on. It
  is **off by default**, because it is all personal or access data: a username is
  personal data, and sending it should be a choice.
- With it on, **three keys arrive together**:

  | Key | Carries |
  |---|---|
  | `user` | the visitor's username |
  | `user_roles` | the visitor's Drupal roles — **always a list**, because a Drupal user holds several at once |
  | `allowed_roles` | the roles the assistant is limited to |

- **`allowed_roles` is always a list under the switch** — the assistant's roles
  when it restricts, or an **empty list** when it is open to everyone — so a
  workflow can read it without ever checking whether the key is there.
- **It is context, never a gate.** Drupal has *already* enforced who may use the
  assistant before the message left, so a workflow can log it or branch on it,
  but it changes nothing on the n8n side.
- With the switch off, **none of it travels** and the agent behaves exactly as it
  does in n8n's own chat.
- `@todo` in the old suite: the **precise-identity** scenario — it needs the
  harness to drive the runner *as* a chosen visitor (account switching plus role
  creation). The logic is proven by `N8nUserContextTest`.

---

## 10. The round trip and its failure edges

*from `assistant-chat.feature` — the whole file was `@todo`; see
[the fixtures that were never built](#11-the-fixture-workflows-and-what-each-one-proves)*

**The headline use case, and the whole product in one line:** a visitor types in
Drupal's chat box and the answer comes from an n8n agent, without their knowing
or caring that n8n produced it.

**One message is exactly one provider call and one n8n execution.** Drupal's
assistant pipeline runs an agent loop that **can** call the provider more than
once; with the zero-tools passthrough this module sets up, it does not — proven
live in the POC (Chapter 2 §1.1a). This is **the canary**: if a Drupal AI upgrade
changes the loop, it goes red **before anyone's LLM bill doubles.** Keep it.

**A JSON-shaped answer must arrive unmangled**, as text. An n8n agent may
legitimately answer with something JSON-shaped. The old JSON-decoder hazard died
with the legacy assistant path; this pinned the promise that replaced it.

**The headless proving tool.** `drush n8n:chat <workflow-id> "…"` diagnoses the
whole path with **no browser, no assistant and no block**, and `--session=` reuses
a session id to prove memory threads.

**The failure rule, one line:** *a visitor never sees a spinner that never
resolves, and an admin never reads code to find out why.* Every failure surfaces
as **the assistant's own configured error message** — Drupal's normal mechanism,
not a bespoke one — while the real cause goes to the log with the n8n status code,
so "the bot is broken" is a five-second diagnosis.

Four causes, all honouring that same contract:

| Cause | Note |
|---|---|
| The workflow was **deactivated** after the assistant was configured | **the most common one in practice** — n8n serves a production chat webhook only while a workflow is active, so an agent that worked yesterday stops the moment someone toggles it off. Switching off the trigger's *publicly available* produces the same 404 and must surface the same way — one scenario covers both |
| The agent **errors inside n8n** | the failure is recorded in the log |
| The agent **takes too long** | requests are timeout-capped; the visitor is not left waiting indefinitely |
| n8n is **unreachable** | the failure is recorded in the log |

These edges were their own `connection-failure` feature once. They were folded in
because they are not a separate product — **they are the ways this one round trip
fails.**

---

## 11. The fixture workflows, and what each one proves

Live in `tests/workflows/`, imported into the ephemeral n8n by the integration
harness. Naming them here because the *point* of each one is not obvious from its
JSON, and the new feature files must not mention them by name — a fixture is
mechanism, and mechanism belongs in the step definitions.

| Fixture | What it exists to prove |
|---|---|
| `echo-agent` | **hands back everything it received.** The workhorse: it is how the suite asserted both what we sent *and what we deliberately did not send*. Every metadata absence assertion in the old suite ran through it |
| `canned-agent` | a fixed reply — the happy-path round trip, and the `drush n8n:chat` smoke test |
| `history-agent` | answers `loadPreviousSession` **by hand** with a known transcript — a stand-in for a retrieving memory like Postgres Chat Memory, proving the load side |
| `inactive-agent` | not active, so no webhook is served |
| `private-agent` | chat trigger is **not** publicly available → 404 |
| `webhook-only` | no chat trigger at all |
| `two-doors` | two public chat triggers in one workflow → **two** models |
| `rename-me` | renamed in n8n, to prove n8n owns the name |
| `shop-bot` | tagged for a second domain's tag — the multisite case |

**Three fixtures were referenced but never built:** `JSON Agent`, `Failing Agent`
and `Slow Agent`. That is why `assistant-chat.feature` carried a file-level
`@todo` — **the headline round trip and every one of its failure edges were
specified and never actually covered.** If the failure contract in §10 matters,
these three fixtures are the price of it.

---

## 12. The `@todo` ledger — what was blocked, and on what

Each of these was a real behaviour with a real reason it could not be asserted.
The reasons are harness limitations, not design doubts.

| Behaviour | Blocked on |
|---|---|
| The whole chat round trip and its failure edges | the three missing fixtures above |
| The API key is never echoed back to the browser | a **rendered page**, which the harness cannot serve |
| A conventional LLM provider stays available for agents | a **second provider module** in the harness |
| The visitor's precise name and roles travel | harness **account switching + role creation**; logic proven by `N8nUserContextTest` |

---

## 13. Where the spec links point now

The deleted files were referenced from **33 lines** by their pre-`old/` paths
(`features/<name>.feature`), so those links were **already broken** before the
deletion — they broke when the files were moved into `old/`. All 33 were
repointed in the same PR that deleted them, so the baseline is clean:

| Where | Lines |
|---|---|
| `README.md` — the `📋 spec:` footers | 11 |
| `AGENTS.md` | 4 |
| `CONTRIBUTING.md` | 2 |
| `SECURITY.md` | 2 |
| Source `@see` docblocks | 8 |
| Test `Spec:` headers | 5 |
| `tests/integration/bin/preload-n8n.sh` | 1 |

Two kinds of link now, because they promise different things:

- **spec** — a live `.feature` file. `connection.feature` runs in CI;
  `assistant.feature` is written and its step definitions are being built.
- **contracts** — a section of this appendix, where a behaviour's rules are
  recorded but no scenario covers it.

| Old target | Now points at |
|---|---|
| `admin-connection.feature` | `features/connection.feature` |
| `agent-exclusion.feature` | `features/connection.feature` — its last two `Then`s — plus §3 for the full surface inventory |
| `drupal-signature.feature` | `features/assistant.feature`, whose `Then` table *is* the envelope |
| `assistant-instructions.feature` | `features/assistant.feature`, the `instructions` row |
| `agents-metadata.feature` | `features/assistant.feature`, the `agents` row, plus §7 |
| `user-context.feature` | `features/assistant.feature`, the `user` rows, plus §9 |
| `page-context.feature` | `features/assistant.feature`, the `path`/`entity` rows, plus §8 |
| `assistant-chat.feature` | `features/assistant.feature` for the round trip; §10 for the failure edges |
| `session-memory.feature` | §4 — nothing in the new specs reaches memory yet |
| `model-discovery.feature` | §2 — nothing in the new specs reaches discovery yet |
| `features/README.md` (never existed) | §11, which records what each fixture proves |

**Two of those links were overclaiming, and the repointing fixed it** — worth
knowing, because both were in `SECURITY.md`:

- *"The settings form does not render the raw key back to the browser — guarded
  by `admin-connection.feature`."* That scenario existed but was tagged `@todo`,
  so it never ran. It now says the behaviour is **not currently covered by a
  test** and must be reviewed by hand, with the block recorded in §§1 and 12.
- *"Two different users must never share a session id … both are specified in
  `session-memory.feature`."* Those two scenarios **never existed**, in that file
  or anywhere else. The claim now points at §4 and says plainly that neither is
  asserted today: the separation follows from how the id is *derived* — the
  runner's thread key is already per user and per assistant — and the scenarios
  that covered the session bridge at all went with the rewrite.

A dangling link is cheap to fix. A link that says a security property is tested
when it is not is the expensive kind, and it hid behind the dangling one.

**Left as history, deliberately.** Seven references remain, and all of them are
dated records rather than live pointers: the immutable `[0.1.1]` and earlier
CHANGELOG sections, and the saga's own narrative — Chapter 1 §673, Chapter 2
§§137 and 1661 on the old `features/README.md`, and the 2026-07-19 work order at
Chapter 2 §§1806, 1817 and 1837. Rewriting a dated log to match today would
falsify it. Chapter 2 §9 records the rewrite instead.
