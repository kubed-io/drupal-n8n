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
 *
 * @todo Phase 2 — swap the placeholder model list and canned reply for the real
 *   client. This is the Phase 1 skeleton: it proves the plugin is discovered,
 *   offered to assistants and hidden from agents.
 */
#[AiProvider(
  id: 'n8n',
  label: new TranslatableMarkup('n8n'),
)]
class N8nProvider extends AiProviderClientBase implements ChatInterface {

  /**
   * The placeholder model used until the real client lands in Phase 2.
   */
  protected const PLACEHOLDER_MODEL = 'hello-world';

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
   * getSupportedCapabilities() is deliberately NOT overridden: the base class
   * returns an empty array, which is the exclusion mechanism. Adding a capability
   * here is the single change that would let n8n be picked as an agent brain and
   * quietly misbehave.
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

    // @todo Phase 2 — list workflows from n8n and keep those with a chat trigger.
    return [self::PLACEHOLDER_MODEL => 'Hello World (placeholder)'];
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
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    // @todo Phase 2 — resolve the workflow's chat webhook, POST
    //   {action, sessionId, chatInput}, and return the agent's {output}. The
    //   session id comes from the `ai_assistant_thread_` tag in $tags; only the
    //   newest message is sent, because the agent's memory node holds the rest.
    $text = sprintf('Hello from the van. The n8n provider is wired up, but has no agent behind it yet (model: %s).', $model_id);

    $message = new ChatMessage('assistant', $text);
    return new ChatOutput($message, $text, []);
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
