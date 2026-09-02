<?php

declare(strict_types=1);

namespace Drupal\Tests\n8n\Integration\Steps;

use Behat\Gherkin\Node\TableNode;
use PHPUnit\Framework\Assert;

/**
 * Setting up the n8n connection, and testing it.
 *
 * The connection is this module's "I'm logged in" gate: nothing else can
 * be asserted until a base URL and an API key are in place and n8n has
 * answered.
 *
 * Every field is driven through the drush command that owns it, because
 * those commands ARE the admin surface a deployment lifecycle uses — the
 * whole connection has to be bakeable with no human at a form, and a
 * non-zero exit is what lets an install script fail loudly. Driving
 * config directly instead would test Drupal's config system rather than
 * this module.
 *
 * The placeholder convention that lets the feature file name a URL and a
 * key while the suite talks to a real ephemeral n8n is documented on
 * {@see \Drupal\Tests\n8n\Integration\Support\SetupTrait}.
 */
trait ConnectionSteps {

  /**
   * Fills in the connection form, field by field, as an admin would.
   *
   * The table is the form: one row per field, the field name on the left
   * and the value on the right. Field names are matched
   * case-insensitively, so the spec can write "base URL" and "API key"
   * the way a form label would.
   *
   * A blank cell means the admin left the field alone, which is not the
   * same as submitting an empty string — so it never reaches the command
   * at all, and the site keeps whatever it already had.
   *
   * @When an admin configures the n8n with:
   */
  public function anAdminConfiguresTheN8nWith(TableNode $table): void {
    $stated = [];
    foreach ($table->getRowsHash() as $field => $value) {
      $value = trim((string) $value);
      if ($value !== '') {
        $stated[strtolower(trim((string) $field))] = $value;
      }
    }

    $known = ['base url', 'api key', 'tag', 'timeout'];
    foreach (array_keys($stated) as $field) {
      Assert::assertContains($field, $known, sprintf(
        'The connection has no "%s" field. n8n.settings holds: %s.',
        $field,
        implode(', ', $known),
      ));
    }

    $this->applyConnection($stated);
    $this->statedConnection = $stated;
  }

  /**
   * Runs the connection test the way an operator or install script would.
   *
   * Deliberately does not assert here: whether it passed is the next
   * step's claim, and a scenario about a broken connection needs the
   * failure to be data rather than an exception.
   *
   * @When the admin tests the connection
   */
  public function theAdminTestsTheConnection(): void {
    $this->drush('n8n:test');
  }

  /**
   * Asserts the test reported success, and the settings actually stuck.
   *
   * Three claims, because "successful" has to mean more than an exit
   * code: the command exited zero, which is what an install script gates
   * on; it said so in words an admin reads; and the values the admin
   * typed are the values the site is now holding.
   *
   * That last one is what makes this a connection test rather than a
   * reachability test — a module that ignored the form and reached a
   * hard-coded n8n would pass the first two.
   *
   * @Then the connection is reported as successful
   */
  public function theConnectionIsReportedAsSuccessful(): void {
    Assert::assertSame(
      0,
      $this->drushExitCode(),
      'n8n:test should exit zero so an install script can gate on it. Output: ' . $this->drushOutput(),
    );
    Assert::assertStringContainsString(
      'Connected',
      $this->drushOutput(),
      'n8n:test should say it connected, in words an admin reads.',
    );

    Assert::assertTrue(
      $this->n8nAcceptsApiKey(),
      'n8n itself rejected the API key the suite was given, so the harness '
      . 'is broken rather than the module. Check the mint step.',
    );

    $settings = $this->connectionSettings();

    if (isset($this->statedConnection['base url'])) {
      Assert::assertSame(
        $this->resolveBaseUrl($this->statedConnection['base url']),
        $settings['base_url'],
        'The site should be holding the base URL the admin gave it.',
      );
    }
    if (isset($this->statedConnection['api key'])) {
      Assert::assertSame(
        self::KEY_ENTITY,
        $settings['api_key'],
        'The site should hold the NAME of a Key entity, never a secret.',
      );
    }
    if (isset($this->statedConnection['tag'])) {
      Assert::assertSame(
        $this->statedConnection['tag'],
        $settings['tag'],
        'The site should be holding the site tag the admin gave it.',
      );
    }
    if (isset($this->statedConnection['timeout'])) {
      Assert::assertSame(
        (int) $this->statedConnection['timeout'],
        (int) $settings['timeout'],
        'The site should be holding the timeout the admin gave it.',
      );
    }
  }

}
