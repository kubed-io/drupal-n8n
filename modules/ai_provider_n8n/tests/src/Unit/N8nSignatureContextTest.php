<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_n8n\Unit;

use Drupal\ai_provider_n8n\N8nChatContext;
use Drupal\ai_provider_n8n\Plugin\AiProvider\N8nProvider;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;

/**
 * Unit-tests the two signature helpers this repo owns: agents and page context.
 *
 * Both helpers are pure functions of injected services, so they are exercised
 * here without a container: a constructor-less N8nProvider has its collaborators
 * set by reflection, and the two protected helpers are called directly. This
 * pins the mapping rules — the aif_ tool ids and the single-entity page rule —
 * as unit facts; the Behat suite proves the same rules end to end through n8n.
 *
 * Spec: features/agents-metadata.feature, features/page-context.feature
 *
 * @group n8n
 * @group ai_provider_n8n
 *
 * @coversDefaultClass \Drupal\ai_provider_n8n\Plugin\AiProvider\N8nProvider
 */
class N8nSignatureContextTest extends UnitTestCase {

  /**
   * Builds a provider with the given collaborators, skipping the plugin base.
   *
   * @param \Symfony\Component\Plugin\PluginManagerInterface|null $function_calls
   *   The AI function-call plugin manager, for agent id resolution.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface|null $etm
   *   The entity type manager, for loading the companion agent.
   * @param \Symfony\Component\Routing\Matcher\RequestMatcherInterface|null $router
   *   The access-free router, for deriving an entity from a page path.
   * @param \Drupal\ai_provider_n8n\N8nChatContext|null $chat_context
   *   The request-scoped page store.
   *
   * @return \Drupal\ai_provider_n8n\Plugin\AiProvider\N8nProvider
   *   The provider under test.
   */
  protected function provider(
    ?PluginManagerInterface $function_calls = NULL,
    ?EntityTypeManagerInterface $etm = NULL,
    ?RequestMatcherInterface $router = NULL,
    ?N8nChatContext $chat_context = NULL,
  ): N8nProvider {
    $provider = (new \ReflectionClass(N8nProvider::class))->newInstanceWithoutConstructor();
    $this->setProtected($provider, 'functionCallManager', $function_calls);
    $this->setProtected($provider, 'entityTypeManager', $etm);
    $this->setProtected($provider, 'router', $router);
    $this->setProtected($provider, 'chatContext', $chat_context ?? new N8nChatContext());
    return $provider;
  }

  /**
   * Sets a protected property on an object by reflection.
   */
  protected function setProtected(object $object, string $property, mixed $value): void {
    $ref = new \ReflectionProperty($object, $property);
    $ref->setAccessible(TRUE);
    $ref->setValue($object, $value);
  }

  /**
   * Calls a protected method on an object by reflection.
   */
  protected function callProtected(object $object, string $method, array $args = []): mixed {
    $ref = new \ReflectionMethod($object, $method);
    $ref->setAccessible(TRUE);
    return $ref->invokeArgs($object, $args);
  }

  /**
   * A fake function-call plugin whose rendered name is fixed.
   */
  protected function fakeTool(string $name): object {
    return new class($name) {

      /**
       * Constructs the fake tool.
       *
       * @param string $name
       *   The name its rendered function array reports.
       */
      public function __construct(protected string $name) {}

      /**
       * Mirrors the real plugin's fluent normalize().
       *
       * @return static
       *   This instance.
       */
      public function normalize(): static {
        return $this;
      }

      /**
       * The rendered function array, carrying only the name we assert on.
       *
       * @return array
       *   The function array with its name.
       */
      public function renderFunctionArray(): array {
        return ['name' => $this->name];
      }

    };
  }

  /**
   * A function-call manager that resolves the given plugin ids to fake tools.
   *
   * @param array<string, string> $names
   *   Map of plugin id to the name its rendered function array should carry.
   */
  protected function functionCallManager(array $names): PluginManagerInterface {
    $manager = $this->createMock(PluginManagerInterface::class);
    $manager->method('hasDefinition')
      ->willReturnCallback(fn($id) => isset($names[$id]));
    $manager->method('createInstance')
      ->willReturnCallback(fn($id) => $this->fakeTool($names[$id]));
    return $manager;
  }

