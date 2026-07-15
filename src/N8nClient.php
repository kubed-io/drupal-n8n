<?php

declare(strict_types=1);

namespace Drupal\n8n;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Talks to an n8n instance's REST API.
 *
 * This is the only place the n8n connection is read. Everything in this repo —
 * the AI provider, the webform handler, the drush commands — goes through here,
 * so there is exactly one answer to "where does the URL and key come from?".
 *
 * The API key is never held by this module: we store the machine name of a Key
 * entity and ask the key repository for the value at call time. See SECURITY.md.
 */
class N8nClient {

  /**
   * The config object holding the connection.
   *
   * Named here rather than inline so the settings form, the drush commands and
   * this client cannot drift onto different config objects.
   */
  public const CONFIG_NAME = 'n8n.settings';

  /**
   * Constructs the client.
   */
  public function __construct(
    protected readonly ClientInterface $httpClient,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly KeyRepositoryInterface $keyRepository,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Whether a base URL and a key have both been configured.
   *
   * This is a configuration check, not a reachability check — it answers "has an
   * admin set this up?", not "is n8n up right now?". Callers on a request path
   * want this; only testConnection() should touch the network.
   */
  public function isConfigured(): bool {
    return $this->getBaseUrl() !== '' && $this->getApiKey() !== '';
  }

  /**
   * The configured base URL, without a trailing slash.
   */
  public function getBaseUrl(): string {
    $url = (string) $this->getConfig()->get('base_url');
    return rtrim($url, '/');
  }

  /**
   * Resolves the n8n API key through the Key module.
   *
   * Returns an empty string rather than throwing when the key is missing or the
   * entity has been deleted — callers decide whether that is fatal.
   */
  public function getApiKey(): string {
    $key_id = (string) $this->getConfig()->get('api_key');
    if ($key_id === '') {
      return '';
    }
    $key = $this->keyRepository->getKey($key_id);
    return $key ? (string) $key->getKeyValue() : '';
  }

  /**
   * Whether a Key entity with this machine name exists.
   *
   * Lets a caller refuse a dangling reference at the moment it is set, rather
   * than discovering it later when someone tries to chat.
   */
  public function keyExists(string $key_id): bool {
    return $this->keyRepository->getKey($key_id) !== NULL;
  }

  /**
   * Verifies the URL and key against a live n8n.
   *
   * This is the only method that deliberately reaches the network on behalf of
   * an admin clicking a button, so it owns the friendly-error mapping.
   *
   * @return array
   *   An array with 'status' of 'ok' or 'error', and a human-readable 'message'.
   */
  public function testConnection(): array {
    if ($this->getBaseUrl() === '') {
      return ['status' => 'error', 'message' => 'No n8n base URL is configured.'];
    }
    if ($this->getApiKey() === '') {
      return ['status' => 'error', 'message' => 'No n8n API key is configured.'];
    }

    try {
      $this->request('GET', '/api/v1/workflows', ['limit' => 1]);
      return ['status' => 'ok', 'message' => 'Connected to n8n.'];
    }
    catch (GuzzleException $e) {
      return ['status' => 'error', 'message' => $this->friendlyError($e)];
    }
  }

  /**
   * Issues a request against the n8n public API.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   A path under the base URL, starting with a slash.
   * @param array $query
   *   Query parameters.
   *
   * @return array
   *   The decoded JSON response body.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   *   When the request fails. Callers decide how loud that should be.
   */
  public function request(string $method, string $path, array $query = []): array {
    $response = $this->httpClient->request($method, $this->getBaseUrl() . $path, [
      'headers' => ['X-N8N-API-KEY' => $this->getApiKey()],
      'query' => $query,
      'timeout' => (int) ($this->getConfig()->get('timeout') ?: 30),
      // n8n normally lives beside Drupal on a private network, so private and
      // link-local addresses must be reachable. This is a documented, admin-only
      // SSRF trade-off — see SECURITY.md before changing it.
      'allow_redirects' => FALSE,
    ]);

    $body = (string) $response->getBody();
    $decoded = json_decode($body, TRUE);

    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Turns a transport exception into something an admin can act on.
   */
  protected function friendlyError(GuzzleException $e): string {
    $code = method_exists($e, 'getResponse') && $e->getResponse()
      ? $e->getResponse()->getStatusCode()
      : 0;

    $message = match ($code) {
      401, 403 => 'n8n rejected the API key.',
      404 => 'The n8n API was not found at that URL.',
      0 => 'Could not reach n8n at that URL.',
      default => sprintf('n8n returned HTTP %d.', $code),
    };

    // The status code is safe to log; the request headers carry the key and are
    // deliberately never logged.
    $this->loggerFactory->get('n8n')->error('n8n request failed: @message', [
      '@message' => $message,
    ]);

    return $message;
  }

  /**
   * The module's settings.
   */
  protected function getConfig() {
    return $this->configFactory->get(self::CONFIG_NAME);
  }

}
