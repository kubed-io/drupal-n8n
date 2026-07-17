# The headline use case: a visitor types in Drupal's chat box and the answer comes
# from an n8n agent. This is the whole product in one feature.
#
# What lives here is the round trip. Memory has its own feature; the Drupal
# signature has its own feature; failures have their own feature.

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
