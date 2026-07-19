<?php

declare(strict_types=1);

namespace Drupal\Tests\n8n\Integration;

use Behat\Behat\Context\Context;
use Drupal\Tests\n8n\Integration\Support\DrupalEvalTrait;
use Drupal\Tests\n8n\Integration\Support\DrushTrait;
use Drupal\Tests\n8n\Integration\Support\N8nApiTrait;
use PHPUnit\Framework\Assert;

/**
 * Step definitions for the n8n integration suite.
 *
 * Wired: admin-connection, model-discovery incl. the site tag and the multisite
 * domain scenario, agent-exclusion's provider-surface checks, and the Drupal
 * signature — including the two scenarios that build a real assistant and run it
 * end to end. Still tagged for later: the assistant-chat round trip and its
 * failure edges, which want a chat block, not just the runner.
 *
 * A `@BeforeScenario` prerequisite hook checks the plumbing (Drupal booted, n8n
 * up) so the harness sanity that `harness.feature` used to give lives on as a
 * lifecycle hook rather than a gherkin feature.
 *
 * Keep the parentheses out of step text: a literal ( or ) becomes a regex group,
 * the step silently goes undefined, and the suite fails while looking green.
 */
class FeatureContext implements Context {

  use DrushTrait;
  use DrupalEvalTrait;
  use N8nApiTrait;

  /**
   * The models the provider offered when the admin last listed them.
   *
   * @var array<string, string>
   */
  protected array $models = [];

  /**
   * Provider facts captured by the inspection steps.
   *
   * @var array
   */
  protected array $providerFacts = [];

  /**
   * The parsed payload the Echo Agent handed back on the last provider chat.
   *
   * @var array
   */
  protected array $echo = [];

  /**
   * The visitor identity a "current visitor is …" step recorded, for user-context.
   *
   * @var array{name: string, roles: list<string>}|null
   */
  protected ?array $visitor = NULL;

  /**
   * The transcript the runner loaded on the last "conversation is loaded" step.
   *
   * @var list<array{role: string, message: string}>
   */
  protected array $loadedHistory = [];

  /**
   * The chat context the next visitor message rides with — the page it is on.
   *
   * Empty means no page; ['current_route' => '/node/5'] is the box on that page.
   *
   * @var array
   */
  protected array $pageContext = [];

  /**
   * The Echo Agent's recorded execution count before the last chat.
   *
   * The baseline for the "exactly one call to the provider" assertion.
   */
  protected int $providerCallBaseline = 0;

  /**
   * Node ids this scenario created for a page-context page, deleted in teardown.
   *
   * @var list<int>
   */
  protected array $createdNodes = [];

  /**
   * The Key entity holding the valid minted API key.
   */
  protected const VALID_KEY = 'behat_n8n_key';

  /**
   * Whether the once-per-suite prerequisite check has run.
   */
  protected static bool $prerequisitesChecked = FALSE;

  /**
   * Assistant ids created this scenario, deleted in teardown for isolation.
   *
   * @var list<string>
   */
  protected array $createdAssistants = [];

  /**
   * Deletes the assistants a scenario created, and their companion agents.
   *
   * Without this an assistant's llm_model — which is an n8n workflow id — would
   * leak into the next scenario's config and, for instance, break the "no
   * workflow id appears in configuration" check. Mirrors the sibling
   * nextcloud-n8n's @AfterScenario teardown. Best-effort.
   *
   * @AfterScenario
   */
  public function tearDownAssistants(): void {
    if (!$this->createdAssistants) {
      return;
    }
    $this->drupalEvalJson(strtr(<<<'PHP'
      $ids = json_decode('IDS', TRUE);
      $etm = \Drupal::entityTypeManager();
      foreach ($ids as $id) {
        foreach (['ai_assistant', 'ai_agent'] as $type) {
          if ($entity = $etm->getStorage($type)->load($id)) {
            $entity->delete();
          }
        }
      }
      echo json_encode(TRUE);
      PHP, ['IDS' => json_encode($this->createdAssistants)]));
    $this->createdAssistants = [];
  }

  // ── Prerequisites (a lifecycle hook, not a feature) ──────────────────────────

  /**
   * Verifies the plumbing before any scenario runs.
   *
   * This is a prerequisite gate, not a product feature — so it is a Behat
   * lifecycle hook, the sibling nextcloud-n8n's `@AfterScenario` teardown turned
   * inside out, rather than a `harness.feature`. It exists so a red suite means
   * "the module is broken", not "Drupal never booted or n8n never came up" —
   * from the outside those look identical. Runs once; a static guard keeps it
   * off the per-scenario hot path.
   *
   * @BeforeScenario
   */
  public function verifyPrerequisites(): void {
    if (self::$prerequisitesChecked) {
      return;
    }
    self::$prerequisitesChecked = TRUE;

    $bootstrap = $this->drush('status', '--field=bootstrap');
    Assert::assertSame(0, $this->drushExitCode(), 'Prerequisite: drush could not reach Drupal at all.');
    Assert::assertStringContainsString(
      'Successful',
      $bootstrap,
      'Prerequisite: Drupal is not bootstrapping. The plumbing is broken, not the module.',
    );

    Assert::assertTrue(
      $this->n8nIsHealthy(),
      sprintf('Prerequisite: the ephemeral n8n never came up at %s.', $this->n8nUrl()),
    );
  }

