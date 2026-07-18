# The "admin connects Drupal to n8n" use case — this module's "I'm logged in" gate,
# and a prerequisite to every other feature. The admin points the module at an n8n
# instance with a base URL and an API key, then tests the connection.
#
# The API key is held by the Key module — the drupal.org "Key" project, a hard
# dependency of this module — so the secret can live in a file, an env var, or a
# secrets manager. We only ever hold the key entity's name.
#
# Obtaining an n8n API key is out of scope — that's the n8n admin's job. In the tests
# it is minted against the ephemeral n8n and provided as setup.

Feature: Admin connects Drupal to n8n
  As a Drupal admin
  I want to point Drupal at my n8n and verify the connection
  So that every n8n feature has a valid, tested connection to rely on

  Background:
    Given the key module is installed and enabled
    And the n8n module is installed and enabled
    And a key holding a valid n8n API key was added to Drupal

  Scenario: Set up and verify the connection
    When the admin sets the n8n base URL
    And the admin selects a key holding a valid n8n API key
    And the admin tests the connection
    Then the connection is verified

  Scenario Outline: Testing fails when the connection is wrong
    When the admin configures the connection with <problem>
    And the admin tests the connection
    Then the connection test reports a failure

    Examples:
      | problem              |
      | an invalid API key   |
      | an unreachable host  |

  # Security boundary: the key belongs to the Key module and must never come back
  # out through our form. See SECURITY.md. Needs a rendered page, which the
  # harness cannot serve yet — hence the tag.
  @todo
  Scenario: The API key is never echoed back to the browser
    Given the connection to n8n is configured and verified
    When the admin views the n8n settings form
    Then the raw n8n API key is not present in the page

  # The whole connection must be bakeable by a deployment lifecycle, with no human
  # clicking a form. A non-zero exit is what lets an install script fail loudly.
  Scenario: The connection is configurable from the command line
    When the admin configures and tests the connection with drush
    Then the connection is verified
    And the command exits with a zero status
