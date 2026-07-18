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
   * The entity type manager, for reading an assistant's clean instructions.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->client = $container->get('n8n.client');
    $instance->entityTypeManager = $container->get('entity_type.manager');
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
   * Only the newest user message travels as the conversation: the workflow's
   * memory node already holds the history, keyed by the session id, and
   * replaying Drupal's copy would make the agent see every message twice.
   *
   * Everything else Drupal knows rides in METADATA — the Drupal signature.
   * The conversation stays clean, but every request that originates here is
   * identifiable and context-rich: a workflow can branch on
   * {{ $json.metadata.source }}, adapt to {{ $json.metadata.instructions }},
   * or ignore all of it and behave exactly as it does in n8n's own chat. The
   * assistant form's fields are optional context an agent MAY use, never
   * instructions Drupal enforces.
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    $text = $this->client->chatSend(
      $model_id,
      $this->sessionIdFromTags($tags),
      $this->lastUserMessage($input),
      $this->drupalSignature($tags),
    );

    $message = new ChatMessage('assistant', $text);
    return new ChatOutput($message, $text, []);
  }

  /**
   * The metadata every Drupal-originated chat message carries.
   *
   * @return array
   *   source: always "drupal" — how a workflow tells Drupal traffic from n8n's
   *     own chat UI. site: the site name. assistant: the machine id of the
   *     assistant's companion agent, so one workflow serving several assistants
   *     can tell its callers apart. instructions: the assistant's own
   *     instructions, CLEAN — never present when the assistant has none, so a
   *     zero-detail assistant is a pure passthrough. context_window: the
   *     assistant's History context length, so a memory node can size its window
   *     from Drupal — absent when unset. All offered as variables the workflow
   *     may use, never injected into the conversation.
   */
  protected function drupalSignature(array $tags): array {
    $signature = [
      'source' => 'drupal',
      'site' => (string) $this->configFactory->get('system.site')->get('name'),
    ];
    if ($agent_id = $this->assistantIdFromTags($tags)) {
      $signature['assistant'] = $agent_id;
      if ($instructions = $this->assistantInstructions($agent_id)) {
        $signature['instructions'] = $instructions;
      }
      if ($window = $this->assistantContextWindow($agent_id)) {
        $signature['context_window'] = $window;
      }
    }

    return $signature;
  }

  /**
   * The assistant's History context length, for a memory node to size itself.
   *
   * Drupal's assistant advanced settings carry a History context length. We
   * never replay history — n8n's memory owns it — but forwarding the number lets
   * a memory node set its Context Window Length from Drupal, so the admin's
   * Drupal setting drives how much the n8n agent remembers. The tag gives the
   * agent id, which for a form-created assistant equals the assistant id. Zero
   * or unset means the key is absent.
   */
  protected function assistantContextWindow(string $agent_id): int {
    if (!$this->entityTypeManager->hasDefinition('ai_assistant')) {
      return 0;
    }
    $assistant = $this->entityTypeManager->getStorage('ai_assistant')->load($agent_id);
    return $assistant ? (int) $assistant->get('history_context_length') : 0;
  }

  /**
   * The assistant's own instructions, clean of the agent loop's runtime framing.
   *
   * The system prompt the provider is handed at chat() time is the agent loop's
   * runtime prompt — the admin's instructions plus per-turn framing like "this
   * is the first time this agent has been run", which changes every turn and is
   * not the admin's intent. The agent entity's stored system_prompt is the
   * clean instructions the form saved, and that is what belongs in metadata.
   * Empty means the admin gave none: the instructions key is then absent.
   */
  protected function assistantInstructions(string $agent_id): string {
    if (!$this->entityTypeManager->hasDefinition('ai_agent')) {
      return '';
    }
    $agent = $this->entityTypeManager->getStorage('ai_agent')->load($agent_id);
    return $agent ? trim((string) $agent->get('system_prompt')) : '';
  }

  /**
   * The assistant's companion-agent id, read from the agent runner's tags.
   *
   * The runner tags every call `ai_agents_<id>` alongside the prompt, runner
   * and thread variants of the same prefix — the bare one is the identity.
   */
  protected function assistantIdFromTags(array $tags): string {
    foreach ($tags as $tag) {
      if (!str_starts_with($tag, 'ai_agents_') || $tag === 'ai_agents') {
        continue;
      }
      $candidate = substr($tag, strlen('ai_agents_'));
      foreach (['prompt_', 'runner_', 'thread_', 'caller_runner_'] as $variant) {
        if (str_starts_with($candidate, $variant)) {
          continue 2;
        }
      }
      return $candidate;
    }

    return '';
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
