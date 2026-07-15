<?php

declare(strict_types=1);

namespace Drupal\n8n;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Talks to an n8n instance's REST API.
 *
 * The API key is never held by this module: we store the machine name of a Key
 * entity and ask the key repository for the value at call time.
 *
 * @see \Drupal\n8n\N8nClientInterface
 * @see SECURITY.md — secrets policy, and the deliberate SSRF trade-off
 */
class N8nClient implements N8nClientInterface {

  use StringTranslationTrait;

  /**
   * The config object holding the connection.
   *
   * Named here so the form, the drush commands and this client cannot drift onto
   * different config objects.
   */
  public const CONFIG_NAME = 'n8n.settings';

  /**
   * Seconds to wait on n8n before giving up, when nothing is configured.
   */
  protected const DEFAULT_TIMEOUT = 30;

  public function __construct(
    protected readonly ClientInterface $httpClient,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly KeyRepositoryInterface $keyRepository,
    protected readonly LoggerInterface $logger,
    TranslationInterface $string_translation,
  ) {
    $this->setStringTranslation($string_translation);
  }

  /**
   * {@inheritdoc}
   */
  public function isConfigured(): bool {
    return $this->getBaseUrl() !== '' && $this->getApiKey() !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function getBaseUrl(): string {
    return rtrim((string) $this->getConfig()->get('base_url'), '/');
  }

  /**
   * {@inheritdoc}
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
   * {@inheritdoc}
   */
  public function keyExists(string $key_id): bool {
    return $this->keyRepository->getKey($key_id) !== NULL;
  }

  /**
   * {@inheritdoc}
   *
   * Refuses before reaching the network when nothing is configured: "you have
   * not set this up" and "n8n is down" are different problems and deserve
   * different messages.
   */
  public function testConnection(): array {
    if ($this->getBaseUrl() === '') {
      return $this->error($this->t('No n8n base URL is configured.'));
    }
    if ($this->getApiKey() === '') {
      return $this->error($this->t('No n8n API key is configured.'));
    }

    try {
      // The cheapest question n8n answers, and it exercises URL + key together.
      $this->request('GET', '/api/v1/workflows', ['limit' => 1]);
      return ['status' => 'ok', 'message' => $this->t('Connected to n8n.')];
    }
    catch (GuzzleException $e) {
      return $this->error($this->friendlyError($e));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function request(string $method, string $path, array $query = []): array {
    $response = $this->httpClient->request($method, $this->getBaseUrl() . $path, [
      'headers' => ['X-N8N-API-KEY' => $this->getApiKey()],
      'query' => $query,
      'timeout' => (int) ($this->getConfig()->get('timeout') ?: self::DEFAULT_TIMEOUT),
      // n8n normally sits beside Drupal on a private network, so private and
      // link-local addresses must stay reachable. Admin-only, and deliberate.
      // @see SECURITY.md — "Network egress and local addresses".
      'allow_redirects' => FALSE,
    ]);

    $decoded = json_decode((string) $response->getBody(), TRUE);

    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Turns a transport exception into something an admin can act on.
   *
   * The status code is safe to log; the request headers carry the key and are
   * deliberately never logged.
   */
  protected function friendlyError(GuzzleException $e): \Stringable|string {
    $code = method_exists($e, 'getResponse') && $e->getResponse()
      ? $e->getResponse()->getStatusCode()
      : 0;

    $message = match ($code) {
      401, 403 => $this->t('n8n rejected the API key.'),
      404 => $this->t('The n8n API was not found at that URL.'),
      0 => $this->t('Could not reach n8n at that URL.'),
      default => $this->t('n8n returned HTTP @code.', ['@code' => $code]),
    };

    $this->logger->error('n8n request failed: @message', ['@message' => $message]);

    return $message;
  }

  /**
   * An error result, shaped like every other result.
   */
  protected function error(\Stringable|string $message): array {
    return ['status' => 'error', 'message' => $message];
  }

  /**
   * The module's settings.
   */
  protected function getConfig(): ImmutableConfig {
    return $this->configFactory->get(self::CONFIG_NAME);
  }

}
