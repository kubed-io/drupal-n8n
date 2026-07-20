<?php

declare(strict_types=1);

namespace Drupal\ai_provider_n8n\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\n8n\N8nClient;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

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
   * The current user, for the opt-in visitor identity in the signature.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The AI function-call plugin manager, for resolving agents to MCP tool ids.
   *
   * @var \Drupal\Core\Plugin\DefaultPluginManager
   */
  protected $functionCallManager;

  /**
   * The router that resolves a page path to a route, without access checks.
   *
   * @var \Symfony\Component\Routing\Matcher\RequestMatcherInterface
   */
  protected $router;

  /**
   * The request-scoped store of the page the chat box is on.
   *
   * @var \Drupal\ai_provider_n8n\N8nChatContext
   */
  protected $chatContext;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->client = $container->get('n8n.client');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUser = $container->get('current_user');
    $instance->functionCallManager = $container->get('plugin.manager.ai.function_calls');
    $instance->router = $container->get('router.no_access_checks');
    $instance->chatContext = $container->get('n8n.chat_context');
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
    $this->chatContext->recordProviderCall();
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
   *     can tell its callers apart. assistant_name: the assistant's human name
   *     (its Drupal label), so a workflow can greet or log by the name the admin
   *     sees — absent when no assistant entity backs the call. instructions: the
   *     assistant's own instructions, CLEAN — never present when the assistant
   *     has none, so a zero-detail assistant is a pure passthrough. context_window:
   *     the assistant's History context length, so a memory node can size its
   *     window from Drupal — absent when unset. All offered as variables the
   *     workflow may use, never injected into the conversation.
   */
  protected function drupalSignature(array $tags): array {
    $signature = [
      'source' => 'drupal',
      'site' => (string) $this->configFactory->get('system.site')->get('name'),
    ];
    if ($agent_id = $this->assistantIdFromTags($tags)) {
      $signature['assistant'] = $agent_id;
      if ($name = $this->assistantName($agent_id)) {
        $signature['assistant_name'] = $name;
      }
      if ($instructions = $this->assistantInstructions($agent_id)) {
        $signature['instructions'] = $instructions;
      }
      if ($window = $this->assistantContextWindow($agent_id)) {
        $signature['context_window'] = $window;
      }
      // Per-concern context, each with its own spec (see saga §8). A helper
      // returns its keys or [] — absent-when-empty is the contract everywhere —
      // so we merge with += and never overwrite the envelope.
      $signature += $this->userContextMetadata($agent_id);
      $signature += $this->agentsMetadata($agent_id);
    }
    $signature += $this->pageContextMetadata();

    return $signature;
  }

  /**
   * Loads a config entity by id, or NULL when its type or the entity is absent.
   *
   * The ai_assistant and ai_agent types live in optional submodules, so every
   * signature read must guard their existence before touching storage. This
   * centralises that guard: a caller asks for an entity and gets NULL whenever
   * there is nothing to read — the module missing, or the id not resolving —
   * which is exactly the "absent when nothing to say" default the signature wants.
   */
  private function loadEntity(string $entity_type_id, string $id): ?ConfigEntityInterface {
    if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return NULL;
    }
    $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($id);
    return $entity instanceof ConfigEntityInterface ? $entity : NULL;
  }

  /**
   * The visitor's identity and the assistant's access list. See user-context.feature.
   *
   * One opt-in — forward_user_context, default off — controls the whole "who is
   * asking" block, because it is all personal or access data: the visitor's name
   * and roles, and the roles the assistant itself is limited to. With the opt-in
   * off, none of it travels. With it on, all three keys are present together, and
   * allowed_roles is ALWAYS an array — the assistant's enabled roles, or an empty
   * list when it is open to everyone — so a workflow can read it without first
   * checking whether the key exists. Drupal has already enforced the access gate
   * before the message left, so allowed_roles rides as context, never as a gate.
   */
  protected function userContextMetadata(string $agent_id): array {
    $assistant = $this->loadEntity('ai_assistant', $agent_id);
    if (!$assistant) {
      return [];
    }
    $configuration = (array) $assistant->get('llm_configuration');
    if (empty($configuration['forward_user_context'])) {
      return [];
    }

    return [
      'user' => $this->currentUser->getAccountName(),
      'user_roles' => array_values($this->currentUser->getRoles()),
      // The enabled roles only — a role disabled in the map is a falsy value —
      // or [] when the assistant is open to everyone.
      'allowed_roles' => array_values(array_keys(array_filter((array) $assistant->get('roles')))),
    ];
  }

  /**
   * The Drupal agents this assistant may use, as MCP tool ids.
   *
   * The assistant's "Agents to use" selection is stored on its companion agent
   * as tools keyed ai_agents::ai_agent::<id>. We keep the enabled agent entries
   * and resolve each to the exact id n8n's MCP server would expose it under, so
   * a workflow can drop metadata.agents straight into an MCP Client Tool node's
   * "Tools to Include" and call those agents back over MCP with no glue.
   *
   * We NEVER run the agents here — the n8n agent is a passthrough that does its
   * own tool calling. The selection travels as data, never as a tool call, so
   * ticking agents cannot turn the one-call passthrough into two. Empty
   * selection means the agents key is absent.
   *
   * @see features/agents-metadata.feature
   */
  protected function agentsMetadata(string $agent_id): array {
    $agent = $this->loadEntity('ai_agent', $agent_id);
    if (!$agent) {
      return [];
    }
    $agents = [];
    foreach ((array) $agent->get('tools') as $plugin_id => $enabled) {
      if (!$enabled || !str_starts_with((string) $plugin_id, 'ai_agents::ai_agent::')) {
        continue;
      }
      if ($tool_id = $this->agentToolId((string) $plugin_id)) {
        $agents[] = $tool_id;
      }
    }

    return $agents ? ['agents' => $agents] : [];
  }

  /**
   * The MCP tool id for one selected agent, resolved exactly as drupal/mcp does.
   *
   * The function-call plugin id IS the stored tools key. We instantiate it and
   * take the name off its rendered function array — the same source drupal/mcp
   * reads — then prefix aif_ and sanitize with the identical rules its
   * McpPluginBase applies, so the id we emit is byte-for-byte the one n8n sees.
   * An unknown or unbuildable plugin yields '' and is dropped.
   */
  protected function agentToolId(string $plugin_id): string {
    if (!$this->functionCallManager->hasDefinition($plugin_id)) {
      return '';
    }
    try {
      $name = $this->functionCallManager->createInstance($plugin_id)
        ->normalize()
        ->renderFunctionArray()['name'] ?? '';
    }
    catch (\Throwable) {
      return '';
    }

    return $name === '' ? '' : 'aif_' . $this->sanitizeToolName($name);
  }

  /**
   * Sanitizes a tool name to n8n's id form, mirroring drupal/mcp exactly.
   *
   * Lowercase, non-[a-z0-9_] runs collapsed to a single underscore, trimmed of
   * leading and trailing underscores, and prefixed with an underscore if it
   * would otherwise start with a digit. This mirrors McpPluginBase so the id we
   * hand n8n matches the one the MCP server publishes.
   */
  protected function sanitizeToolName(string $tool_name): string {
    $name = strtolower($tool_name);
    $name = preg_replace('/[^a-z0-9_]+/', '_', $name);
    $name = trim($name, '_');
    if (preg_match('/^[0-9]/', $name)) {
      $name = '_' . $name;
    }

    return $name;
  }

  /**
   * The page the chat box is on: path, and entity when the page is a single one.
   *
   * The path arrives out of band — ChatContextSubscriber stashed it from the
   * assistant pipeline's context event earlier this same request, because the
   * provider cannot see the page directly at chat() time. When that path
   * resolves to exactly one content entity's canonical route, entity carries its
   * {type, id}, derived server-side from the path — so an agent can look up the
   * very node the visitor is reading, over MCP. On a listing, a view, the front
   * page, or an admin route, no single entity owns the page and entity is absent.
   * With no page context at all, the whole block is absent.
   *
   * @see \Drupal\ai_provider_n8n\EventSubscriber\ChatContextSubscriber
   * @see features/page-context.feature
   */
  protected function pageContextMetadata(): array {
    $path = $this->chatContext->getPath();
    if ($path === NULL || $path === '') {
      return [];
    }
    $meta = ['path' => $path];
    if ($entity = $this->entityFromPath($path)) {
      $meta['entity'] = $entity;
    }

    return $meta;
  }

  /**
   * The single content entity a page path resolves to, or [] when there is none.
   *
   * Only a canonical single-entity route (entity.<type>.canonical, its entity
   * upcast from the path) counts as "a page that IS one piece of content".
   * Listings, views, the front page, admin routes and edit forms all fall
   * through to []. The match runs without access checks — it is deriving a fact
   * about the URL, not authorising the visitor — and any non-match is [].
   */
  protected function entityFromPath(string $path): array {
    try {
      $match = $this->router->matchRequest(Request::create($path));
    }
    catch (\Throwable) {
      return [];
    }
    $route_name = $match['_route'] ?? '';
    if (!preg_match('/^entity\.([a-z0-9_]+)\.canonical$/', $route_name, $captured)) {
      return [];
    }
    $entity = $match[$captured[1]] ?? NULL;
    if (!$entity instanceof ContentEntityInterface) {
      return [];
    }

    return [
      'type' => $entity->getEntityTypeId(),
      'id' => (string) $entity->id(),
    ];
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
    $assistant = $this->loadEntity('ai_assistant', $agent_id);
    return $assistant ? (int) $assistant->get('history_context_length') : 0;
  }

  /**
   * The assistant's human name, the label the admin gave it in Drupal.
   *
   * The tag carries the agent id, which for a form-created assistant equals the
   * assistant id, so we load the assistant entity and forward its label. This is
   * the display name an admin sees, distinct from the machine id in `assistant` —
   * a workflow can greet or log by it. Absent when no assistant entity backs the
   * call (for example the bare-transport path, which has only the tag).
   */
  protected function assistantName(string $agent_id): string {
    $assistant = $this->loadEntity('ai_assistant', $agent_id);
    return $assistant ? trim((string) $assistant->label()) : '';
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
    $agent = $this->loadEntity('ai_agent', $agent_id);
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
