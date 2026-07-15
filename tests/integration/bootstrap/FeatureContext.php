<?php

declare(strict_types=1);

namespace Drupal\Tests\n8n\Integration;

use Behat\Behat\Context\Context;
use Drupal\Tests\n8n\Integration\Support\DrushTrait;
use Drupal\Tests\n8n\Integration\Support\N8nApiTrait;
use PHPUnit\Framework\Assert;

/**
 * Step definitions for the n8n integration suite.
 *
 * Only the harness steps are wired today. Every other feature is tagged @todo and
 * skipped by behat.dist.yml — those files are the specification for work not yet
 * done, and each loses its tag as its steps land here.
 *
 * Keep the parentheses out of step text: a literal ( or ) becomes a regex group,
 * the step silently goes undefined, and the suite fails while looking green.
 */
class FeatureContext implements Context {

  use DrushTrait;
  use N8nApiTrait;

  /**
   * Asserts the module under test is enabled on the site.
   *
   * @Given the n8n module is installed and enabled
   */
  public function theModuleIsInstalledAndEnabled(): void {
    $output = $this->drush('pm:list', '--status=enabled', '--filter=n8n', '--field=name');

    Assert::assertStringContainsString(
      'n8n',
      $output,
      'The n8n module should be enabled on the site under test. Did the workflow install it?',
    );
  }

  /**
   * Asserts Drupal itself is up, before we blame the module.
   *
   * @Then drush reports the site is bootstrapped
   */
  public function drushReportsTheSiteIsBootstrapped(): void {
    $output = $this->drush('status', '--field=bootstrap');

    Assert::assertSame(0, $this->drushExitCode(), 'drush status should exit zero.');
    Assert::assertStringContainsString(
      'Successful',
      $output,
      'Drupal should be bootstrapped. If this fails the site is broken, not the module.',
    );
  }

  /**
   * Asserts the ephemeral n8n container came up.
   *
   * @Then n8n reports that it is healthy
   */
  public function n8nReportsThatItIsHealthy(): void {
    Assert::assertTrue(
      $this->n8nIsHealthy(),
      sprintf('n8n should be healthy at %s. If this fails the container never came up.', $this->n8nUrl()),
    );
  }

}