  /**
   * An entity type manager whose ai_agent storage loads an agent with tools.
   *
   * @param array<string, bool>|null $tools
   *   The agent's tools map, or NULL for "no such agent".
   */
  protected function agentManager(?array $tools): EntityTypeManagerInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    if ($tools === NULL) {
      $storage->method('load')->willReturn(NULL);
    }
    else {
      $agent = $this->createMock(ConfigEntityInterface::class);
      $agent->method('get')->with('tools')->willReturn($tools);
      $storage->method('load')->willReturn($agent);
    }
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->with('ai_agent')->willReturn(TRUE);
    $etm->method('getStorage')->with('ai_agent')->willReturn($storage);
    return $etm;
  }

  // ── Agents passthrough ───────────────────────────────────────────────────

  /**
   * Two selected agents arrive as their aif_ tool ids, in stored order.
   *
   * @covers ::agentsMetadata
   */
  public function testSelectedAgentsBecomeAifToolIds(): void {
    $provider = $this->provider(
      function_calls: $this->functionCallManager([
        'ai_agents::ai_agent::chef' => 'chef',
        'ai_agents::ai_agent::storyteller' => 'storyteller',
      ]),
      etm: $this->agentManager([
        'ai_agents::ai_agent::chef' => TRUE,
        'ai_agents::ai_agent::storyteller' => TRUE,
      ]),
    );

    $this->assertSame(
      ['agents' => ['aif_chef', 'aif_storyteller']],
      $this->callProtected($provider, 'agentsMetadata', ['helper']),
    );
  }

  /**
   * A single selected agent maps to a single id.
   *
   * @covers ::agentsMetadata
   */
  public function testOneSelectedAgentMapsToOneId(): void {
    $provider = $this->provider(
      function_calls: $this->functionCallManager(['ai_agents::ai_agent::chef' => 'chef']),
      etm: $this->agentManager(['ai_agents::ai_agent::chef' => TRUE]),
    );

    $this->assertSame(
      ['agents' => ['aif_chef']],
      $this->callProtected($provider, 'agentsMetadata', ['helper']),
    );
  }

  /**
   * No selection, disabled entries, and non-agent tools all yield no key.
   *
   * @covers ::agentsMetadata
   */
  public function testNoSelectedAgentsIsAbsent(): void {
    $provider = $this->provider(
      function_calls: $this->functionCallManager(['ai_agents::ai_agent::chef' => 'chef']),
      etm: $this->agentManager([
        // Disabled — must be skipped.
        'ai_agents::ai_agent::chef' => FALSE,
        // Not an agent tool — must be skipped.
        'ai_tools::some_other_tool' => TRUE,
      ]),
    );

    $this->assertSame([], $this->callProtected($provider, 'agentsMetadata', ['helper']));
  }

  /**
   * A missing companion agent yields no key.
   *
   * @covers ::agentsMetadata
   */
  public function testMissingAgentIsAbsent(): void {
    $provider = $this->provider(
      function_calls: $this->functionCallManager([]),
      etm: $this->agentManager(NULL),
    );

    $this->assertSame([], $this->callProtected($provider, 'agentsMetadata', ['ghost']));
  }

  // ── Assistant display name ───────────────────────────────────────────────

  /**
   * An entity type manager whose ai_assistant storage returns a fixed label.
   *
   * @param string|null $label
   *   The assistant's label, or NULL to make the assistant absent.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The mocked manager.
   */
  protected function assistantManager(?string $label): EntityTypeManagerInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    if ($label === NULL) {
      $storage->method('load')->willReturn(NULL);
    }
    else {
      $assistant = $this->createMock(ConfigEntityInterface::class);
      $assistant->method('label')->willReturn($label);
      $storage->method('load')->willReturn($assistant);
    }
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->with('ai_assistant')->willReturn(TRUE);
    $etm->method('getStorage')->with('ai_assistant')->willReturn($storage);
    return $etm;
  }

  /**
   * The assistant's label is forwarded, trimmed, as its human name.
   *
   * @covers ::assistantName
   */
  public function testAssistantNameForwardsTheLabel(): void {
    $provider = $this->provider(etm: $this->assistantManager('  Reception Desk  '));

    $this->assertSame(
      'Reception Desk',
      $this->callProtected($provider, 'assistantName', ['reception']),
    );
  }

  /**
   * A missing assistant yields the empty string, so the key is absent.
   *
   * @covers ::assistantName
   */
  public function testAssistantNameIsEmptyWhenAbsent(): void {
    $provider = $this->provider(etm: $this->assistantManager(NULL));

    $this->assertSame('', $this->callProtected($provider, 'assistantName', ['ghost']));
  }

  /**
   * The id form mirrors drupal/mcp: lowercased, cleaned, digit-guarded.
   *
   * @covers ::sanitizeToolName
   *
   * @dataProvider sanitizeCases
   */
  public function testSanitizeToolName(string $in, string $out): void {
    $this->assertSame($out, $this->callProtected($this->provider(), 'sanitizeToolName', [$in]));
  }

  /**
   * Cases pinning the sanitiser to McpPluginBase's rules.
   */
  public static function sanitizeCases(): array {
    return [
      'already clean' => ['chef', 'chef'],
      'uppercase' => ['Chef', 'chef'],
      'spaces and punctuation' => ['Chef Bot!', 'chef_bot'],
      'collapsed runs' => ['a---b', 'a_b'],
      'trimmed edges' => ['__weird__', 'weird'],
      'leading digit guarded' => ['123go', '_123go'],
    ];
  }

  // ── Page context ─────────────────────────────────────────────────────────

  /**
   * A canonical content route carries both the path and its entity.
   *
   * @covers ::pageContextMetadata
   * @covers ::entityFromPath
   */
  public function testContentPageCarriesPathAndEntity(): void {
    $node = $this->createMock(ContentEntityInterface::class);
    $node->method('getEntityTypeId')->willReturn('node');
    $node->method('id')->willReturn(5);

    $router = $this->createMock(RequestMatcherInterface::class);
    $router->method('matchRequest')->willReturn([
      '_route' => 'entity.node.canonical',
      'node' => $node,
    ]);

    $chat_context = new N8nChatContext();
    $chat_context->setPath('/node/5');
    $provider = $this->provider(router: $router, chat_context: $chat_context);

    $this->assertSame(
      ['path' => '/node/5', 'entity' => ['type' => 'node', 'id' => '5']],
      $this->callProtected($provider, 'pageContextMetadata'),
    );
  }

  /**
   * A listing carries the path but no entity — the router does not match one.
   *
   * @covers ::pageContextMetadata
   * @covers ::entityFromPath
   */
  public function testListingCarriesPathButNoEntity(): void {
    $router = $this->createMock(RequestMatcherInterface::class);
    $router->method('matchRequest')
      ->willThrowException(new ResourceNotFoundException());

    $chat_context = new N8nChatContext();
    $chat_context->setPath('/blog');
    $provider = $this->provider(router: $router, chat_context: $chat_context);

    $this->assertSame(
      ['path' => '/blog'],
      $this->callProtected($provider, 'pageContextMetadata'),
    );
  }

  /**
   * A non-canonical route (a view, admin, the front page) carries no entity.
   *
   * @covers ::entityFromPath
   */
  public function testNonCanonicalRouteCarriesNoEntity(): void {
    $router = $this->createMock(RequestMatcherInterface::class);
    $router->method('matchRequest')->willReturn(['_route' => 'view.frontpage.page_1']);

    $chat_context = new N8nChatContext();
    $chat_context->setPath('/');
    $provider = $this->provider(router: $router, chat_context: $chat_context);

    $this->assertSame(
      ['path' => '/'],
      $this->callProtected($provider, 'pageContextMetadata'),
    );
  }

  /**
   * With no page recorded, the whole page-context block is absent.
   *
   * @covers ::pageContextMetadata
   */
  public function testNoPageIsAbsent(): void {
    $this->assertSame([], $this->callProtected($this->provider(), 'pageContextMetadata'));
  }

}
