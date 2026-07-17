# The Drupal signature — the one place Drupal's knowledge crosses into n8n, and the
# module's distinctive value-add.
#
# THE CONTRACT. The conversation carries exactly one thing: the visitor's newest
# message. Everything else Drupal knows rides in METADATA — the signature — on every
# request: source is always "drupal", plus the site name, the assistant's id, and the
# instructions composed from the assistant form. So every Drupal-originated message is
# identifiable and context-rich, while the agent behaves exactly as it does in n8n's
# own chat unless its workflow chooses to read the metadata.
#
# EVERYTHING IN THE SIGNATURE IS OPTIONAL CONTEXT, NEVER AN ORDER. The assistant form's
# fields do not have to be filled in, its name does not have to match the workflow's —
# an assistant is an overrideable implementation of the model's chat trigger. A generic
# agent can read metadata.instructions as a variable and become a different persona per
# assistant; a workflow that ignores the metadata entirely loses nothing. That is also
# why several assistants can share one model.
#
# This file absorbed prompt-ownership.feature: "Drupal's prompt never reaches the
# conversation" and "the prompt travels as metadata" are two sides of one contract, so
# they are one feature. n8n owns the brain; Drupal offers context.
#
# The Echo Agent hands back everything it received, which is how the suite can assert
# both what we sent and what we did NOT send.

Feature: Every message carries the Drupal signature
  As a workflow author
  I want each Drupal-originated message to carry identifiable Drupal context
  So that one agent can serve many assistants and still behave normally in n8n's own chat

  Background:
    Given the connection to n8n is configured and verified
    And the site tag is set to "mysite"

  Scenario: The signature rides the metadata and the conversation stays clean
    When a message is sent to the "Echo Agent" agent through the provider
    Then n8n received the message "hello from behat" as the whole conversation
    And n8n received the session id "behat-signature"
    And the message carried the Drupal signature
    And the signature carried the instructions "You are a pirate"
    And the conversation did not contain "You are a pirate"
