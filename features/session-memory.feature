# The session contract. Each Drupal conversation maps to one n8n session, so the
# agent's own memory node threads it exactly as it would in n8n's chat window.
#
# THE MEMORY LIVES IN n8n, NOT DRUPAL. Drupal stores no transcript and replays no
# history — it sends the newest message plus a stable session id. Replaying history
# would make the agent see every message twice, because n8n already has it.
#
# Note what is NOT specified here: whether the agent actually remembers. That is
# n8n's memory node doing its job, not ours. Our contract is that the right session
# id arrives — which "Echo Agent" proves by handing back what it received.

@todo
Feature: Conversations are threaded and isolated
  As a site visitor
  I want my conversation to be mine and to continue where I left off
  So that the assistant is useful and does not leak other people's chats

  Background:
    Given the connection to n8n is configured and verified
    And an assistant named "Helper" uses the n8n agent "Echo Agent"

  Scenario: A follow-up message continues the same session
    Given a visitor has sent "first" to the assistant "Helper"
    When the visitor sends "second" to the assistant "Helper"
    Then both messages reached n8n with the same session id

  Scenario: Only the newest message is sent to n8n
    Given a visitor has sent "first" to the assistant "Helper"
    When the visitor sends "second" to the assistant "Helper"
    Then n8n received only the message "second"

  # A privacy boundary this module owns. Drupal derives its thread id from the
  # current user's id — and every anonymous visitor is user 0. If that reaches n8n
  # unmodified, all anonymous visitors share one memory session and can read each
  # other's conversation. See SECURITY.md and saga §9. This scenario is the guard.
  Scenario: Two anonymous visitors do not share a conversation
    Given a visitor has sent "my secret" to the assistant "Helper"
    When a different anonymous visitor sends "hello" to the assistant "Helper"
    Then the two visitors' messages reached n8n with different session ids