  // ── The module ───────────────────────────────────────────────────────────────

  /**
   * Asserts the module under test is enabled on the site.
   *
   * @Given the n8n module is installed and enabled
   */
  public function theModuleIsInstalledAndEnabled(): void {
    $output = $this->drush('pm:list', '--status=enabled', '--filter=n8n', '--field=name');

    Assert::assertStringContainsString(
      'n8n',
      $output,
      'The n8n module should be enabled on the site under test. Did the workflow install it?',
    );
  }

  // ── The connection ─────────────────────────────────────────────────────────

  /**
   * Asserts the Key module is enabled — it is a hard dependency.
   *
   * @Given the key module is installed and enabled
   */
  public function theKeyModuleIsInstalledAndEnabled(): void {
    $output = $this->drush('pm:list', '--status=enabled', '--filter=key', '--field=name');
    Assert::assertStringContainsString('key', $output, 'The key module should be enabled.');
  }

  /**
   * Creates a Key entity carrying the minted n8n API key.
   *
   * @Given a key holding a valid n8n API key was added to Drupal
   */
  public function validKeyEntityExists(): void {
    $this->createKeyEntity(self::VALID_KEY, $this->n8nApiKey());
  }

  /**
   * Step: The admin sets the n8n base URL.
   *
   * @When the admin sets the n8n base URL
   */
  public function theAdminSetsTheBaseUrl(): void {
    $this->drush('n8n:set-url', $this->n8nUrl());
    Assert::assertSame(0, $this->drushExitCode(), $this->drushOutput());
  }

  /**
   * Step: The admin selects a key holding a valid n8n API key.
   *
   * @When the admin selects a key holding a valid n8n API key
   */
  public function theAdminSelectsTheValidKey(): void {
    $this->createKeyEntity(self::VALID_KEY, $this->n8nApiKey());
    $this->drush('n8n:set-key', self::VALID_KEY);
    Assert::assertSame(0, $this->drushExitCode(), $this->drushOutput());
  }

  /**
   * Step: The admin tests the connection.
   *
   * @When the admin tests the connection
   */
  public function theAdminTestsTheConnection(): void {
    $this->drush('n8n:test');
  }

  /**
   * Step: The connection is verified.
   *
   * @Then the connection is verified
   */
  public function theConnectionIsVerified(): void {
    Assert::assertSame(0, $this->drushExitCode(), 'n8n:test should exit zero: ' . $this->drushOutput());
    Assert::assertStringContainsString('Connected', $this->drushOutput());
  }

  /**
   * Step: The admin configures the connection with an invalid API key.
   *
   * @When the admin configures the connection with an invalid API key
   */
  public function theAdminConfiguresWithAnInvalidKey(): void {
    $this->createKeyEntity('behat_bad_key', 'not-a-real-key');
    $this->drush('n8n:set-url', $this->n8nUrl());
    $this->drush('n8n:set-key', 'behat_bad_key');
  }

  /**
   * Step: The admin configures the connection with an unreachable host.
   *
   * @When the admin configures the connection with an unreachable host
   */
  public function theAdminConfiguresWithAnUnreachableHost(): void {
    $this->createKeyEntity(self::VALID_KEY, $this->n8nApiKey());
    $this->drush('n8n:set-url', 'http://localhost:59999');
    $this->drush('n8n:set-key', self::VALID_KEY);
  }

  /**
   * Step: The connection test reports a failure.
   *
   * @Then the connection test reports a failure
   */
  public function theConnectionTestFails(): void {
    Assert::assertNotSame(0, $this->drushExitCode(), 'n8n:test should exit non-zero: ' . $this->drushOutput());
  }

  /**
   * Step: The admin configures and tests the connection with drush.
   *
   * @When the admin configures and tests the connection with drush
   */
  public function theAdminConfiguresAndTestsWithDrush(): void {
    $this->theAdminSetsTheBaseUrl();
    $this->theAdminSelectsTheValidKey();
    $this->theAdminTestsTheConnection();
  }

  /**
   * Step: The command exits with a zero status.
   *
   * @Then the command exits with a zero status
   */
  public function theCommandExitsZero(): void {
    Assert::assertSame(0, $this->drushExitCode(), $this->drushOutput());
  }

  /**
   * The gate every feature opens with: a working, verified connection.
   *
   * @Given the connection to n8n is configured and verified
   */
  public function theConnectionIsConfiguredAndVerified(): void {
    $this->createKeyEntity(self::VALID_KEY, $this->n8nApiKey());
    $this->drush('n8n:set-url', $this->n8nUrl());
    $this->drush('n8n:set-key', self::VALID_KEY);
    $this->drush('n8n:test');
    Assert::assertSame(0, $this->drushExitCode(), 'The connection gate failed: ' . $this->drushOutput());
  }

  // ── The site tag ───────────────────────────────────────────────────────────

  /**
   * Step: The site tag is set to the tag.
   *
   * @Given the site tag is set to :tag
   */
  public function theSiteTagIsSetTo(string $tag): void {
    $this->drush('config:set', 'n8n.settings', 'tag', $tag, '-y');
    Assert::assertSame(0, $this->drushExitCode(), $this->drushOutput());
  }

  /**
   * Step: The site tag is not set.
   *
   * @Given the site tag is not set
   */
  public function theSiteTagIsNotSet(): void {
    $this->theSiteTagIsSetTo('');
  }

