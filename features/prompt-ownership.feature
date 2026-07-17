# The "n8n owns the brain" rule, stated as behaviour.
#
# Your agent's system prompt, model, memory and tools all live in n8n. Drupal's AI
# Assistant form shows fields for those things because the form is shared across
# every provider — but when n8n is the provider they are INERT. Two sets of
# instructions would fight, and n8n's must win, because n8n is where the agent runs.
#
# Under the hood, Drupal's assistant pipeline builds a system prompt from the
# assistant's companion agent and hands it to the provider on every call. This
# provider drops it on the floor, deliberately. That is what these scenarios pin.
#
# This is the most surprising thing about the module, so it is specified rather than
# left to documentation. See README "Settings that intentionally do nothing".
#
# Open question, deliberately NOT specified yet: what happens when an admin attaches
# Drupal tools to the companion agent (the one misconfiguration that makes two
# brains fight). Warn, strip, or refuse — decided when we get there, not guessed.
#
# "Echo Agent" hands back exactly what it received, which is how we can assert what
# we did NOT send.

@todo
Feature: n8n owns the prompt, the model, and the memory
  As a Drupal admin
  I want Drupal to leave my agent's instructions alone
  So that the agent behaves exactly as it does when I test it in n8n

  Background:
    Given the connection to n8n is configured and verified
    And an assistant named "Helper" uses the n8n agent "Echo Agent"

  Scenario: n8n receives only the message and the session
    When a visitor sends "hello" to the assistant "Helper"
    Then n8n received the message "hello"
    And n8n received a session id
    And n8n received no other instructions

  Scenario: Drupal's system prompt does not reach the agent
    Given the assistant "Helper" has the system prompt "You are a pirate"
    When a visitor sends "hello" to the assistant "Helper"
    Then n8n did not receive the system prompt "You are a pirate"

  # The agent already did its own tool calling before we ever see a reply. Whatever
  # the pipeline sends, no tool definitions may reach n8n.
  Scenario: No tool definitions reach n8n
    When a visitor sends "hello" to the assistant "Helper"
    Then n8n received no tool definitions
