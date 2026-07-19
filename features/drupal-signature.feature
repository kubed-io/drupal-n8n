# The Drupal signature — the envelope every Drupal-originated message carries.
#
# THE CONTRACT. The conversation carries exactly one thing: the visitor's newest
# message. Everything else Drupal knows rides in METADATA — the signature. This file
# pins the always-on ENVELOPE: source is always "drupal", plus the site name and the
# assistant's id. So every Drupal-originated message is identifiable, while the agent
# behaves exactly as it does in n8n's own chat unless its workflow reads the metadata.
#
# THE REST OF THE SIGNATURE IS PER-CONCERN, ONE OPTIONAL KEY PER SPEC. The signature
# is built to grow; each concern has its own feature file:
#   - assistant-instructions.feature — the assistant's Instructions (personas)
#   - user-context.feature           — the visitor's identity + the assistant's roles
#   - agents-metadata.feature        — the Drupal agents the assistant may use
#   - page-context.feature           — the page the chat box is on
#   - session-memory.feature         — the session id and context_window
# Same rule throughout: metadata Drupal OFFERS, never an order, absent when there is
# nothing to say. n8n owns the brain; Drupal offers context.
#
# The Echo Agent hands back everything it received, which is how the suite asserts
# both what we sent and what we did NOT send.

Feature: Every message carries the Drupal signature envelope
  As a workflow author
  I want each Drupal-originated message stamped with its origin
  So that a workflow can tell Drupal traffic apart and behave normally in n8n's own chat

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