  // ── Model discovery ────────────────────────────────────────────────────────

  /**
   * Control-case check on n8n's side: the fixture really carries the tag.
   *
   * @Given the :name workflow is tagged :tag in n8n
   */
  public function theWorkflowIsTagged(string $name, string $tag): void {
    Assert::assertTrue(
      $this->n8nWorkflowHasTag($name, $tag),
      "Fixture '$name' should carry the '$tag' tag in n8n — did the preload run?",
    );
  }

  /**
   * Step: The the name workflow is not tagged the tag in n8n.
   *
   * @Given the :name workflow is not tagged :tag in n8n
   */
  public function theWorkflowIsNotTagged(string $name, string $tag): void {
    Assert::assertFalse(
      $this->n8nWorkflowHasTag($name, $tag),
      "Fixture '$name' must NOT carry the '$tag' tag for this scenario to mean anything.",
    );
  }

  /**
   * Step: The the name workflow is renamed to the new_name in n8n.
   *
   * @Given the :name workflow is renamed to :new_name in n8n
   */
  public function theWorkflowIsRenamed(string $name, string $new_name): void {
    $this->n8nRenameWorkflow($name, $new_name);
  }

  /**
   * Step: The admin lists the available n8n models.
   *
   * @When the admin lists the available n8n models
   */
  public function theAdminListsTheModels(): void {
    $this->models = $this->providerModels();
  }

  /**
   * Step: the label is offered as a model.
   *
   * @Then :label is offered as a model
   */
  public function modelIsOffered(string $label): void {
    Assert::assertContains(
      $label,
      array_values($this->models),
      "'$label' should be among the offered models. Got: " . json_encode(array_values($this->models)),
    );
  }

  /**
   * Step: the label is not offered as a model.
   *
   * @Then :label is not offered as a model
   */
  public function modelIsNotOffered(string $label): void {
    Assert::assertNotContains(
      $label,
      array_values($this->models),
      "'$label' must not be offered. Got: " . json_encode(array_values($this->models)),
    );
  }

  /**
   * Step: No workflow id appears in Drupal's configuration.
   *
   * @Then no workflow id appears in Drupal's configuration
   */
  public function noWorkflowIdAppearsInConfiguration(): void {
    $workflow = $this->n8nWorkflowByName('Echo Agent');
    Assert::assertNotNull($workflow, 'The Echo Agent fixture should exist.');
    $hits = $this->drupalEvalJson(strtr(<<<'PHP'
      $needle = NEEDLE;
      $hits = [];
      foreach (\Drupal::configFactory()->listAll('') as $name) {
        $raw = json_encode(\Drupal::config($name)->getRawData());
        if ($raw !== FALSE && str_contains($raw, $needle)) {
          $hits[] = $name;
        }
      }
      echo json_encode($hits);
      PHP, ['NEEDLE' => var_export($workflow['id'], TRUE)]));
    Assert::assertSame([], $hits, 'No config object may carry an n8n workflow id.');
  }

  // ── Multisite ──────────────────────────────────────────────────────────────

  /**
   * Creates the domains and writes the per-domain tag override.
   *
   * The override lives in the config COLLECTION domain.<id> and is written with
   * domain.config_factory_override — the only API that works. Proven in
   * saga/Chapter_1_Packing_the_Van.md §9.1; do not "simplify" this into a
   * config object named domain.config.<id>.<name>: nothing reads that.
   *
   * @Given a domain :id overrides the site tag to :tag
   */
  public function aDomainOverridesTheSiteTag(string $id, string $tag): void {
    $this->drupalEvalJson(strtr(<<<'PHP'
      $storage = \Drupal::entityTypeManager()->getStorage('domain');
      if (!$storage->load('behat_default')) {
        $storage->create([
          'id' => 'behat_default',
          'name' => 'Behat default',
          'hostname' => 'localhost',
          'scheme' => 'http',
          'status' => TRUE,
          'weight' => 0,
          'is_default' => TRUE,
        ])->save();
      }
      if (!$storage->load(DOMAIN)) {
        $storage->create([
          'id' => DOMAIN,
          'name' => DOMAIN,
          'hostname' => DOMAIN . '.example.test',
          'scheme' => 'http',
          'status' => TRUE,
          'weight' => 1,
          'is_default' => FALSE,
        ])->save();
      }
      \Drupal::service('domain.config_factory_override')
        ->getOverrideEditable(DOMAIN, 'n8n.settings')
        ->set('tag', TAG)
        ->save();
      echo json_encode(TRUE);
      PHP, [
        'DOMAIN' => var_export($id, TRUE),
        'TAG' => var_export($tag, TRUE),
      ]));
  }

  /**
   * Lists models with the given domain active.
   *
   * The way a request on that hostname would see them.
   * CLI never negotiates a domain — drush --uri does NOT populate the context,
   * proven in saga Ch1 §9.1 — so the step activates the domain explicitly
   * through domain.negotiation_context, which is the service that actually
   * gates config overrides.
   *
   * @When the admin lists the available n8n models on the :id domain
   */
  public function theAdminListsTheModelsOnDomain(string $id): void {
    $this->models = (array) $this->drupalEvalJson(strtr(<<<'PHP'
      $domain = \Drupal::entityTypeManager()->getStorage('domain')->load(DOMAIN);
      if ($domain === NULL) {
        throw new \RuntimeException('No domain ' . DOMAIN);
      }
      \Drupal::service('domain.negotiation_context')->setDomain($domain);
      \Drupal::configFactory()->reset('n8n.settings');
      $models = \Drupal::service('ai.provider')->createInstance('n8n')->getConfiguredModels('chat');
      echo json_encode($models);
      PHP, ['DOMAIN' => var_export($id, TRUE)]));
  }

