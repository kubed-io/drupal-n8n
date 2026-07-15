<?php

declare(strict_types=1);

namespace Drupal\Tests\n8n\Integration\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Talks to the ephemeral n8n directly, as the *other* side of every assertion.
 *
 * The point of the integration suite is that it checks both ends: Drupal says it
 * sent a message, and n8n independently agrees it received one. This trait is the
 * n8n end, and it deliberately does NOT go through the module under test.
 *
 * Ported from the sibling nextcloud-n8n project — same n8n, same public API, so
 * this is the piece that transfers between them almost unchanged.
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
   * Fetches the workflows n8n knows about.
   *
   * @return array
   *   The `data` array from n8n's response, or an empty array.
   */
  protected function n8nWorkflows(): array {
    $response = $this->n8n()->get('/api/v1/workflows', [
      'headers' => ['X-N8N-API-KEY' => $this->n8nApiKey()],
    ]);

    $body = json_decode((string) $response->getBody(), TRUE);
    return is_array($body) && isset($body['data']) ? $body['data'] : [];
  }

  /**
   * Finds a fixture workflow by its name.
   *
   * Fixture names are the ones this repo owns — "Echo Agent", "Canned Agent" and
   * friends — never a workflow from anyone's real n8n.
   */
  protected function n8nWorkflowByName(string $name): ?array {
    foreach ($this->n8nWorkflows() as $workflow) {
      if (($workflow['name'] ?? NULL) === $name) {
        return $workflow;
      }
    }
    return NULL;
  }

}
