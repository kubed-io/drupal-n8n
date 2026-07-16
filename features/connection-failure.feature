# The "it broke, and I can tell why in five seconds" use case.
#
# This module sits between a Drupal chat box and a remote service that can be down,
# misconfigured, slow, or switched off. The rule: a visitor never sees a spinner that
# never resolves, and an admin never reads code to find out what happened.
#
# Failures surface as the assistant's own configured error message — Drupal's normal
# mechanism, not a bespoke one — while the real cause goes to the log.

@todo
Feature: Failures surface instead of hanging
  As a site visitor
  I want to be told when the assistant cannot answer
  So that I am not left staring at a chat box that never responds

  Background:
    Given the connection to n8n is configured and verified

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
