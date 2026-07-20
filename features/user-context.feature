# Who is asking — the visitor, and who the assistant is for.
#
# One switch, "Forward visitor identity to n8n", turns this whole block on. It is
# off by default, because it is all personal or access data:
#
#   metadata.user           the visitor's username
#   metadata.user_roles     the visitor's Drupal roles — always a list
#   metadata.allowed_roles  the roles the assistant is limited to
#
# With the switch off, none of it travels and the agent behaves exactly as before.
# With it on, all three arrive together — and allowed_roles is ALWAYS a list: the
# assistant's roles when it restricts, or an empty list when it is open to
# everyone, so a workflow can read it without checking whether the key is there.
#
# allowed_roles is context, never a gate. Drupal has already decided who may use
# the assistant before the message ever leaves, so a workflow can log it or branch
# on it, but it changes nothing on the n8n side.
#
# The Echo Agent hands back everything it received, which is how the suite checks
# both what we sent and what we deliberately did not.

Feature: The message can carry who is asking
  As a workflow author
  I want the visitor and the assistant's audience as context
  So that my agent can tailor its answer to who is talking

  Background:
    Given the connection to n8n is configured and verified
    And the site tag is set to "mysite"

  # The switch is on, so the visitor's name and roles ride along.
  # @todo Goes live once the harness can drive the runner AS a chosen visitor
  # (account switch + role creation); the logic is proven in N8nUserContextTest.
  @todo
  Scenario: With the switch on, the visitor's name and roles travel
    Given an assistant "Helper" backed by the "Echo Agent" agent with user context enabled
    And the current visitor is "jdoe" with roles "authenticated" and "content_editor"
    When a visitor chats "hello" with the assistant "Helper"
    Then n8n received the user "jdoe"
    And n8n received the user roles "authenticated" and "content_editor"

  # A restricted assistant hands over the roles it is for — always present under
  # the switch, so the workflow never has to guess whether the key is there.
  Scenario: A restricted assistant forwards the roles it is for
    Given an assistant "Editors Only" backed by the "Echo Agent" agent with user context enabled restricted to role "content_editor"
    When a visitor chats "hello" with the assistant "Editors Only"
    Then n8n received the allowed roles "content_editor"

  # An assistant open to everyone still sends the key — as an empty list.
  Scenario: An open assistant forwards an empty allowed-roles list
    Given an assistant "Open Desk" backed by the "Echo Agent" agent with user context enabled
    When a visitor chats "hello" with the assistant "Open Desk"
    Then n8n received an empty allowed-roles list

  # The switch is off by default, so nothing about the visitor or the audience
  # travels — the agent behaves exactly as it does in n8n's own chat.
  Scenario: With the switch off, nothing about the user travels
    Given an assistant "Bare" backed by the "Echo Agent" agent
    When a visitor chats "hello" with the assistant "Bare"
    Then n8n received no user from Drupal
    And n8n received no user roles from Drupal
    And n8n received no allowed roles from Drupal
