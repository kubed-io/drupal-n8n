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
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Exposes n8n chat agents to Drupal as AI models.
 *
 * The whole idea in one paragraph: each n8n workflow that starts with a Chat
 * Trigger is presented to Drupal as a "model". Picking your agent in an AI
 * Assistant is then the same gesture as picking gpt-4o, and every surface that
 * already speaks to a provider works unchanged.
 *
 * Three things about this class are load-bearing. Read them before editing:
 *
 * 1. We support the `chat` operation and NOTHING else, and we declare NO
 *    capabilities. That is what keeps n8n out of ai_agents, the CKEditor AI
 *    plugins, and field automators — those ask for the `chat_with_tools` /
 *    `chat_with_complex_json` pseudo-operations, which resolve to `chat` filtered
 *    by an AiModelCapability. An n8n agent already did its own tool calling;
 *    handing it to something that wants to drive a raw model means two agents
 *    fighting over one conversation.
 *
 * 2. n8n owns the brain. The model, the system prompt, the memory and the tools
 *    all live in the n8n workflow. We deliberately IGNORE the system prompt and
 *    the configuration Drupal hands us — see README, "Settings that
 *    intentionally do nothing".
 *
 * 3. The connection is NOT ours. It belongs to the base `n8n` module so the
 *    webform submodule can share it, which is why getConfig() is overridden.
 *
 * @todo Phase 2 — replace the placeholder model list and canned reply with the
 *   real n8n client. This class is currently the Phase 1 "hello world" skeleton:
 *   it proves the plugin is discovered, appears for assistants, is absent for
 *   agents, and renders through the chat block. See
 *   saga/Chapter_1_Packing_the_Van.md, Phase 1.
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
   * The n8n client, from the base module.
   *
   * @var \Drupal\n8n\N8nClient
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
   * The connection lives in the base `n8n` module, not here, because the webform
   * submodule shares it. Without this override the base class would look for
   * `ai_provider_n8n.settings`, which does not exist.
   */
  public function getConfig(): ImmutableConfig {
    return $this->configFactory->get('n8n.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    // Chat, and only chat. See the class docblock, point 1.
    return ['chat'];
  }

  /**
   * {@inheritdoc}
   *
   * NOTE: getSupportedCapabilities() is deliberately NOT overridden. The base
   * class returns an empty array, which is exactly right — an n8n agent is not a
   * raw model and offers Drupal no tools, no vision, no JSON mode. Do not
   * "helpfully" add capabilities here: that is the single change that would let
   * n8n be selected as an agent brain and quietly misbehave.
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    // Any caller asking for a capability wants a raw model. We are not one.
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
    // Same contract as getConfiguredModels(): capabilities mean "raw model".
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
    // The model lives in n8n; there is nothing for Drupal to configure.
    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthentication(mixed $authentication): void {
    // The API key is resolved through the Key module by the shared client at call
    // time, so there is nothing to set here and nothing to hold. Leaving this a
    // no-op is deliberate: this class must never hold a raw credential.
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
    // n8n owns the model, so Drupal cannot know its context window. Report a
    // permissive bound rather than a fake-precise one: the agent, not Drupal, is
    // what will reject an over-long message.
    return 128000;
  }

  /**
   * {@inheritdoc}
   */
  public function getMaxOutputTokens(string $model_id): int {
    // See getMaxInputTokens().
    return 128000;
  }

}