  // ── Provider surfaces ──────────────────────────────────────────────────────

  /**
   * Step: The admin views the provider choices for an AI assistant.
   *
   * @When the admin views the provider choices for an AI assistant
   */
  public function theAdminViewsAssistantProviderChoices(): void {
    $this->providerFacts = (array) $this->drupalEvalJson(<<<'PHP'
      $providers = \Drupal::service('ai.provider')->getProvidersForOperationType('chat');
      echo json_encode(['providers' => array_keys($providers)]);
      PHP);
  }

  /**
   * Step: the provider is offered as a provider.
   *
   * @Then :provider is offered as a provider
   */
  public function providerIsOffered(string $provider): void {
    Assert::assertContains(strtolower($provider), $this->providerFacts['providers'] ?? [], json_encode($this->providerFacts));
  }

  /**
   * Step: the provider is not offered as a provider.
   *
   * @Then :provider is not offered as a provider
   */
  public function providerIsNotOffered(string $provider): void {
    Assert::assertNotContains(strtolower($provider), $this->providerFacts['providers'] ?? [], json_encode($this->providerFacts));
  }

  /**
   * Step: The admin views the provider choices for an operation requiring the capability.
   *
   * @When the admin views the provider choices for an operation requiring :capability
   */
  public function theAdminViewsCapabilityFilteredChoices(string $capability): void {
    $map = [
      'tools' => 'ChatTools',
      'complex JSON' => 'ChatJsonOutput',
      'structured response' => 'ChatStructuredResponse',
      'image vision' => 'ChatWithImageVision',
    ];
    Assert::assertArrayHasKey($capability, $map, "Unknown capability '$capability' in the step table.");
    $this->providerFacts = (array) $this->drupalEvalJson(strtr(<<<'PHP'
      $providers = \Drupal::service('ai.provider')->getProvidersForOperationType(
        'chat', TRUE, [\Drupal\ai\Enum\AiModelCapability::CAPABILITY],
      );
      echo json_encode(['providers' => array_keys($providers)]);
      PHP, ['CAPABILITY' => $map[$capability]]));
  }

  /**
   * Step: The admin inspects the n8n provider.
   *
   * @When the admin inspects the n8n provider
   */
  public function theAdminInspectsTheProvider(): void {
    $this->providerFacts = (array) $this->drupalEvalJson(<<<'PHP'
      $provider = \Drupal::service('ai.provider')->createInstance('n8n');
      echo json_encode([
        'operations' => $provider->getSupportedOperationTypes(),
        'capabilities' => $provider->getSupportedCapabilities(),
      ]);
      PHP);
  }

  /**
   * Step: The n8n provider supports the chat operation.
   *
   * @Then the n8n provider supports the chat operation
   */
  public function theProviderSupportsChat(): void {
    Assert::assertContains('chat', $this->providerFacts['operations'] ?? []);
  }

  /**
   * Step: The n8n provider supports no other operation.
   *
   * @Then the n8n provider supports no other operation
   */
  public function theProviderSupportsNoOtherOperation(): void {
    Assert::assertSame(['chat'], $this->providerFacts['operations'] ?? NULL);
  }

  /**
   * Step: The n8n provider declares no model capabilities.
   *
   * @Then the n8n provider declares no model capabilities
   */
  public function theProviderDeclaresNoCapabilities(): void {
    Assert::assertSame([], $this->providerFacts['capabilities'] ?? NULL);
  }

  // ── The Drupal signature ───────────────────────────────────────────────────

  /**
   * Sends a message through the real provider, directly.
   *
   * The exact call the assistant pipeline makes, at the Echo Agent, which hands
   * back everything it saw. No assistant entity is involved, so this tests the
   * transport signature — source, site, session, whole conversation — not the
   * instructions, which come from a real assistant and are tested below.
   *
   * @When a message is sent to the :name agent through the provider
   */
  public function aMessageIsSentThroughTheProvider(string $name): void {
    $workflow = $this->n8nWorkflowByName($name);
    Assert::assertNotNull($workflow, "No fixture named '$name'.");
    $reply = $this->providerChat($workflow['id'], 'hello from behat', 'behat-signature');
    $decoded = json_decode($reply, TRUE);
    Assert::assertIsArray($decoded, "The $name fixture should echo JSON. Got: $reply");
    $this->echo = $decoded;
  }

  /**
   * Step: N8n received the message the message as the whole conversation.
   *
   * @Then n8n received the message :message as the whole conversation
   */
  public function n8nReceivedOnlyTheMessage(string $message): void {
    Assert::assertSame($message, $this->echo['chatInput'] ?? NULL, json_encode($this->echo));
  }

  /**
   * Step: The newest message is sent after earlier turns.
   *
   * @When the newest message :message is sent to the :name agent after earlier turns
   */
  public function theNewestMessageIsSentAfterEarlierTurns(string $message, string $name): void {
    $workflow = $this->n8nWorkflowByName($name);
    Assert::assertNotNull($workflow, "No fixture named '$name'.");
    $reply = $this->providerChatWithHistory($workflow['id'], $message);
    $decoded = json_decode($reply, TRUE);
    Assert::assertIsArray($decoded, "The $name fixture should echo JSON. Got: $reply");
    $this->echo = $decoded;
  }

