# The "n8n is an assistant, never an agent" rule, enforced.
#
# An n8n agent is a black box that already did its own tool calling. Handing it to
# Drupal's ai_agents — which expects a raw model it can drive with function calls —
# would mean two agents fighting over one conversation. So n8n must appear where an
# ASSISTANT picks a provider, and nowhere an AGENT does.
#
# THE INVENTORY of provider-selection surfaces (verified live, 2026-07-16). Every
# one of them resolves through the same two service calls, which is why a handful
# of scenarios covers all of them:
#
#   - the AI settings page (/admin/config/ai/settings) lists ~17 operation types.
#     n8n appears on exactly ONE row: plain "Chat" — we implement no other
#     operation interface, so speech-to-text, embeddings, etc. never offer us.
#   - the same page's capability rows — Chat with Tools, Complex JSON, Structured
#     Response, Image Vision — are plain chat filtered by capability: n8n absent
#     from all four, because we declare no capabilities.
#   - the assistant form's provider dropdown asks for plain chat: n8n present.
#   - anything asking the default-provider service for a capability-filtered chat
#     (ai_agents, CKEditor AI, automators) gets the same filter: n8n absent.
#
# Setting n8n as the SITE-WIDE default chat provider is possible and legal — but
# it means every plain-chat consumer on the site talks to one workflow, so the
# README steers people to select n8n per assistant instead.
#
# We do NOT implement this with form alters or hooks. The provider supports the chat
# operation and declines the tools capability; Drupal's own capability filtering does
# the rest, because ai_agents asks for the "chat_with_tools" pseudo-operation which
# resolves to chat filtered by capability.
#
# One nuance since the AI module made every assistant agent-backed: Drupal now
# creates a companion agent entity per assistant. That is scaffolding, not a
# contradiction — the companion is an empty passthrough, and the rule here is about
# the PROVIDER surface: n8n is never offered where something needs a raw model it
# can drive with tools.
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
      | capability          |
      | tools               |
      | complex JSON        |
      | structured response |
      | image vision        |

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
