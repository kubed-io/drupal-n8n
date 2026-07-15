# The "n8n is an assistant, never an agent" rule, enforced.
#
# An n8n agent is a black box that already did its own tool calling. Handing it to
# Drupal's ai_agents — which expects a raw model it can drive with function calls —
# would mean two agents fighting over one conversation. So n8n must appear where an
# ASSISTANT picks a provider, and nowhere an AGENT does.
#
# We do NOT implement this with form alters or hooks. The provider supports the chat
# operation and declines the tools capability; Drupal's own capability filtering does
# the rest, because ai_agents asks for the "chat_with_tools" pseudo-operation which
# resolves to chat filtered by capability.
#
# This feature exists to prove the framework keeps its side of the bargain. If a
# future Drupal release changes capability filtering, this is the test that goes red
# — and it should, loudly.

@todo
Feature: n8n is offered to assistants, never to agents
  As a Drupal admin
  I want n8n absent from every surface that needs a raw model
  So that I cannot accidentally build something that quietly misbehaves

  Background:
    Given the connection to n8n is configured and verified

  Scenario: n8n is offered as an assistant provider
    When the admin views the provider choices for an AI assistant
    Then "n8n" is offered as a provider

  Scenario Outline: n8n is not offered where a raw model is required
    When the admin views the provider choices for an operation requiring <capability>
    Then "n8n" is not offered as a provider

    Examples:
      | capability   |
      | tools        |
      | complex JSON |

  Scenario: The n8n provider supports chat and declares no capabilities
    When the admin inspects the n8n provider
    Then the n8n provider supports the chat operation
    And the n8n provider supports no other operation
    And the n8n provider declares no model capabilities

  # The two provider kinds must coexist: a site runs a real LLM for its Drupal-side
  # agents AND n8n for its assistants, at the same time, without interfering.
  Scenario: A conventional LLM provider remains available for agents
    Given a conventional LLM provider is configured
    When the admin views the provider choices for an operation requiring tools
    Then the conventional LLM provider is offered as a provider
    And "n8n" is not offered as a provider