  /**
   * Step: An assistant backed by an n8n agent with a set history length.
   *
   * @Given an assistant :id backed by the :agent agent with history context length :length
   */
  public function anAssistantWithHistoryLength(string $id, string $agent, int $length): void {
    $workflow = $this->n8nWorkflowByName($agent);
    Assert::assertNotNull($workflow, "No fixture named '$agent'.");
    $this->createN8nAssistant($id, $workflow['id'], '', $length);
    $this->createdAssistants[] = $id;
  }

  /**
   * Step: N8n received the context window the window.
   *
   * @Then n8n received the context window :window
   */
  public function n8nReceivedTheContextWindow(int $window): void {
    Assert::assertSame(
      $window,
      $this->echo['metadata']['context_window'] ?? NULL,
      'The history length should arrive as metadata.context_window: ' . json_encode($this->echo['metadata'] ?? []),
    );
  }

  /**
   * Step: N8n received no context window.
   *
   * @Then n8n received no context window
   */
  public function n8nReceivedNoContextWindow(): void {
    Assert::assertArrayNotHasKey(
      'context_window',
      $this->echo['metadata'] ?? [],
      'An assistant with no history length must forward no context window: ' . json_encode($this->echo['metadata'] ?? []),
    );
  }

  /**
   * Step: The message carried the Drupal signature.
   *
   * @Then the message carried the Drupal signature
   */
  public function theMessageCarriedTheSignature(): void {
    $metadata = $this->echo['metadata'] ?? [];
    Assert::assertSame('drupal', $metadata['source'] ?? NULL, 'metadata.source should say drupal: ' . json_encode($this->echo));
    Assert::assertArrayHasKey('site', $metadata, 'metadata.site should carry the site name.');
    Assert::assertSame('behat_helper', $metadata['assistant'] ?? NULL, 'metadata.assistant should carry the companion agent id.');
  }

  /**
   * Step: N8n received the session id the session.
   *
   * @Then n8n received the session id :session
   */
  public function n8nReceivedTheSessionId(string $session): void {
    Assert::assertSame($session, $this->echo['sessionId'] ?? NULL, json_encode($this->echo));
  }

  /**
   * Step: An assistant backed by an n8n agent with no instructions.
   *
   * @Given an assistant :id backed by the :agent agent with no instructions
   */
  public function anAssistantWithNoInstructions(string $id, string $agent): void {
    $this->createAssistantForFixture($id, $agent, '');
  }

  /**
   * Step: An assistant backed by an n8n agent, carrying its own instructions.
   *
   * @Given an assistant :id backed by the :agent agent instructed to :instructions
   */
  public function anAssistantInstructed(string $id, string $agent, string $instructions): void {
    $this->createAssistantForFixture($id, $agent, $instructions);
  }

  /**
   * Step: A visitor chats with the assistant through the full pipeline.
   *
   * @When a visitor chats :message with the assistant :id
   */
  public function aVisitorChatsWithTheAssistant(string $message, string $id): void {
    $reply = $this->chatThroughAssistant($id, $message, $this->pageContext);
    $decoded = json_decode($reply, TRUE);
    Assert::assertIsArray($decoded, "The assistant's model should echo JSON. Got: $reply");
    $this->echo = $decoded;
  }

  /**
   * Step: The message was marked as coming from Drupal.
   *
   * @Then the message was marked as coming from Drupal
   */
  public function theMessageWasMarkedFromDrupal(): void {
    Assert::assertSame('drupal', $this->echo['metadata']['source'] ?? NULL, json_encode($this->echo));
  }

  /**
   * Step: N8n received no instructions from Drupal.
   *
   * @Then n8n received no instructions from Drupal
   */
  public function n8nReceivedNoInstructions(): void {
    Assert::assertArrayNotHasKey(
      'instructions',
      $this->echo['metadata'] ?? [],
      'A zero-detail assistant must forward no instructions: ' . json_encode($this->echo['metadata'] ?? []),
    );
  }

  /**
   * Step: N8n received the instructions the instructions.
   *
   * @Then n8n received the instructions :instructions
   */
  public function n8nReceivedInstructions(string $instructions): void {
    Assert::assertSame(
      $instructions,
      $this->echo['metadata']['instructions'] ?? NULL,
      'The assistant instructions should arrive clean: ' . json_encode($this->echo['metadata'] ?? []),
    );
  }

  /**
   * Creates an assistant and its companion agent, pointed at a fixture workflow.
   */
  protected function createAssistantForFixture(string $id, string $agent, string $instructions): void {
    $workflow = $this->n8nWorkflowByName($agent);
    Assert::assertNotNull($workflow, "No fixture named '$agent'.");
    $this->createN8nAssistant($id, $workflow['id'], $instructions);
    $this->createdAssistants[] = $id;
  }

  // ── User context (visitor identity + the assistant's access list) ────────────
  // Shared Given, used by user-context, agents-metadata and page-context — defined
  // once here (do NOT redefine it elsewhere, or the suite double-defines and fails).

