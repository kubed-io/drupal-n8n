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

  /**
   * Whether a workflow carries a tag, per n8n itself.
   *
   * The other side of every tag assertion.
   */
  protected function n8nWorkflowHasTag(string $name, string $tag): bool {
    $workflow = $this->n8nWorkflowByName($name);
    foreach ($workflow['tags'] ?? [] as $t) {
      if (($t['name'] ?? NULL) === $tag) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Renames a workflow through n8n's own API.
   *
   * The public API's update wants the full body back, so this is read-modify-
   * write on name/nodes/connections/settings only — the writable set.
   */
  protected function n8nRenameWorkflow(string $name, string $new_name): void {
    $workflow = $this->n8nWorkflowByName($name);
    if ($workflow === NULL) {
      throw new \RuntimeException("No workflow named '$name' to rename.");
    }
    $response = $this->n8n()->put('/api/v1/workflows/' . $workflow['id'], [
      'headers' => ['X-N8N-API-KEY' => $this->n8nApiKey()],
      'json' => [
        'name' => $new_name,
        'nodes' => $workflow['nodes'],
        'connections' => $workflow['connections'],
        'settings' => $workflow['settings'] ?? ['executionOrder' => 'v1'],
      ],
    ]);
    if ($response->getStatusCode() >= 300) {
      throw new \RuntimeException("Renaming '$name' failed: HTTP " . $response->getStatusCode() . ' ' . (string) $response->getBody());
    }
  }

}
