# The "I tagged it in n8n, now let me chat with it" use case — publishing an agent to
# the site is one tag, not a form.
#
# Sync decides which assistants EXIST, and nothing else. It never copies behaviour in
# either direction: Drupal's roles/greeting/error messages are never pushed to n8n,
# and n8n's prompt/model/memory/tools are never pulled into Drupal. The sibling
# project nextcloud-n8n syncs content two-way because there the file IS the workflow;
# here nothing is shared, so the only honest direction is n8n → Drupal, existence only.
#
# Sync only manages assistants it created — they are stamped with the workflow id they
# came from. Identity is the workflow id, never the name. Losing the tag DISABLES the
# synced assistant rather than deleting it, because an assistant carries work n8n
# knows nothing about; a disabled assistant's chat box simply stops rendering, which
# is stock ai_chatbot behaviour, not ours.

@todo
Feature: Tagged n8n workflows become Drupal assistants
  As a Drupal admin
  I want the workflows I tag in n8n to show up as assistants
  So that publishing an agent to my site is one tag, not a form

  Background:
    Given the connection to n8n is configured and verified
    And the admin has set the n8n assistant tag to "drupal"

  Scenario: A tagged workflow becomes a working assistant
    Given the "Echo Agent" workflow is tagged "drupal" in n8n
    When the admin syncs assistants from n8n
    Then an enabled assistant named "Echo Agent" exists
    And it uses the n8n provider with the "Echo Agent" workflow as its model

  # Identity is the workflow id — otherwise a rename orphans the assistant and the
  # next sync creates a second one.
  Scenario: Renaming in n8n updates the assistant instead of duplicating it
    Given the "Echo Agent" workflow is tagged "drupal" in n8n
    And the admin syncs assistants from n8n
    When the "Echo Agent" workflow is renamed to "Support Triage" in n8n
    And the admin syncs assistants from n8n
    Then an assistant named "Support Triage" exists
    And exactly one assistant uses that workflow as its model

  Scenario: Losing the tag disables the assistant and re-tagging restores it intact
    Given the "Echo Agent" workflow is tagged "drupal" in n8n
    And the admin syncs assistants from n8n
    And the admin sets the "Echo Agent" assistant's greeting to "Welcome to support"
    When the "drupal" tag is removed from the "Echo Agent" workflow in n8n
    And the admin syncs assistants from n8n
    Then the "Echo Agent" assistant is disabled but still exists
    When the "drupal" tag is added back to the "Echo Agent" workflow in n8n
    And the admin syncs assistants from n8n
    Then the "Echo Agent" assistant is enabled
    And its greeting is still "Welcome to support"

  # Deleting has to reach n8n or it does not stick — the tag would recreate the
  # assistant on the next sync. Untagging is the least destructive thing that makes
  # the deletion mean something; the workflow itself is untouched.
  Scenario: Deleting a synced assistant untags the workflow so it stays gone
    Given the "Echo Agent" workflow is tagged "drupal" in n8n
    And the admin syncs assistants from n8n
    When the admin deletes the "Echo Agent" assistant
    And the admin syncs assistants from n8n
    Then the "Echo Agent" workflow exists in n8n without the "drupal" tag
    And no assistant named "Echo Agent" exists

  # Sync stays inside its own lane: it only touches what it stamped.
  Scenario: A hand-made assistant is never touched
    Given the admin has created an assistant named "My Own Bot" by hand
    And "My Own Bot" uses the "Echo Agent" workflow as its model
    And the "Echo Agent" workflow is tagged "drupal" in n8n
    When the "drupal" tag is removed from every workflow in n8n
    And the admin syncs assistants from n8n
    Then the "My Own Bot" assistant is enabled and still exists
    When the admin deletes the "My Own Bot" assistant
    Then the "Echo Agent" workflow still exists in n8n

  # A synced assistant is a normal assistant: what Drupal owns is the admin's, and
  # nothing the admin does to it leaks back into n8n.
  Scenario: Drupal-owned settings survive sync and never reach n8n
    Given the "Echo Agent" workflow is tagged "drupal" in n8n
    And the admin syncs assistants from n8n
    And the admin restricts the "Echo Agent" assistant to the "editor" role
    When the admin syncs assistants from n8n
    Then the "Echo Agent" assistant is still restricted to the "editor" role
    And the "Echo Agent" workflow in n8n is unchanged

  Scenario: Syncing without a tag configured is refused rather than guessed
    Given the admin has not set an n8n assistant tag
    When the admin syncs assistants from n8n
    Then the sync is refused with an error naming the missing tag setting
    And no assistant is created