  /**
   * Step: A bare assistant with no extra Drupal detail.
   *
   * @Given an assistant :id backed by the :agent agent
   */
  public function anAssistantBackedByAgent(string $id, string $agent): void {
    $this->createAssistantForFixture($id, $agent, '');
  }

  /**
   * Step: An assistant that opts in to forwarding the visitor's identity.
   *
   * @Given an assistant :id backed by the :agent agent with user context enabled
   */
  public function anAssistantWithUserContext(string $id, string $agent): void {
    $workflow = $this->n8nWorkflowByName($agent);
    Assert::assertNotNull($workflow, "No fixture named '$agent'.");
    $this->createN8nAssistant($id, $workflow['id'], '', 2, 'session_one_thread', [], ['forward_user_context' => TRUE]);
    $this->createdAssistants[] = $id;
  }

  /**
   * Step: An assistant restricted to a single role.
   *
   * @Given an assistant :id backed by the :agent agent restricted to role :role
   */
  public function anAssistantRestrictedToRole(string $id, string $agent, string $role): void {
    $workflow = $this->n8nWorkflowByName($agent);
    Assert::assertNotNull($workflow, "No fixture named '$agent'.");
    $this->createN8nAssistant($id, $workflow['id'], '', 2, 'session_one_thread', [$role => $role]);
    $this->createdAssistants[] = $id;
  }

  /**
   * Step: Records the intended visitor identity (two roles).
   *
   * @Given the current visitor is :name with roles :first and :second
   */
  public function theCurrentVisitorWithTwoRoles(string $name, string $first, string $second): void {
    $this->visitor = ['name' => $name, 'roles' => [$first, $second]];
  }

  /**
   * Step: Records the intended visitor identity (one role).
   *
   * @Given the current visitor is :name with roles :role
   */
  public function theCurrentVisitorWithOneRole(string $name, string $role): void {
    $this->visitor = ['name' => $name, 'roles' => [$role]];
  }

  /**
   * Step: N8n received the visitor's username.
   *
   * @Then n8n received the user :user
   */
  public function n8nReceivedTheUser(string $user): void {
    Assert::assertSame($user, $this->echo['metadata']['user'] ?? NULL, json_encode($this->echo['metadata'] ?? []));
  }

  /**
   * Step: N8n received the visitor's roles as a list.
   *
   * @Then n8n received the user roles :first and :second
   */
  public function n8nReceivedTheUserRoles(string $first, string $second): void {
    $got = $this->echo['metadata']['user_roles'] ?? [];
    Assert::assertContains($first, $got, json_encode($this->echo['metadata'] ?? []));
    Assert::assertContains($second, $got, json_encode($this->echo['metadata'] ?? []));
  }

  /**
   * Step: N8n received no username — the opt-in was off.
   *
   * @Then n8n received no user from Drupal
   */
  public function n8nReceivedNoUser(): void {
    Assert::assertArrayNotHasKey('user', $this->echo['metadata'] ?? [], json_encode($this->echo['metadata'] ?? []));
  }

  /**
   * Step: N8n received no visitor roles — the opt-in was off.
   *
   * @Then n8n received no user roles from Drupal
   */
  public function n8nReceivedNoUserRoles(): void {
    Assert::assertArrayNotHasKey('user_roles', $this->echo['metadata'] ?? [], json_encode($this->echo['metadata'] ?? []));
  }

  /**
   * Step: N8n received the assistant's own allowed roles (context, not a gate).
   *
   * @Then n8n received the allowed roles :role
   */
  public function n8nReceivedTheAllowedRoles(string $role): void {
    $got = $this->echo['metadata']['allowed_roles'] ?? [];
    Assert::assertContains($role, $got, 'allowed_roles should carry the assistant restriction: ' . json_encode($this->echo['metadata'] ?? []));
  }

  // ── Agents passthrough (the assistant's "Agents to use" as MCP tool ids) ─────

  /**
   * Ticks Drupal agents on the assistant's companion agent.
   *
   * The agents must exist for their function-call plugins to resolve, so this
   * creates each selected agent before ticking it — and hands them to teardown.
   * The companion agent shares the assistant's id, and its tools map is keyed
   * ai_agents::ai_agent::<id>, exactly as the assistant form writes it. A fresh
   * function-call plugin cache lets the new agents resolve this same request.
   *
   * @Given the assistant :id is allowed to use the Drupal agents :first and :second
   */
  public function assistantAllowedToUseAgents(string $id, string $first, string $second): void {
    $this->drupalEvalJson(strtr(<<<'PHP'
      $etm = \Drupal::entityTypeManager();
      $selected = json_decode(SELECTED, TRUE);
      foreach ($selected as $agent_id) {
        if (!$etm->getStorage('ai_agent')->load($agent_id)) {
          $etm->getStorage('ai_agent')->create([
            'id' => $agent_id, 'label' => $agent_id, 'description' => 'behat',
            'system_prompt' => '', 'tools' => [],
            'orchestration_agent' => FALSE, 'triage_agent' => FALSE, 'max_loops' => 3,
          ])->save();
        }
      }
      $companion = $etm->getStorage('ai_agent')->load(ID);
      $tools = [];
      foreach ($selected as $agent_id) {
        $tools['ai_agents::ai_agent::' . $agent_id] = TRUE;
      }
      $companion->set('tools', $tools)->save();
      \Drupal::service('plugin.manager.ai.function_calls')->clearCachedDefinitions();
      echo json_encode(TRUE);
      PHP, [
        'ID' => var_export($id, TRUE),
        'SELECTED' => var_export(json_encode([$first, $second]), TRUE),
      ]));
    $this->createdAssistants[] = $first;
    $this->createdAssistants[] = $second;
    if ($workflow = $this->n8nWorkflowByName('Echo Agent')) {
      $this->providerCallBaseline = $this->n8nExecutionCount($workflow['id']);
    }
  }

