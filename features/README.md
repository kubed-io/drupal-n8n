# The specification

These `.feature` files **are the requirements**. They are written before the code,
they drive the integration suite, and every feature in the [README](../README.md)
links back to one of them.

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
| **JSON Agent** | returns a JSON object as its answer | the `promptJsonDecoder` hazard |
| **Slow Agent** | waits longer than the request timeout | the timeout path |
| **Failing Agent** | throws during execution | the error path |
| **Webhook Only** | a webhook trigger, no chat trigger | the model filter excludes it |
| **Inactive Agent** | a chat trigger, left inactive | the active filter excludes it |

> **Never add an LLM-backed fixture.** If a scenario can only pass with a real model,
> it is testing n8n rather than this module — reframe it or tag it `@todo`.

## Conventions

- **No parentheses in step text.** A literal `(` or `)` becomes a regex group, the
  step goes undefined, and the suite fails *while looking green*.
- **`@todo`** marks a documented scenario whose steps aren't wired yet. The runner
  skips them; they are specification, not dead code.
- **A scenario earns its place** by pinning a decision that could plausibly go the
  other way. "Two things are two things" is not a scenario.
