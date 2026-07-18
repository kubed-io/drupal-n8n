# The headline use case: a visitor types in Drupal's chat box and the answer comes
# from an n8n agent. This is the whole product in one feature.
#
# What lives here is the round trip — the happy path and the ways it can fail.
# Memory has its own feature; the Drupal signature has its own feature. The failure
# edges live here too, at the bottom, because "the round trip breaks" is an edge of
# the round trip, not a separate product.
#
# The failure rule, one line: a visitor never sees a spinner that never resolves,
# and an admin never reads code to find out why. Every failure surfaces as the
# assistant's own configured error message — Drupal's normal mechanism, not a
# bespoke one — while the real cause goes to the log.

@todo
Feature: Chat with an n8n agent
  As a site visitor
  I want to chat with the site's assistant
  So that I get an answer, without knowing or caring that n8n produced it

  Background:
    Given the connection to n8n is configured and verified
    And an assistant named "Helper" uses the n8n agent "Canned Agent"

  Scenario: The agent's answer reaches the visitor
    When a visitor sends "what is the answer?" to the assistant "Helper"
    Then the assistant replies with "the answer is 42"

  # Drupal's assistant pipeline runs an agent loop that CAN call the provider more
  # than once. With the zero-tools passthrough agent this module sets up, one visitor
  # message is exactly one provider call and one n8n execution — proven live in the
  # POC (saga Chapter 2 §1.1a). This scenario is the canary: if a Drupal AI upgrade
  # changes the loop, this goes red before anyone's LLM bill doubles.
  Scenario: One message triggers exactly one n8n execution
    When a visitor sends "hello" to the assistant "Helper"
    Then the n8n workflow "Canned Agent" has run exactly once

  # An n8n agent may legitimately answer with something JSON-shaped, and it must
  # arrive unmangled. The old JSON-decoder hazard died with the legacy assistant
  # path; this pins the promise that replaced it.
  Scenario: A JSON-shaped answer reaches the visitor intact
    Given an assistant named "Coder" uses the n8n agent "JSON Agent"
    When a visitor sends "give me json" to the assistant "Coder"
    Then the assistant replies with that JSON object as text

  # The headless proving tool: diagnose the whole path with no browser, no assistant,
  # and no block.
  Scenario: An agent can be smoke-tested from the command line
    When the admin sends "hello" to the n8n agent "Canned Agent" with drush
    Then the command prints "the answer is 42"

  # ── When the round trip breaks ─────────────────────────────────────────────
  # The edges below were their own "connection-failure" feature once. They are not
  # a separate product — they are the ways this same round trip fails — so they
  # live here as edges of it. Each asserts the same contract: the assistant's
  # configured error message to the visitor, the real cause to the log, never a hang.

  # The most common failure in practice: n8n only serves a production chat webhook
  # while the workflow is active, so an agent that worked yesterday stops the moment
  # someone toggles it off in n8n. Switching off the trigger's "publicly available"
  # produces the same 404 and must surface the same way — one scenario covers both.
  Scenario: The workflow was deactivated after the assistant was configured
    Given an assistant named "Stale" uses the n8n agent "Inactive Agent"
    When a visitor sends "hello" to the assistant "Stale"
    Then the assistant replies with its configured error message
    And the log explains that the workflow is not active

  Scenario: The agent errors inside n8n
    Given an assistant named "Broken" uses the n8n agent "Failing Agent"
    When a visitor sends "hello" to the assistant "Broken"
    Then the assistant replies with its configured error message
    And the failure is recorded in the log

  Scenario: The agent takes too long to answer
    Given an assistant named "Patient" uses the n8n agent "Slow Agent"
    When a visitor sends "hello" to the assistant "Patient"
    Then the assistant replies with its configured error message
    And the visitor is not left waiting indefinitely

  Scenario: n8n is unreachable
    Given an assistant named "Helper" uses the n8n agent "Canned Agent"
    And n8n is unreachable
    When a visitor sends "hello" to the assistant "Helper"
    Then the assistant replies with its configured error message
    And the failure is recorded in the log
