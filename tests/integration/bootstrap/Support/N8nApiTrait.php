<?php

declare(strict_types=1);

namespace Drupal\Tests\n8n\Integration\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Talks to the ephemeral n8n directly, as the *other* side of every assertion.
 *
 * The point of the integration suite is that it checks both ends: Drupal says it
 * reached n8n, and n8n independently agrees. This trait is the n8n end, and it
 * deliberately does NOT go through the module under test.
 *
 * Ported from the sibling nextcloud-n8n project — same n8n, same public API, so
 * this is the piece that transfers between them almost unchanged.
 *
 * Trimmed to what the live suite uses. The workflow read/rename helpers that
 * backed model discovery went with `features/old/`; they are in git history if
 * the rewritten specs want them back.
 */
trait N8nApiTrait {

  /**
   * A Guzzle client pointed at the ephemeral n8n.
   */
  protected ?Client $n8nClient = NULL;

  /**
   * The n8n base URL under test.
   */
  protected function n8nUrl(): string {
    return rtrim(getenv('N8N_URL') ?: 'http://localhost:5678', '/');
  }

  /**
   * The n8n API key, minted by the pipeline before the suite runs.
   */
  protected function n8nApiKey(): string {
    return getenv('N8N_API_KEY') ?: '';
  }

  /**
   * A client for the n8n public API.
   */
  protected function n8n(): Client {
    if ($this->n8nClient === NULL) {
      $this->n8nClient = new Client([
        'base_uri' => $this->n8nUrl(),
        'timeout' => 10,
        'http_errors' => FALSE,
      ]);
    }
    return $this->n8nClient;
  }

  /**
   * Whether n8n is up.
   */
  protected function n8nIsHealthy(): bool {
    try {
      $response = $this->n8n()->get('/healthz');
      return $response->getStatusCode() === 200
        && str_contains((string) $response->getBody(), 'ok');
    }
    catch (GuzzleException) {
      return FALSE;
    }
  }

  /**
   * Whether the minted key is accepted by n8n's own public API.
   *
   * The independent half of "the connection is reported as successful": Drupal
   * says it connected, and this says the same credential works when the module
   * is not the one holding it. A 401 here means the harness handed the suite a
   * bad key, which is a different failure from the module misreporting one.
   */
  protected function n8nAcceptsApiKey(): bool {
    try {
      $response = $this->n8n()->get('/api/v1/workflows?limit=1', [
        'headers' => ['X-N8N-API-KEY' => $this->n8nApiKey()],
      ]);
      return $response->getStatusCode() === 200;
    }
    catch (GuzzleException) {
      return FALSE;
    }
  }

}
