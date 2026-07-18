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
   * {@inheritdoc}
   *
   * The addressable unit is the CHAT TRIGGER, not the workflow: one workflow
   * can carry several public chat triggers, each with its own registered
   * webhook (proven live — two "doors" into one flow, e.g. a public persona
   * and an admin persona sharing an agent). The common single-trigger case
   * keeps the plain workflow id as the model id; additional triggers get
   * "workflow::webhook" ids and a door-name suffix on the label.
   */
  public function listChatWorkflows(): array {
    $models = [];
    $query = [
      'active' => 'true',
      'excludePinnedData' => 'true',
      'limit' => 100,
    ];
    // The site tag scopes discovery to the workflows meant for this site. Read
    // through configFactory so a Domain override gives each subsite its own tag
    // for free (see README "Different agents per site"). Empty tag means no
    // filter — every qualifying workflow is offered.
    $tag = trim((string) $this->getConfig()->get('tag'));
    if ($tag !== '') {
      $query['tags'] = $tag;
    }
    $result = $this->request('GET', '/api/v1/workflows', $query);
    foreach ($result['data'] ?? [] as $workflow) {
      $doors = [];
      foreach ($workflow['nodes'] ?? [] as $node) {
        if (($node['type'] ?? '') !== '@n8n/n8n-nodes-langchain.chatTrigger') {
          continue;
        }
        // Active is not enough: the chat webhook only registers when the
        // trigger is public. A non-public trigger would 404.
        if (empty($node['parameters']['public']) || empty($node['webhookId'])) {
          continue;
        }
        $doors[] = $node;
      }
      $label = $workflow['name'] ?? $workflow['id'];
      foreach ($doors as $i => $node) {
        $model_id = $i === 0 ? $workflow['id'] : $workflow['id'] . '::' . $node['webhookId'];
        $models[$model_id] = [
          // The Chat Hub agent name, when set, is the human-facing name.
          'label' => ($node['parameters']['agentName'] ?? $label)
          . (count($doors) > 1 ? ' — ' . $node['name'] : ''),
          'webhook_id' => $node['webhookId'],
        ];
      }
    }

    return $models;
  }

  /**
   * {@inheritdoc}
   */
  public function chatSend(string $workflow_id, string $session_id, string $message, array $metadata = []): string {
    $models = $this->listChatWorkflows();
    if (!isset($models[$workflow_id])) {
      throw new \RuntimeException(sprintf('Workflow %s is not an available chat model — it may be inactive or its chat trigger not public.', $workflow_id));
    }

    // The chat webhook is its own surface: no API key travels with this call,
    // and agents can be slow, so the floor is higher than the API timeout.
    $response = $this->httpClient->request('POST', $this->getBaseUrl() . '/webhook/' . $models[$workflow_id]['webhook_id'] . '/chat', [
      'json' => [
        'action' => 'sendMessage',
        'sessionId' => $session_id,
        'chatInput' => $message,
        'metadata' => $metadata ?: new \stdClass(),
      ],
      'timeout' => max((int) ($this->getConfig()->get('timeout') ?: self::DEFAULT_TIMEOUT), 60),
      'allow_redirects' => FALSE,
    ]);

    $decoded = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($decoded) || !array_key_exists('output', $decoded)) {
      throw new \RuntimeException('The n8n workflow answered without an "output" field — name the last node\'s response field "output".');
    }

    return (string) $decoded['output'];
  }

  /**
   * {@inheritdoc}
   *
   * The chat webhook answers loadPreviousSession with `{data: [...]}`, where each
   * entry is a serialised LangChain message — the exact shape a Postgres Chat
   * Memory node returns, and the exact shape `@n8n/chat` consumes. A workflow
   * with no retrieving memory answers with an empty (or absent) data array, so
   * an empty transcript is a normal answer, not an error.
   */
  public function loadPreviousSession(string $workflow_id, string $session_id): array {
    $models = $this->listChatWorkflows();
    if (!isset($models[$workflow_id])) {
      throw new \RuntimeException(sprintf('Workflow %s is not an available chat model — it may be inactive or its chat trigger not public.', $workflow_id));
    }

    $response = $this->httpClient->request('POST', $this->getBaseUrl() . '/webhook/' . $models[$workflow_id]['webhook_id'] . '/chat', [
      'json' => [
        'action' => 'loadPreviousSession',
        'sessionId' => $session_id,
      ],
      'timeout' => max((int) ($this->getConfig()->get('timeout') ?: self::DEFAULT_TIMEOUT), 60),
      'allow_redirects' => FALSE,
    ]);

    $decoded = json_decode((string) $response->getBody(), TRUE);
    $messages = is_array($decoded) ? ($decoded['data'] ?? []) : [];

    $history = [];
    foreach (is_array($messages) ? $messages : [] as $message) {
      if (!is_array($message)) {
        continue;
      }
      $history[] = [
        'role' => $this->roleOfLangchainMessage($message),
        'message' => (string) ($message['kwargs']['content'] ?? $message['content'] ?? ''),
      ];
    }

    return $history;
  }

  /**
   * Normalises a serialised LangChain message to a Drupal chat role.
   *
   * A serialised message identifies its kind either by the last segment of its
   * `id` path (HumanMessage / AIMessage / SystemMessage — the Postgres memory
   * shape) or, in simpler encodings, by a `type` of human / ai / system. Anything
   * unrecognised is treated as a user turn, the safe default for display.
   */
  protected function roleOfLangchainMessage(array $message): string {
    $kind = '';
    if (isset($message['id']) && is_array($message['id'])) {
      $kind = (string) end($message['id']);
    }
    if ($kind === '' && isset($message['type']) && is_string($message['type'])) {
      $kind = $message['type'];
    }

    return match (strtolower($kind)) {
      'aimessage', 'ai', 'assistant' => 'assistant',
      'systemmessage', 'system' => 'system',
      default => 'user',
    };
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
