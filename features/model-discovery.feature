# The "my n8n agents show up in Drupal" use case. A workflow qualifies as a model when
# it is ACTIVE and starts with a CHAT TRIGGER whose "Make Chat Publicly Available" is
# switched on. All three are required, and each rules out a different way of not
# being able to answer:
#
#   - no chat trigger  → you cannot hold a conversation with a cron job
#   - not active       → n8n only serves a production webhook while a workflow is active
#   - not public       → the chat webhook is never registered, so it answers 404
#
# The last one is the trap, proven live during the POC: a workflow can be active and
# still have no reachable chat webhook. "Active" is not enough.
#
# A model is a CHAT TRIGGER, not strictly a workflow — the trigger is the door, the
# workflow is the building. One workflow may carry several public chat triggers, each
# with its own webhook (proven live): think one agent with a customer door on the
# front page and an admin door for staff. Each door is its own model. The everyday
# single-door case keeps the plain workflow id, so nothing gets uglier for the
# common case.
#
# A model does NOT have to contain an AI Agent node. We never look inside; a chat
# trigger wired to a Code node is a perfectly valid model.
#
# n8n stays the source of truth: the list is read live, nothing is copied into
# Drupal's configuration, and nobody presses a sync button.

@todo
Feature: n8n agents appear as models
  As a Drupal admin
  I want my n8n chat agents listed as models
  So that I can pick one for an assistant the same way I pick gpt-4o

  Background:
    Given the connection to n8n is configured and verified

  Scenario: An active public chat-trigger workflow is offered as a model
    When the admin lists the available n8n models
    Then "Echo Agent" is offered as a model

  Scenario Outline: A workflow that cannot answer is not a model
    When the admin lists the available n8n models
    Then "<fixture>" is not offered as a model

    Examples:
      | fixture        | why                                    |
      | Webhook Only   | no chat trigger                        |
      | Inactive Agent | not active, so no webhook is served    |
      | Private Agent  | chat trigger is not publicly available |

  Scenario: n8n owns the name
    Given the "Echo Agent" workflow is renamed to "Support Triage" in n8n
    When the admin lists the available n8n models
    Then "Support Triage" is offered as a model
    And "Echo Agent" is not offered as a model

  # The trigger is the door. Two public chat triggers into one flow means two
  # models, each labelled by its door, each with its own webhook and session space.
  Scenario: A workflow with two public chat triggers offers two models
    When the admin lists the available n8n models
    Then "Two Doors — Front Door" is offered as a model
    And "Two Doors — Admin Door" is offered as a model

  # The assistant tag is optional. Unset, every qualifying workflow is offered; set,
  # the list narrows to the workflows meant for this site. The tag's second job —
  # deciding which assistants exist — lives in assistant-sync.feature.
  Scenario: The assistant tag narrows the list
    Given the admin has set the n8n assistant tag to "drupal"
    And the "Echo Agent" workflow is tagged "drupal" in n8n
    When the admin lists the available n8n models
    Then "Echo Agent" is offered as a model
    And "Canned Agent" is not offered as a model

  Scenario: Nothing about the workflows is written to Drupal configuration
    When the admin lists the available n8n models
    Then no workflow id appears in Drupal's configuration
