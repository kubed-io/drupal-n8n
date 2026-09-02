<?php

declare(strict_types=1);

namespace Drupal\Tests\n8n\Integration\Support;

/**
 * Arrange-side plumbing: getting the site into a stated pre-state.
 *
 * No step definitions live here — a `@Given` that reads as product behaviour
 * belongs in a `Steps/*` trait. This is the machinery those steps call.
 *
 * ── THE PLACEHOLDER CONVENTION ─────────────────────────────────────────────
 *
 * `features/connection.feature` writes the connection out as a form the admin
 * fills in, and two of its four fields cannot be taken literally: the suite has
 * to reach a REAL ephemeral n8n with a REAL minted key, and neither value
 * exists until the pipeline is already running.
 *
 * So two cell values are documented placeholders, and everything else is taken
 * at face value:
 *
 *   | base URL | https://n8n.example.com |  → the n8n under test
 *   | API key  | my-secret-api-key       |  → the key minted for this run
 *
 * Any OTHER value is used verbatim, which is what lets a later scenario point
 * at a deliberately unreachable host or a deliberately wrong key and assert the
 * failure. The mapping is narrow and explicit on purpose: a placeholder that
 * silently swallowed every value would make the feature file a decoration.
 *
 * This is a workaround for gherkin that names literals, not an endorsement of
 * it. The sibling suites say `Given the n8n base URL points at the test
 * instance` and never write a URL down; that reads better and needs no
 * convention at all.
 */
trait SetupTrait {

  /**
   * The Key entity the suite stores the n8n API key in.
   *
   * The module holds a key entity's *name*, never a secret, so the suite has to
   * provide the entity the module will name.
   */
  protected const KEY_ENTITY = 'behat_n8n_key';

  /**
   * The base URL cell that means "the n8n this run is testing against".
   */
  protected const PLACEHOLDER_URL = 'https://n8n.example.com';

  /**
   * The API key cell that means "the key minted for this run".
   */
  protected const PLACEHOLDER_KEY = 'my-secret-api-key';

  /**
   * Resolves a stated base URL to the one the suite should actually use.
   */
  protected function resolveBaseUrl(string $stated): string {
    return $stated === self::PLACEHOLDER_URL ? $this->n8nUrl() : $stated;
  }

  /**
   * Resolves a stated API key to the one the suite should actually use.
   */
  protected function resolveApiKey(string $stated): string {
    return $stated === self::PLACEHOLDER_KEY ? $this->n8nApiKey() : $stated;
  }

  /**
   * Creates or updates a Key entity holding a secret.
   *
   * `config` is the right provider here and nowhere else: the suite needs a
   * value it chose to be readable back by the module, which a file or env
   * provider would make the pipeline's problem instead of the test's.
   */
  protected function createKeyEntity(string $id, string $value): void {
    $this->drupalEvalJson(strtr(<<<'PHP'
      $storage = \Drupal::entityTypeManager()->getStorage('key');
      $key = $storage->load(KEY_ID);
      if ($key === NULL) {
        $key = $storage->create(['id' => KEY_ID, 'label' => KEY_ID, 'key_type' => 'authentication']);
      }
      $key->set('key_provider', 'config');
      $key->set('key_provider_settings', ['key_value' => KEY_VALUE]);
      $key->save();
      echo json_encode(TRUE);
      PHP, [
        'KEY_ID' => var_export($id, TRUE),
        'KEY_VALUE' => var_export($value, TRUE),
      ]));
  }

  /**
   * Puts the connection in place through the module's own admin surface.
   *
   * Every field goes through the drush command that owns it, because those
   * commands ARE the admin surface a deployment lifecycle uses — driving config
   * directly would test Drupal's config system instead of this module's. The
   * one exception is the timeout, which has no command yet, so it is set as
   * config.
   *
   * @param array<string, string> $values
   *   Stated connection values, keyed by the feature file's field names,
   *   lowercased: 'base url', 'api key', 'tag', 'timeout'. Absent keys are left
   *   alone at whatever the site already has.
   */
  protected function applyConnection(array $values): void {
    if (isset($values['base url'])) {
      $this->drush('n8n:set-url', $this->resolveBaseUrl($values['base url']));
    }

    if (isset($values['api key'])) {
      $this->createKeyEntity(self::KEY_ENTITY, $this->resolveApiKey($values['api key']));
      $this->drush('n8n:set-key', self::KEY_ENTITY);
    }

    if (isset($values['tag'])) {
      $this->drush('n8n:set-tag', $values['tag']);
    }

    if (isset($values['timeout'])) {
      // No drush command owns the timeout yet, so this is the one field the
      // suite sets as config. Cast to int deliberately: the schema types it as
      // an integer, and drush config:set would store the cell as a string.
      $this->drupalEvalJson(strtr(<<<'EVAL'
        \Drupal::configFactory()->getEditable('n8n.settings')
          ->set('timeout', TIMEOUT)->save();
        echo json_encode(TRUE);
        EVAL, ['TIMEOUT' => var_export((int) $values['timeout'], TRUE)]));
    }
  }

  /**
   * The connection settings the site holds now, as the module sees them.
   *
   * @return array<string, mixed>
   *   The four n8n.settings values, keyed as the config stores them.
   */
  protected function connectionSettings(): array {
    return (array) $this->drupalEvalJson(<<<'PHP'
      $config = \Drupal::config('n8n.settings');
      echo json_encode([
        'base_url' => $config->get('base_url'),
        'api_key' => $config->get('api_key'),
        'tag' => $config->get('tag'),
        'timeout' => $config->get('timeout'),
      ]);
      PHP);
  }

}
