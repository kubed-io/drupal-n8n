# The "my Drupal form feeds an n8n workflow" use case — the second passenger.
#
# Webform already POSTs submissions to any URL via its own Remote Post handler. This
# module does NOT reimplement that; it subclasses it, so Webform's conditions, token
# replacement, error handling and response mapping keep working untouched.
#
# We add exactly one thing: pick the target from a LIST of your n8n endpoints instead
# of pasting a URL, with auth filled in from the connection you already configured.
#
# If stock Remote Post turns out to give us everything, the honest outcome is to ship
# documentation instead of a module — see saga Chapter 1, Phase 4. Everything here is
# @todo until that question is answered; do not build to it yet.

@todo
Feature: Webform submissions reach n8n
  As a site builder
  I want a form's submissions to start an n8n workflow
  So that I can automate what happens after someone fills in my form

  Background:
    Given the connection to n8n is configured and verified
    And the n8n_webform module is installed and enabled
    And a webform named "Contact" exists

  Scenario: The admin picks the target from a list rather than pasting a URL
    When the admin adds the n8n handler to the webform "Contact"
    Then "Webhook Only" is offered as a target
    And the handler does not require a URL to be entered by hand

  Scenario: Submitting the form starts the workflow
    Given the webform "Contact" posts to the n8n workflow "Webhook Only"
    When a visitor submits the webform "Contact"
    Then the n8n workflow "Webhook Only" has run exactly once
    And n8n received the submitted values authenticated

  # Inherited from Webform rather than rebuilt — this proves subclassing did not
  # break what we are riding on.
  Scenario: Webform conditions still apply
    Given the webform "Contact" posts to the n8n workflow "Webhook Only"
    And the handler is conditional on a field value
    When a visitor submits the webform "Contact" without that field value
    Then the n8n workflow "Webhook Only" has not run
