# The "my n8n agents show up in Drupal" use case, and the one setting that shapes it:
# the site tag.
#
# WHAT A MODEL IS. A model is a CHAT TRIGGER, not strictly a workflow — the trigger is
# the door, the workflow is the building. One workflow may carry several public chat
# triggers, each its own webhook and its own model (proven live): one agent with a
# customer door on the front page and an admin door for staff. The everyday
# single-door case keeps the plain workflow id, so nothing gets uglier for it.
#
# A workflow's trigger qualifies when ACTIVE and its chat trigger is "Make Chat
# Publicly Available" — each rules out a different way of not being able to answer:
#   - no chat trigger  → you cannot hold a conversation with a cron job
#   - not active       → n8n only serves a production webhook while a workflow is active
#   - not public       → the chat webhook is never registered, so it answers 404
# The last is the trap, proven live: active is not enough.
#
# A model does NOT have to contain an AI Agent node. We never look inside; a chat
# trigger wired to a Code node is a valid model.
#
# THE SITE TAG is how Drupal knows which n8n agents belong to this site. It is one
# tag per site — the n8n workflow tag Drupal looks for — mirroring the sibling
# nextcloud-n8n's one-tag-per-folder, except here it is one tag per SITE. Set it in
# the connection settings; tag the workflows you want in n8n. Leave it empty and every
# qualifying workflow is offered, which is the friendly default for a fresh install.
#
# MULTISITE comes for free from Drupal's Domain module: because the client reads the
# tag through the config factory, a per-domain override of the tag gives each subsite
# its own set of agents, with the default site falling through to the global tag. The
# default site behaves identically whether or not the Domain module is installed — so
# a scenario that does not set up a domain is a faithful test of the no-Domain case.
#
# WE DO NOT GENERATE ASSISTANTS. Turning a model into a chat box is the admin's
# choice: they create an AI Assistant, pick n8n as the provider and the agent as the
# model. That is deliberately a human decision — one model can back several assistants
# with different roles and metadata — so it is not something this module automates.
#
# n8n stays the source of truth: the list is read live, nothing about the workflows is
# copied into Drupal's configuration, and nobody presses a sync button.

Feature: n8n agents appear as models
  As a Drupal admin
  I want the n8n chat agents tagged for my site listed as models
  So that I can pick one for an assistant the same way I pick gpt-4o

  Background:
    Given the connection to n8n is configured and verified
    And the site tag is set to "mysite"

  # The base case: two tagged workflows, their public chat triggers become the models.
  Scenario: A tagged active public chat-trigger workflow is offered as a model
    Given the "Echo Agent" workflow is tagged "mysite" in n8n
    When the admin lists the available n8n models
    Then "Echo Agent" is offered as a model

  Scenario: A workflow without the site tag is not offered
    Given the "Canned Agent" workflow is not tagged "mysite" in n8n
    When the admin lists the available n8n models
    Then "Canned Agent" is not offered as a model

  Scenario Outline: A tagged workflow that still cannot answer is not a model
    Given the "<fixture>" workflow is tagged "mysite" in n8n
    When the admin lists the available n8n models
    Then "<fixture>" is not offered as a model

    Examples:
      | fixture        | why                                    |
      | Webhook Only   | no chat trigger                        |
      | Inactive Agent | not active, so no webhook is served    |
      | Private Agent  | chat trigger is not publicly available |

  Scenario: With no site tag set every qualifying workflow is offered
    Given the site tag is not set
    When the admin lists the available n8n models
    Then "Echo Agent" is offered as a model
    And "Canned Agent" is offered as a model

  Scenario: n8n owns the name
    Given the "Rename Me" workflow is renamed to "Support Triage" in n8n
    When the admin lists the available n8n models
    Then "Support Triage" is offered as a model
    And "Rename Me" is not offered as a model

  # The trigger is the door. Two public chat triggers into one tagged flow means two
  # models, each labelled by its door, each with its own webhook and session space.
  Scenario: A workflow with two public chat triggers offers two models
    Given the "Two Doors" workflow is tagged "mysite" in n8n
    When the admin lists the available n8n models
    Then "Two Doors — Front Door" is offered as a model
    And "Two Doors — Admin Door" is offered as a model

  Scenario: Nothing about the workflows is written to Drupal configuration
    When the admin lists the available n8n models
    Then no workflow id appears in Drupal's configuration

  # Multisite. The default site uses the global tag; a second domain overrides it
  # with its own. CLI never negotiates a domain, so the step activates it through
  # domain.negotiation_context — the service that actually gates config overrides.
  @domain
  Scenario: A second domain sees only its own tagged agents
    Given a domain "shop" overrides the site tag to "shopsite"
    And the "Shop Bot" workflow is tagged "shopsite" in n8n
    And the "Echo Agent" workflow is tagged "mysite" in n8n
    When the admin lists the available n8n models on the "shop" domain
    Then "Shop Bot" is offered as a model
    And "Echo Agent" is not offered as a model
