# Who is asking — the visitor's identity, and the assistant's own access list.
#
# THE CONTRACT. When the site owner opts in, every message carries who is talking:
# metadata.user (the current username) and metadata.user_roles (the visitor's
# Drupal roles, always a LIST — a Drupal user holds several roles at once). Both
# come from the current user in the same request; neither needs the page context.
#
# OPT-IN, BECAUSE IT IS PII. A username and roles are personal data, so forwarding
# them is a deliberate setting, default OFF. With it off, neither key is sent and
# the agent behaves exactly as before.
#
# ALLOWED_ROLES IS DIFFERENT — IT IS NOT THE VISITOR'S. The assistant's own Roles
# field (who may use this assistant) travels as metadata.allowed_roles when the
# assistant restricts access. Drupal has ALREADY enforced that gate before the
# message ever reached n8n, so allowed_roles gates nothing on the n8n side — it is
# informational context only, and is config, not PII, so it does not need the opt-in.
#
# The Echo Agent hands back everything it received, which is how the suite asserts
# both what we sent and what we did NOT send.

Feature: The message can carry who is asking
  As a workflow author
  I want the visitor's identity and the assistant's access list as context
  So that my agent can tailor its answer to who is talking

  Background:
    Given the connection to n8n is configured and verified
    And the site tag is set to "mysite"

  # The base case: with user context on, the username and the role list arrive.
  Scenario: User context forwards the visitor's name and roles
    Given an assistant "Helper" backed by the "Echo Agent" agent with user context enabled
    And the current visitor is "jdoe" with roles "authenticated" and "content_editor"
    When a visitor chats "hello" with the assistant "Helper"
    Then n8n received the user "jdoe"
    And n8n received the user roles "authenticated" and "content_editor"

  # Default off: a message carries no identity unless the owner asks for it.
  Scenario: Without opt-in no identity is forwarded
    Given an assistant "Bare" backed by the "Echo Agent" agent
    And the current visitor is "jdoe" with roles "authenticated"
    When a visitor chats "hello" with the assistant "Bare"
    Then n8n received no user from Drupal
    And n8n received no user roles from Drupal

  # The assistant's own access list rides as context — and Drupal has already
  # enforced it, so it never acts as a gate on the n8n side.
  Scenario: A restricted assistant forwards its allowed roles as context
    Given an assistant "Editors Only" backed by the "Echo Agent" agent restricted to role "content_editor"
    When a visitor chats "hello" with the assistant "Editors Only"
    Then n8n received the allowed roles "content_editor"
