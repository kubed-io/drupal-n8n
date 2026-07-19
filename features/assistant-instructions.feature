# The assistant's Instructions — how one n8n agent becomes many Drupal personas.
#
# THE CONTRACT. An assistant is an overrideable implementation of a model's chat
# trigger. Fill in its Instructions and they travel as metadata.instructions — clean
# admin text, offered as a variable for a generic n8n agent to fold into its prompt.
# Leave them empty and the key is absent: a zero-detail assistant is a pure
# passthrough where n8n owns the whole agent. Several assistants can back one model,
# each with its own instructions, which is why turning a model into a persona is a
# real design choice and not something the module automates.
#
# CLEAN, NOT THE RUNTIME PROMPT. instructions is the admin's stored Instructions
# text, never the agent loop's per-turn runtime framing ("This is the first time
# this agent has been run.") — that framing is noise and must not leak.
#
# This absorbed the old prompt-ownership concern: "Drupal's prompt never reaches the
# conversation" and "the prompt travels as metadata" are two sides of one contract.
# n8n owns the brain; Drupal offers the persona.
#
# The Echo Agent hands back everything it received, which is how the suite asserts
# both what we sent and what we did NOT send.

Feature: An assistant's instructions ride along as a persona
  As a site builder
  I want an assistant's Instructions delivered as clean metadata
  So that one n8n agent can wear a different persona per assistant

  Background:
    Given the connection to n8n is configured and verified
    And the site tag is set to "mysite"

  # A zero-detail assistant — provider and model chosen, no instructions — is a pure
  # passthrough: n8n owns the entire agent, and no instructions ride the metadata.
  Scenario: A zero-detail assistant forwards no instructions
    Given an assistant "Bare" backed by the "Echo Agent" agent with no instructions
    When a visitor chats "hello" with the assistant "Bare"
    Then the message was marked as coming from Drupal
    And n8n received no instructions from Drupal

  # An assistant that extends the agent: its instructions reach the workflow as
  # metadata, clean, so a generic n8n agent can read and act on them.
  Scenario: An assistant's instructions reach the agent as metadata
    Given an assistant "Fancy" backed by the "Echo Agent" agent instructed to "Always answer in French"
    When a visitor chats "hello" with the assistant "Fancy"
    Then the message was marked as coming from Drupal
    And n8n received the instructions "Always answer in French"
