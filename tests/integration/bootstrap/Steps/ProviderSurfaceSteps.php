<?php

declare(strict_types=1);

namespace Drupal\Tests\n8n\Integration\Steps;

use Behat\Gherkin\Node\TableNode;
use PHPUnit\Framework\Assert;

/**
 * Where n8n shows up once the connection is made — and where it must not.
 *
 * The provider declines every capability and supports only `chat`, so Drupal's
 * own capability filtering keeps it out of every surface that needs a raw model
 * it can drive with tools. Nothing here is implemented with form alters; these
 * steps ask the same service the forms ask.
 *
 * READING "a default option". The step text says the provider is "now a default
 * option for these capabilities", and the operative word is *option*: making a
 * connection makes n8n available to pick, it does not make it the site's default
 * chat provider. It must not — a site's default belongs on a real LLM, and
 * pointing it at n8n would send every plain-chat consumer to one workflow. So
 * these steps assert availability, which is also the behaviour the provider
 * actually implements: `isUsable()` returns FALSE until the connection is
 * configured, and that is the whole claim this feature is making.
 */
trait ProviderSurfaceSteps {

  /**
   * Asserts n8n is an available provider for each listed operation type.
   *
   * The table is a single `capability` column, one row per operation type the
   * connection should have opened up.
   *
   * @Then the :provider provider is now a default option for these capabilities:
   */
  public function theProviderIsNowAnOptionForCapabilities(string $provider, TableNode $table): void {
    $rows = $table->getHash();
    Assert::assertNotEmpty($rows, 'The capability table needs a "capability" header and at least one row.');

    foreach ($rows as $row) {
      Assert::assertArrayHasKey(
        'capability',
        $row,
        'The capability table should have a "capability" header column.',
      );
      $operation = trim((string) $row['capability']);
      $offered = $this->providersForOperationType($operation);

      Assert::assertContains(
        strtolower($provider),
        $offered,
        sprintf(
          'After configuring the connection, "%s" should be an option for the "%s" operation. Offered instead: %s.',
          $provider,
          $operation,
          $offered ? implode(', ', $offered) : 'nothing at all',
        ),
      );
    }
  }

  /**
   * Asserts an assistant can pick n8n as its provider.
   *
   * This is the seam between this feature and the one that matters: the
   * assistant form's provider dropdown asks for plain `chat`, which is the one
   * operation n8n supports — so this is the exact call that form makes, minus
   * the form. It also checks `ai_assistant_api` is installed, so the sentence is
   * about something real rather than about a service nobody is asking.
   *
   * @Then the assistants now offer :provider as a provider
   */
  public function theAssistantsNowOfferProvider(string $provider): void {
    $installed = (bool) $this->drupalEvalJson(<<<'EVAL'
      echo json_encode(\Drupal::moduleHandler()->moduleExists('ai_assistant_api'));
      EVAL);
    Assert::assertTrue(
      $installed,
      'ai_assistant_api should be installed, or "the assistants" is not a surface on this site.',
    );

    $offered = $this->providersForOperationType('chat');
    Assert::assertContains(
      strtolower($provider),
      $offered,
      sprintf(
        'An assistant should be able to pick "%s". The chat providers on offer are: %s.',
        $provider,
        $offered ? implode(', ', $offered) : 'nothing at all',
      ),
    );
  }

  /**
   * The provider ids usable for an operation type, as the AI module reports them.
   *
   * `getProvidersForOperationType` filters to the usable ones, which is what
   * every provider dropdown on the site is built from.
   *
   * @return list<string>
   */
  protected function providersForOperationType(string $operation): array {
    $offered = (array) $this->drupalEvalJson(strtr(<<<'EVAL'
      $providers = \Drupal::service('ai.provider')->getProvidersForOperationType(OPERATION);
      echo json_encode(array_keys($providers));
      EVAL, ['OPERATION' => var_export($operation, TRUE)]));

    return array_values(array_map('strval', $offered));
  }

}
