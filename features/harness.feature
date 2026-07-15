# Proves the integration harness itself works.
#
# This asserts nothing about the module on purpose. It exists so that a red suite
# means "the module is broken" rather than "Behat never started, or Drupal never
# booted, or n8n never came up" — from the outside those look identical.
#
# If this is the only feature that fails, the problem is the harness: the Behat
# config, the autoloader, drush, or the ephemeral n8n container. If this passes and
# others fail, the problem is real.
#
# Do not delete it, and do not make it clever.

Feature: The integration harness works
  As a maintainer
  I want to know the suite can reach Drupal and n8n at all
  So that a red run tells me about my code rather than my plumbing

  Scenario: Drupal is reachable and this module is installed
    Given the n8n module is installed and enabled
    Then drush reports the site is bootstrapped

  Scenario: The ephemeral n8n is reachable
    Then n8n reports that it is healthy
