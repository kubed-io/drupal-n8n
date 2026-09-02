<?php

declare(strict_types=1);

namespace Drupal\Tests\n8n\Integration;

use Behat\Behat\Context\Context;
use Drupal\Tests\n8n\Integration\Steps\ConnectionSteps;
use Drupal\Tests\n8n\Integration\Steps\ModuleSteps;
use Drupal\Tests\n8n\Integration\Steps\ProviderSurfaceSteps;
use Drupal\Tests\n8n\Integration\Support\DrupalEvalTrait;
use Drupal\Tests\n8n\Integration\Support\DrushTrait;
use Drupal\Tests\n8n\Integration\Support\N8nApiTrait;
use Drupal\Tests\n8n\Integration\Support\SetupTrait;
use PHPUnit\Framework\Assert;

/**
 * Behat context for the n8n integration suite.
 *
 * Deliberately thin: it owns the state carried between steps, the lifecycle
 * hooks, and the composition below. Every step definition lives in a per-concern
 * trait, so a new feature grows ONE `Steps/*` trait rather than bloating a
 * single file — the shape the sibling nextcloud-n8n settled on after its own
 * context hit a thousand lines.
 *
 *   bootstrap/
 *     FeatureContext.php   ← you are here: state + lifecycle + composition
 *     Steps/               ← gherkin-facing definitions, one trait per concern
 *       ModuleSteps, ConnectionSteps, ProviderSurfaceSteps
 *     Support/             ← transport and arrange plumbing, no step definitions
 *       DrushTrait, DrupalEvalTrait, N8nApiTrait, SetupTrait
 *
 * Two transports, each faithful to a real actor:
 *  - **drush** ($DRUSH) drives the admin surface the way an operator or a
 *    deployment lifecycle hook does. Every admin action this module offers has a
 *    drush equivalent — a product requirement, not a testing convenience — which
 *    is why the suite needs no browser.  → DrushTrait, with DrupalEvalTrait for
 *    the AI module's services, which have no CLI of their own.
 *  - **n8n REST** (Guzzle, X-N8N-API-KEY) is the independent side: n8n agreeing,
 *    without the module in the middle.  → N8nApiTrait
 *
 * SCOPE, as of the rewrite. This suite covers `features/connection.feature` and
 * nothing else. The previous context carried 1264 lines of definitions for the
 * ten feature files deleted in `features/old/` — chat, memory, the signature,
 * model discovery. Those went with the specs they served; the contracts they
 * encoded are recorded in `saga/Appendix_A_The_Glovebox.md`, and the definitions
 * themselves are in git history for whoever writes `assistant.feature`.
 *
 * Keep parentheses out of step text: a literal ( or ) becomes a regex group, the
 * step silently goes undefined, and the suite fails while looking green.
 */
class FeatureContext implements Context {

  use DrushTrait;
  use DrupalEvalTrait;
  use N8nApiTrait;
  use SetupTrait;
  use ModuleSteps;
  use ConnectionSteps;
  use ProviderSurfaceSteps;

  /**
   * The connection values the scenario stated, lowercased, blanks dropped.
   *
   * Held so a `Then` can hold the site to what the admin actually typed instead
   * of to what the harness happens to be pointed at.
   *
   * @var array<string, string>
   */
  protected array $statedConnection = [];

  /**
   * Whether the once-per-suite prerequisite check has run.
   */
  protected static bool $prerequisitesChecked = FALSE;

  /**
   * Verifies the plumbing before any scenario runs.
   *
   * A prerequisite gate, not a product feature — so it is a lifecycle hook
   * rather than a `harness.feature`. It exists so a red suite means "the module
   * is broken" and not "Drupal never booted or n8n never came up", because from
   * the outside those look identical. Runs once; the static guard keeps it off
   * the per-scenario hot path.
   *
   * @BeforeScenario
   */
  public function verifyPrerequisites(): void {
    if (self::$prerequisitesChecked) {
      return;
    }
    self::$prerequisitesChecked = TRUE;

    $bootstrap = $this->drush('status', '--field=bootstrap');
    Assert::assertSame(
      0,
      $this->drushExitCode(),
      'Prerequisite: drush could not reach Drupal at all. Output: ' . $this->drushOutput(),
    );
    Assert::assertStringContainsString(
      'Successful',
      $bootstrap,
      'Prerequisite: Drupal is not bootstrapping. The plumbing is broken, not the module.',
    );

    Assert::assertTrue(
      $this->n8nIsHealthy(),
      sprintf('Prerequisite: the ephemeral n8n never came up at %s.', $this->n8nUrl()),
    );

    Assert::assertNotSame(
      '',
      $this->n8nApiKey(),
      'Prerequisite: no N8N_API_KEY in the environment. The mint step should have '
      . 'failed the job before the suite ran.',
    );
  }

  /**
   * Puts the connection settings back after every scenario.
   *
   * A scenario that pointed the site at an unreachable host or a wrong key would
   * otherwise hand the next one a broken connection and a failure that reads as
   * a bug. Deleting the settings — rather than restoring a "good" connection —
   * keeps each scenario's arrange honest about what it set up.
   *
   * @AfterScenario
   */
  public function resetConnection(): void {
    $this->statedConnection = [];
    $this->drupalEvalJson(<<<'EVAL'
      \Drupal::configFactory()->getEditable('n8n.settings')
        ->set('base_url', '')
        ->set('api_key', '')
        ->set('tag', '')
        ->set('timeout', 30)
        ->save();
      echo json_encode(TRUE);
      EVAL);
  }

}
