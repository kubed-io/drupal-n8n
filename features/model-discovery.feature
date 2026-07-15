# The "my n8n agents show up in Drupal" use case. An n8n workflow qualifies as a
# model when it is ACTIVE and starts with a CHAT TRIGGER. Everything else is filtered
# out — you cannot hold a conversation with a cron job, and n8n only serves a
# production chat webhook while a workflow is active.
#
# n8n stays the source of truth: the list is read live, and nothing about the
# workflows is copied into Drupal's configuration.

@todo
Feature: n8n agents appear as models
  As a Drupal admin
  I want my n8n chat agents listed as models
  So that I can pick one for an assistant the same way I pick gpt-4o

  Background:
    Given the connection to n8n is configured and verified

  Scenario: An active chat-trigger workflow is offered as a model
    When the admin lists the available n8n models
    Then "Echo Agent" is offered as a model

  Scenario Outline: A workflow that cannot hold a conversation is not a model
    When the admin lists the available n8n models
    Then "<fixture>" is not offered as a model

    Examples:
      | fixture        |
      | Webhook Only   |
      | Inactive Agent |
