# The specification

These `.feature` files **are the requirements**: they drive the integration suite,
and every feature in the [README](../README.md) links back to one of them.

## How a feature becomes a feature

The order matters, and it is **README first**:

1. **It starts as prose in the README** — the highest level, the least detail. An
   idea is cheapest to scrutinize, argue about, or kill while it is still a
   paragraph.
2. **Due diligence before it gets a `.feature` file.** Read the actual code, probe
   the live systems, search for prior art. A spec written before verifying the
   thing is possible is a guess wearing a suit — this repo has killed more than one
   "obvious" feature at this step (streaming; the Execute Workflow trigger).
3. **Then the base case** — the ideal flow, specified plainly.
4. **Then the few most likely edges, strategically.** A handful of scenarios that
   each pin a real decision beat exhaustive coverage. Do not enumerate every case
   you can imagine: the refactor loop at the end of implementation reliably
   uncovers the edges nobody could have guessed, and speculative scenarios written
   now are the ones that turn out wrong.
5. **Changing behaviour later? Change the `.feature` in the same PR.**

## Fixtures — the tests own their workflows

Scenarios **never** reference a real workflow from anyone's n8n. Every workflow named
below is a **fixture this repo owns**, created in an ephemeral n8n through n8n's own
public API before the suite runs (`tests/integration/bin/preload-n8n.sh`).

Every fixture is **LLM-free** — `Chat Trigger → Code → responseMode: lastNode`. No AI
Agent node, no model credential. That is not a shortcut: this module's job is the
**transport**, not the intelligence (see [saga §1](../saga/Chapter_1_Packing_the_Van.md)).
It also keeps CI free, fast and deterministic.

| Fixture | Shape | Exists to prove |
|---|---|---|
| **Echo Agent** | returns the `chatInput` and `sessionId` it received | what we actually sent n8n |
| **Canned Agent** | always returns `the answer is 42` | the answer reaches the visitor |
| **JSON Agent** | returns a JSON object as its answer | that a JSON-shaped answer survives intact |
| **Slow Agent** | waits longer than the request timeout | the timeout path |
| **Failing Agent** | throws during execution | the error path |
| **Webhook Only** | a webhook trigger, no chat trigger | the model filter excludes it |
| **Inactive Agent** | a chat trigger, left inactive | the active filter excludes it |
| **Private Agent** | an active chat trigger, **not** publicly available | the public filter excludes it — active is not enough |
| **Two Doors** | ONE workflow, TWO public chat triggers — "Front Door" and "Admin Door" — into one Code node | each public trigger is its own model |

Every chat fixture must set the chat trigger's **Make Chat Publicly Available**. Without
it n8n registers no webhook and the fixture answers 404 no matter how active it is.

> **The JSON Agent survives, but its reason changed.** It was written for the
> `promptJsonDecoder` hazard, which only ever existed on the legacy non-agent assistant
> path — a path this module no longer supports. The fixture now pins the opposite
> promise: a JSON-shaped answer reaches the visitor **unmangled**.

> **Never add an LLM-backed fixture.** If a scenario can only pass with a real model,
> it is testing n8n rather than this module — reframe it or tag it `@todo`.

## Conventions

- **No parentheses in step text.** A literal `(` or `)` becomes a regex group, the
  step goes undefined, and the suite fails *while looking green*.
- **`@todo`** marks a documented scenario whose steps aren't wired yet. The runner
  skips them; they are specification, not dead code.
- **A scenario earns its place** by pinning a decision that could plausibly go the
  other way. "Two things are two things" is not a scenario.
