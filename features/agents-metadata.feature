# The "Agents to use" passthrough — the assistant tells n8n which of Drupal's own
# agents its workflow may call, and hands over the list ready to use.
#
# THE CONTRACT. Drupal's MCP server (drupal/mcp) already exposes each Drupal agent
# as a tool. The AI Assistant form already has an "Agents to use" section. This
# feature joins the two: whatever agents are ticked on the assistant travel on
# every message as metadata.agents — an array of the exact MCP tool ids n8n sees
# (aif_<agent_id>, the AI Function Calling exposure, which mirrors the tool Drupal
# itself would call natively). A workflow drops that array straight into an MCP
# Client Tool node's "Tools to Include" expression (={{ $json.metadata.agents }})
# with no transformation.
#
# DRUPAL NEVER RUNS THEM. The selection is not executed on the Drupal side — the
# n8n agent is a passthrough (one message, one provider call) and does its own
# tool calling over MCP. The checkboxes are only a clean way to hand n8n the list,
# so ticking agents must NOT turn the one-call passthrough into two.
#
# SOURCE OF THE IDS. The selection is stored on the assistant's companion agent as
# tools keyed ai_agents::ai_agent::<agent_id>; the provider reads those, keeps the
# agent entries, and emits aif_<agent_id>. Empty selection ⇒ the key is absent, and
# the workflow keeps whatever tools it already had.
#
# The Echo Agent hands back everything it received, which is how the suite asserts
# both what we sent and what we did NOT send.

Feature: The assistant forwards its selected Drupal agents as metadata
  As a workflow author
  I want the assistant's chosen Drupal agents delivered as ready-to-use MCP tool ids
  So that my n8n agent can call exactly those agents back over MCP with no glue

  Background:
    Given the connection to n8n is configured and verified
    And the site tag is set to "mysite"

  # The base case: two Drupal agents ticked, both arrive as their MCP tool ids.
  Scenario: Selected agents ride the metadata as MCP tool ids
    Given an assistant "Helper" backed by the "Echo Agent" agent
    And the assistant "Helper" is allowed to use the Drupal agents "chef" and "storyteller"
    When a visitor chats "hello" with the assistant "Helper"
    Then n8n received the agents "aif_chef" and "aif_storyteller"

  # A zero-detail assistant selects nothing: the key is absent, a pure passthrough.
  Scenario: No selected agents forwards no agents key
    Given an assistant "Bare" backed by the "Echo Agent" agent
    When a visitor chats "hello" with the assistant "Bare"
    Then n8n received no agents from Drupal

  # The load-bearing guarantee: handing n8n the list must not make Drupal run the
  # agents, so the message is still exactly one provider call.
  Scenario: Selecting agents does not break the one-call passthrough
    Given an assistant "Helper" backed by the "Echo Agent" agent
    And the assistant "Helper" is allowed to use the Drupal agents "chef" and "storyteller"
    When a visitor chats "hello" with the assistant "Helper"
    Then the agent made exactly one call to the provider
    And Drupal ran none of the selected agents itself
