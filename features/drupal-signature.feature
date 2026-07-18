# The Drupal signature — the one place Drupal's knowledge crosses into n8n, and the
# module's distinctive value-add.
#
# THE CONTRACT. The conversation carries exactly one thing: the visitor's newest
# message. Everything else Drupal knows rides in METADATA — the signature — on every
# request: source is always "drupal", plus the site name, the assistant's id, and the
# assistant's own instructions. So every Drupal-originated message is identifiable and
# context-rich, while the agent behaves exactly as it does in n8n's own chat unless
# its workflow chooses to read the metadata.
#
# INSTRUCTIONS ARE THE ASSISTANT'S, CLEAN, AND OPTIONAL. An assistant is an
# overrideable implementation of a model's chat trigger: fill in its Instructions and
# they travel as metadata.instructions for a generic n8n agent to read as a variable;
# leave them empty and nothing is forwarded — a zero-detail assistant is a pure
# passthrough where n8n owns the whole agent. Several assistants can back one model,
# each with its own instructions, which is why this is a real design choice and not
# something the module automates. The instructions are the admin's clean text, never
# the agent loop's per-turn runtime framing.
#
# This file absorbed prompt-ownership.feature: "Drupal's prompt never reaches the
# conversation" and "the prompt travels as metadata" are two sides of one contract.
# n8n owns the brain; Drupal offers context.
#
# The Echo Agent hands back everything it received, which is how the suite asserts
# both what we sent and what we did NOT send.

Feature: Every message carries the Drupal signature
  As a workflow author
  I want each Drupal-originated message to carry identifiable Drupal context
  So that one agent can serve many assistants and still behave normally in n8n's own chat

  Background:
    Given the connection to n8n is configured and verified
    And the site tag is set to "mysite"

  # The transport signature, tested at the provider directly: the message is the
  # whole conversation, and it is stamped with source, site and the assistant id.
  Scenario: The signature rides the metadata and the conversation stays clean
    When a message is sent to the "Echo Agent" agent through the provider
    Then n8n received the message "hello from behat" as the whole conversation
    And n8n received the session id "behat-signature"
    And the message carried the Drupal signature

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