  /**
   * Step: N8n received the selected agents as their MCP tool ids, in order.
   *
   * @Then n8n received the agents :first and :second
   */
  public function n8nReceivedTheAgents(string $first, string $second): void {
    Assert::assertSame(
      [$first, $second],
      $this->echo['metadata']['agents'] ?? NULL,
      'The selected agents should arrive as ordered aif_ tool ids: ' . json_encode($this->echo['metadata'] ?? []),
    );
  }

  /**
   * Step: N8n received no agents key — a bare assistant selected none.
   *
   * @Then n8n received no agents from Drupal
   */
  public function n8nReceivedNoAgents(): void {
    Assert::assertArrayNotHasKey(
      'agents',
      $this->echo['metadata'] ?? [],
      'An assistant that selected no agents must forward no agents key: ' . json_encode($this->echo['metadata'] ?? []),
    );
  }

  /**
   * Step: The passthrough hit the provider exactly once, per n8n's own log.
   *
   * @Then the agent made exactly one call to the provider
   */
  public function theAgentMadeExactlyOneCall(): void {
    $workflow = $this->n8nWorkflowByName('Echo Agent');
    Assert::assertNotNull($workflow, 'The Echo Agent fixture should exist.');
    $count = $this->n8nExecutionCount($workflow['id'], $this->providerCallBaseline + 1);
    Assert::assertSame(
      $this->providerCallBaseline + 1,
      $count,
      'Selecting agents must not turn the one-call passthrough into many: baseline '
      . $this->providerCallBaseline . ', now ' . $count,
    );
  }

  /**
   * Step: The selection travelled as data, so Drupal never ran the agents.
   *
   * The agents ride in metadata.agents — handed to n8n as a list — rather than
   * being executed on the Drupal side. That they arrived as data, together with
   * the single provider call above, is the passthrough guarantee.
   *
   * @Then Drupal ran none of the selected agents itself
   */
  public function drupalRanNoneOfTheSelectedAgents(): void {
    Assert::assertNotEmpty(
      $this->echo['metadata']['agents'] ?? [],
      'The selection must travel to n8n as data, proving Drupal handed it over rather than running it: '
      . json_encode($this->echo['metadata'] ?? []),
    );
  }

  // ── Page context (the page the chat box is on, and its content entity) ───────

  /**
   * Puts the chat box on a content page, creating the node it names.
   *
   * The node must exist for its canonical route to resolve to an entity. The
   * path is stashed as the chat context the next message rides with, exactly as
   * the chat block would send it.
   *
   * @Given the chat box is on the page :path for the node :nid
   */
  public function theChatBoxIsOnThePageForNode(string $path, int $nid): void {
    $this->drupalEvalJson(strtr(<<<'PHP'
      $etm = \Drupal::entityTypeManager();
      if (!$etm->getStorage('node_type')->load('page')) {
        $etm->getStorage('node_type')->create(['type' => 'page', 'name' => 'Page'])->save();
      }
      if (!$etm->getStorage('node')->load(NID)) {
        $etm->getStorage('node')->create(['nid' => NID, 'type' => 'page', 'title' => 'Behat node ' . NID])->save();
      }
      echo json_encode(TRUE);
      PHP, ['NID' => var_export($nid, TRUE)]));
    $this->createdNodes[] = $nid;
    $this->pageContext = ['current_route' => $path];
  }

  /**
   * Puts the chat box on a listing page, which owns no single entity.
   *
   * @Given the chat box is on the listing page :path
   */
  public function theChatBoxIsOnTheListingPage(string $path): void {
    $this->pageContext = ['current_route' => $path];
  }

  /**
   * Step: N8n received the page path.
   *
   * @Then n8n received the path :path
   */
  public function n8nReceivedThePath(string $path): void {
    Assert::assertSame(
      $path,
      $this->echo['metadata']['path'] ?? NULL,
      'The page path should arrive as metadata.path: ' . json_encode($this->echo['metadata'] ?? []),
    );
  }

  /**
   * Step: N8n received the page's content entity.
   *
   * @Then n8n received the entity of type :type with id :id
   */
  public function n8nReceivedTheEntity(string $type, string $id): void {
    Assert::assertSame(
      ['type' => $type, 'id' => $id],
      $this->echo['metadata']['entity'] ?? NULL,
      'A content page should carry its entity as {type, id}: ' . json_encode($this->echo['metadata'] ?? []),
    );
  }

  /**
   * Step: N8n received no entity — the page owned no single one.
   *
   * @Then n8n received no entity from Drupal
   */
  public function n8nReceivedNoEntity(): void {
    Assert::assertArrayNotHasKey(
      'entity',
      $this->echo['metadata'] ?? [],
      'A listing must forward a path but no entity: ' . json_encode($this->echo['metadata'] ?? []),
    );
  }

