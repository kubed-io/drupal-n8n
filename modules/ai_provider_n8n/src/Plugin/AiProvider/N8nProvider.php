<?php

declare(strict_types=1);

namespace Drupal\ai_provider_n8n\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\n8n\N8nClient;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Exposes n8n chat agents to Drupal as AI models.
 *
 * Three things here are load-bearing; the rest is plumbing.
 *
 * 1. We support `chat` only and declare NO capabilities. That alone keeps n8n out
 *    of ai_agents, CKEditor AI and field automators, which ask for capability-
 *    filtered pseudo-operations. It is not a checkbox — it is what the plugin is.
 * 2. n8n owns the brain, so we ignore the system prompt and configuration Drupal
 *    hands us.
 * 3. The connection belongs to the base `n8n` module so a future n8n_webform can
 *    share it — hence the getConfig() override.
 *
 * @see README.md#why-n8n-is-deliberately-absent-from-the-agent-dropdown
 * @see README.md#settings-that-intentionally-do-nothing
 * @see features/agent-exclusion.feature
 */
#[AiProvider(
  id: 'n8n',
  label: new TranslatableMarkup('n8n'),
)]
class N8nProvider extends AiProviderClientBase implements ChatInterface {

  /**
   * The tag prefix that carries the assistant's thread key.
   *
   * On the current (agent-backed) assistant pipeline, AiAgentEntityWrapper
   * tags every provider call with `ai_agents_thread_<key>`, where <key> is the
   * assistant runner's thread key — stable per user, per assistant, per
   * browser session. That key becomes n8n's sessionId, so the workflow's
   * memory node owns the conversation and Drupal stores nothing.
   *
   * NOT `ai_assistant_thread_` — that tag only exists on the legacy
   * (non-agent) path, which this module does not support.
   */
  protected const THREAD_TAG_PREFIX = 'ai_agents_thread_';

  /**
   * Reported when only n8n knows the real limit, which is always.
   */
  protected const UNKNOWN_TOKEN_LIMIT = 128000;

  /**
   * The n8n client, from the base module.
   *
   * @var \Drupal\n8n\N8nClientInterface
   */
  protected $client;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->client = $container->get('n8n.client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * Without this the base class would look for `ai_provider_n8n.settings`, which
   * does not exist — the connection is the base module's.
   */
  public function getConfig(): ImmutableConfig {
    return $this->configFactory->get(N8nClient::CONFIG_NAME);
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return ['chat'];
  }

  /**
   * {@inheritdoc}
   *
   * Note that getSupportedCapabilities() is deliberately NOT overridden: the base
   * class returns an empty array, and that IS the exclusion mechanism. Adding a
   * capability there is the single change that would let n8n be picked as an
   * agent brain and quietly misbehave.
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    // A caller asking for a capability wants a raw model. We are not one.
    if (!empty($capabilities)) {
      return [];
    }
    if ($operation_type !== NULL && !in_array($operation_type, $this->getSupportedOperationTypes(), TRUE)) {
      return [];
    }
    if (!$this->client->isConfigured()) {
      return [];
    }

    $models = [];
    foreach ($this->client->listChatWorkflows() as $workflow_id => $info) {
      $models[$workflow_id] = $info['label'];
    }

    return $models;
  }

  /**
   * {@inheritdoc}
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    if (!empty($capabilities)) {
      return FALSE;
    }
    if (!$this->client->isConfigured()) {
      return FALSE;
    }
    if ($operation_type !== NULL) {
      return in_array($operation_type, $this->getSupportedOperationTypes(), TRUE);
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    // The model lives in n8n; nothing here for Drupal to configure.
    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthentication(mixed $authentication): void {
    // Deliberate no-op: the shared client resolves the key through the Key module
    // at call time, and this class must never hold a raw credential.
  }

  /**
   * {@inheritdoc}
   *
   * Only the newest user message travels: the workflow's memory node already
   * holds the history, keyed by the session id, and replaying Drupal's copy
   * would make the agent see every message twice. The system prompt Drupal
   * builds is deliberately dropped — the n8n agent has its own.
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    $text = $this->client->chatSend(
      $model_id,
      $this->sessionIdFromTags($tags),
      $this->lastUserMessage($input),
      ['source' => 'drupal'],
    );

    $message = new ChatMessage('assistant', $text);
    return new ChatOutput($message, $text, []);
  }

  /**
   * The n8n session id for this conversation.
   *
   * Falls back to a per-call id when no thread tag is present (drush, the API
   * explorer): the reply still works, it just doesn't thread.
   */
  protected function sessionIdFromTags(array $tags): string {
    foreach ($tags as $tag) {
      if (str_starts_with($tag, self::THREAD_TAG_PREFIX)) {
        return substr($tag, strlen(self::THREAD_TAG_PREFIX));
      }
    }

    return uniqid('drupal-oneshot-', TRUE);
  }

  /**
   * The newest user message out of whatever shape the caller sent.
   */
  protected function lastUserMessage(array|string|ChatInput $input): string {
    if (is_string($input)) {
      return $input;
    }
    $messages = $input instanceof ChatInput ? $input->getMessages() : $input;
    for ($i = count($messages) - 1; $i >= 0; $i--) {
      $message = $messages[$i];
      if ($message instanceof ChatMessage && $message->getRole() === 'user') {
        return $message->getText();
      }
    }

    throw new \RuntimeException('No user message to send to n8n.');
  }

  /**
   * {@inheritdoc}
   */
  public function getMaxInputTokens(string $model_id): int {
    // n8n owns the model, so Drupal cannot know its context window. A permissive
    // bound beats a fake-precise one: the agent rejects an over-long message, not us.
    return self::UNKNOWN_TOKEN_LIMIT;
  }

  /**
   * {@inheritdoc}
   */
  public function getMaxOutputTokens(string $model_id): int {
    return self::UNKNOWN_TOKEN_LIMIT;
  }

}