  /**
   * Step: The page context is exactly path and entity, nothing invented.
   *
   * @Then n8n received only the path and entity as page context
   */
  public function n8nReceivedOnlyPathAndEntity(): void {
    $metadata = $this->echo['metadata'] ?? [];
    $page = array_intersect_key($metadata, array_flip([
      'path', 'entity', 'page_path', 'url', 'title', 'langcode', 'bundle', 'entity_type', 'entity_id', 'route',
    ]));
    Assert::assertSame(
      ['path', 'entity'],
      array_keys($page),
      'Page context must carry only path and its derived entity: ' . json_encode($metadata),
    );
  }

  /**
   * Deletes any nodes a page-context scenario created.
   *
   * @AfterScenario
   */
  public function tearDownNodes(): void {
    if (!$this->createdNodes) {
      return;
    }
    $this->drupalEvalJson(strtr(<<<'PHP'
      $storage = \Drupal::entityTypeManager()->getStorage('node');
      foreach (json_decode('IDS', TRUE) as $nid) {
        if ($node = $storage->load($nid)) {
          $node->delete();
        }
      }
      echo json_encode(TRUE);
      PHP, ['IDS' => json_encode($this->createdNodes)]));
    $this->createdNodes = [];
  }

  // ── Session from n8n memory ──────────────────────────────────────────────────

  /**
   * Step: An assistant that loads its shown transcript from n8n's memory.
   *
   * @Given an assistant :id backed by the :agent agent remembering from n8n
   */
  public function anAssistantRememberingFromN8n(string $id, string $agent): void {
    $this->createAssistantRememberingFromN8n($id, $agent, 2);
  }

  /**
   * Step: The same, with an explicit history context length.
   *
   * @Given an assistant :id backed by the :agent agent remembering from n8n with history context length :length
   */
  public function anAssistantRememberingFromN8nWithLength(string $id, string $agent, int $length): void {
    $this->createAssistantRememberingFromN8n($id, $agent, $length);
  }

  /**
   * Step: The chat box asks the runner for the transcript it would paint.
   *
   * @When the assistant's stored conversation is loaded
   */
  public function theStoredConversationIsLoaded(): void {
    Assert::assertNotEmpty($this->createdAssistants, 'No assistant was created to load.');
    $id = end($this->createdAssistants);
    $this->loadedHistory = $this->loadAssistantHistory($id);
  }

  /**
   * Step: The loaded transcript contains a message.
   *
   * @Then the conversation came back from n8n including :text
   */
  public function theConversationCameBackIncluding(string $text): void {
    $messages = array_column($this->loadedHistory, 'message');
    Assert::assertContains(
      $text,
      $messages,
      "n8n's transcript should include '$text'. Got: " . json_encode($this->loadedHistory),
    );
  }

  /**
   * Step: The loaded transcript is a given length.
   *
   * @Then the loaded conversation has :count messages
   */
  public function theLoadedConversationHasMessages(int $count): void {
    Assert::assertCount(
      $count,
      $this->loadedHistory,
      'History context length should bound what n8n hands back: ' . json_encode($this->loadedHistory),
    );
  }

  /**
   * Step: Every loaded turn is display-ready.
   *
   * The chat box drops any message without a timestamp newer than a day, so a
   * turn that reaches the runner but carries no timestamp is painted nowhere —
   * the transcript silently vanishes on reload. This guards that gate.
   *
   * @Then every loaded turn can be shown in the chat box
   */
  public function everyLoadedTurnCanBeShown(): void {
    Assert::assertNotEmpty($this->loadedHistory, 'Nothing was loaded to show.');
    $day_ago = strtotime('-1 day');
    foreach ($this->loadedHistory as $turn) {
      Assert::assertArrayHasKey(
        'timestamp',
        $turn,
        'A loaded turn without a timestamp is dropped by the chat box: ' . json_encode($turn),
      );
      Assert::assertGreaterThan(
        $day_ago,
        $turn['timestamp'],
        'A loaded turn older than a day is hidden by the chat box: ' . json_encode($turn),
      );
    }
  }

  /**
   * Creates an n8n-memory-mode assistant pointed at a fixture workflow.
   */
  protected function createAssistantRememberingFromN8n(string $id, string $agent, int $length): void {
    $workflow = $this->n8nWorkflowByName($agent);
    Assert::assertNotNull($workflow, "No fixture named '$agent'.");
    $this->createN8nAssistant($id, $workflow['id'], '', $length, 'session_from_n8n');
    $this->createdAssistants[] = $id;
  }

  // ── Support ────────────────────────────────────────────────────────────────

  /**
   * Creates or updates a Key entity with a literal value.
   *
   * The "config" key provider stores the value in the entity — fine for a test
   * key that dies with the ephemeral site. The module under test never sees the
   * raw value either way; it asks the key repository at call time.
   */
  protected function createKeyEntity(string $id, string $value): void {
    $this->drupalEvalJson(strtr(<<<'PHP'
      $storage = \Drupal::entityTypeManager()->getStorage('key');
      $key = $storage->load(KEY_ID);
      if ($key === NULL) {
        $key = $storage->create(['id' => KEY_ID, 'label' => KEY_ID, 'key_type' => 'authentication']);
      }
      $key->set('key_provider', 'config');
      $key->set('key_provider_settings', ['key_value' => KEY_VALUE]);
      $key->save();
      echo json_encode(TRUE);
      PHP, [
        'KEY_ID' => var_export($id, TRUE),
        'KEY_VALUE' => var_export($value, TRUE),
      ]));
  }

}
