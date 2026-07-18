<?php

declare(strict_types=1);

namespace Drupal\Tests\n8n\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\n8n\N8nClient;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Tests the n8n client against a mock HTTP transport.
 *
 * The client is built over Guzzle's MockHandler so the OUTGOING REQUEST and the
 * response handling can both be asserted without a network — the same technique
 * ai_provider_openai uses, and the reason we can assert what we send n8n at all.
 *
 * Spec: features/admin-connection.feature
 *
 * @group n8n
 *
 * @coversDefaultClass \Drupal\n8n\N8nClient
 */
class N8nClientTest extends UnitTestCase {

  /**
   * The captured request history of the mock transport.
   *
   * @var array
   */
  protected array $history = [];

  /**
   * Builds a client whose n8n responds with the queued responses.
   *
   * @param array $responses
   *   Responses or exceptions for the mock transport to return in order.
   * @param array $settings
   *   Overrides for n8n.settings.
   * @param string|null $key_value
   *   The value the Key entity resolves to, or NULL for "no such key".
   */
  protected function buildClient(array $responses, array $settings = [], ?string $key_value = 'secret-key'): N8nClient {
    $settings += [
      'base_url' => 'https://n8n.example.com',
      'api_key' => 'n8n_key',
      'timeout' => 30,
    ];

    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $this->history = [];
    $stack->push(Middleware::history($this->history));
    $http = new GuzzleClient(['handler' => $stack]);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn($name) => $settings[$name] ?? NULL,
    );
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with(N8nClient::CONFIG_NAME)->willReturn($config);

    $key_repository = $this->createMock(KeyRepositoryInterface::class);
    if ($key_value === NULL) {
      $key_repository->method('getKey')->willReturn(NULL);
    }
    else {
      $key = $this->createMock(KeyInterface::class);
      $key->method('getKeyValue')->willReturn($key_value);
      $key_repository->method('getKey')->willReturn($key);
    }

    $logger = $this->createMock(LoggerChannelInterface::class);

    return new N8nClient(
      $http,
      $config_factory,
      $key_repository,
      $logger,
      $this->getStringTranslationStub(),
    );
  }

  /**
   * A trailing slash on the base URL must not produce a double slash.
   *
   * @covers ::getBaseUrl
   */
  public function testBaseUrlLosesItsTrailingSlash(): void {
    $client = $this->buildClient([], ['base_url' => 'https://n8n.example.com/']);
    $this->assertSame('https://n8n.example.com', $client->getBaseUrl());
  }

  /**
   * The key is resolved through the Key module, never stored by us.
   *
   * @covers ::getApiKey
   */
  public function testApiKeyComesFromTheKeyModule(): void {
    $client = $this->buildClient([], [], 'resolved-value');
    $this->assertSame('resolved-value', $client->getApiKey());
  }

  /**
   * A deleted Key entity is empty, not fatal — callers decide.
   *
   * @covers ::getApiKey
   * @covers ::keyExists
   */
  public function testMissingKeyIsEmptyRatherThanFatal(): void {
    $client = $this->buildClient([], [], NULL);
    $this->assertSame('', $client->getApiKey());
    $this->assertFalse($client->keyExists('gone'));
  }

  /**
   * Being configured means an admin set it up, not that n8n is up.
   *
   * @covers ::isConfigured
   *
   * @dataProvider configuredCases
   */
  public function testIsConfigured(array $settings, ?string $key_value, bool $expected): void {
    $client = $this->buildClient([], $settings, $key_value);
    $this->assertSame($expected, $client->isConfigured());
  }

  /**
   * Cases for isConfigured().
   */
  public static function configuredCases(): array {
    return [
      'url and key' => [[], 'secret', TRUE],
      'no url' => [['base_url' => ''], 'secret', FALSE],
      'no key selected' => [['api_key' => ''], 'secret', FALSE],
      'key entity is gone' => [[], NULL, FALSE],
    ];
  }

  /**
   * A good connection reports ok, and asks n8n the cheapest question it can.
   *
   * @covers ::testConnection
   */
  public function testSuccessfulConnectionReportsOk(): void {
    $client = $this->buildClient([
      new Response(200, [], json_encode(['data' => []])),
    ]);

    $result = $client->testConnection();

    $this->assertSame('ok', $result['status']);
    $this->assertCount(1, $this->history, 'Test connection makes exactly one request.');

    $request = $this->history[0]['request'];
    $this->assertSame('GET', $request->getMethod());
    $this->assertSame('/api/v1/workflows', $request->getUri()->getPath());
    $this->assertSame('limit=1', $request->getUri()->getQuery(), 'Asks for one workflow, not all of them.');
  }

  /**
   * The key travels in the header n8n expects, and nowhere else.
   *
   * @covers ::request
   */
  public function testTheApiKeyIsSentAsTheN8nHeader(): void {
    $client = $this->buildClient([new Response(200, [], '{}')], [], 'secret-key');
    $client->testConnection();

    $request = $this->history[0]['request'];
    $this->assertSame('secret-key', $request->getHeaderLine('X-N8N-API-KEY'));
    $this->assertSame('', $request->getHeaderLine('Authorization'), 'n8n uses its own header, not Authorization.');
  }

  /**
   * Nothing is asked of the network until an admin has configured something.
   *
   * @covers ::testConnection
   *
   * @dataProvider unconfiguredCases
   */
  public function testUnconfiguredConnectionFailsWithoutCallingOut(array $settings, ?string $key_value, string $expected): void {
    $client = $this->buildClient([], $settings, $key_value);

    $result = $client->testConnection();

    $this->assertSame('error', $result['status']);
    $this->assertStringContainsString($expected, (string) $result['message']);
    $this->assertCount(0, $this->history, 'An unconfigured client must not make a request.');
  }

  /**
   * Cases where testConnection should refuse before touching the network.
   */
  public static function unconfiguredCases(): array {
    return [
      'no url' => [['base_url' => ''], 'secret', 'base URL'],
      'no key' => [['api_key' => ''], 'secret', 'API key'],
    ];
  }

  /**
   * Failures from n8n become sentences an admin can act on.
   *
   * @covers ::testConnection
   * @covers ::friendlyError
   *
   * @dataProvider errorCases
   */
  public function testFailuresBecomeFriendlyMessages(int $status, string $expected): void {
    $client = $this->buildClient([
      new ClientException(
        'n8n said no',
        new Request('GET', 'https://n8n.example.com/api/v1/workflows'),
        new Response($status),
      ),
    ]);

    $result = $client->testConnection();

    $this->assertSame('error', $result['status']);
    $this->assertStringContainsString($expected, (string) $result['message']);
  }

  /**
   * The failures an admin actually hits, and what they should read as.
   */
  public static function errorCases(): array {
    return [
      'revoked key' => [401, 'rejected the API key'],
      'forbidden key' => [403, 'rejected the API key'],
      'wrong URL' => [404, 'was not found at that URL'],
      'anything else' => [500, 'HTTP 500'],
    ];
  }

  /**
   * An unreachable host reads as unreachable, not as a stack trace.
   *
   * @covers ::testConnection
   */
  public function testUnreachableHostIsReportedAsUnreachable(): void {
    $client = $this->buildClient([
      new ConnectException('Connection refused', new Request('GET', 'https://n8n.example.com')),
    ]);

    $result = $client->testConnection();

    $this->assertSame('error', $result['status']);
    $this->assertStringContainsString('Could not reach n8n', (string) $result['message']);
  }

  /**
   * The configured timeout reaches the transport.
   *
   * A slow agent must fail cleanly rather than hang a page, so this value has to
   * actually arrive rather than merely be stored.
   *
   * @covers ::request
   */
  public function testTheConfiguredTimeoutIsApplied(): void {
    $client = $this->buildClient([new Response(200, [], '{}')], ['timeout' => 5]);
    $client->testConnection();

    $this->assertSame(5, $this->history[0]['options']['timeout']);
  }

  /**
   * A non-JSON body is an empty array, not a crash.
   *
   * @covers ::request
   */
  public function testNonJsonBodyDoesNotCrash(): void {
    $client = $this->buildClient([new Response(200, [], '<html>not json</html>')]);
    $this->assertSame([], $client->request('GET', '/api/v1/workflows'));
  }

  /**
   * A workflow listing as n8n returns it, shaped for the discovery tests.
   *
   * @param array $workflows
   *   Workflow stubs: [id, name, nodes[]].
   */
  protected function workflowListing(array $workflows): Response {
    return new Response(200, [], json_encode(['data' => $workflows]));
  }

  /**
   * A public chat trigger node, the way the REST payload carries it.
   */
  protected function chatTrigger(string $webhook_id, array $parameters = ['public' => TRUE], string $name = 'When chat message received'): array {
    return [
      'type' => '@n8n/n8n-nodes-langchain.chatTrigger',
      'name' => $name,
      'webhookId' => $webhook_id,
      'parameters' => $parameters,
    ];
  }

  /**
   * Discovery keeps only public chat triggers and asks n8n for active only.
   *
   * @covers ::listChatWorkflows
   */
  public function testDiscoveryFiltersToPublicChatTriggers(): void {
    $client = $this->buildClient([
      $this->workflowListing([
        [
          'id' => 'wf1',
          'name' => 'Echo Agent',
          'nodes' => [$this->chatTrigger('hook-1')],
        ],
        [
          'id' => 'wf2',
          'name' => 'Private Agent',
          'nodes' => [$this->chatTrigger('hook-2', ['public' => FALSE])],
        ],
        [
          'id' => 'wf3',
          'name' => 'Webhook Only',
          'nodes' => [
            [
              'type' => 'n8n-nodes-base.webhook',
              'name' => 'Webhook',
              'webhookId' => 'hook-3',
              'parameters' => [],
            ],
          ],
        ],
      ]),
    ]);

    $models = $client->listChatWorkflows();

    $this->assertSame(['wf1'], array_keys($models));
    $this->assertSame('Echo Agent', $models['wf1']['label']);
    $this->assertSame('hook-1', $models['wf1']['webhook_id']);
    // The exclusion of inactive workflows happens in the query, not in PHP.
    parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
    $this->assertSame('true', $query['active']);
  }

  /**
   * The site tag scopes discovery to n8n, not in PHP: the query carries it.
   *
   * @covers ::listChatWorkflows
   */
  public function testTheSiteTagIsPassedAsTheWorkflowFilter(): void {
    $client = $this->buildClient([
      $this->workflowListing([
        [
          'id' => 'wf1',
          'name' => 'Echo Agent',
          'nodes' => [$this->chatTrigger('hook-1')],
        ],
      ]),
    ], ['tag' => 'mysite']);

    $client->listChatWorkflows();

    parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
    $this->assertSame('mysite', $query['tags']);
  }

  /**
   * An empty site tag is no filter at all — every qualifying workflow is offered.
   *
   * @covers ::listChatWorkflows
   */
  public function testAnEmptySiteTagDoesNotFilter(): void {
    $client = $this->buildClient([
      $this->workflowListing([
        [
          'id' => 'wf1',
          'name' => 'Echo Agent',
          'nodes' => [$this->chatTrigger('hook-1')],
        ],
      ]),
    ], ['tag' => '']);

    $models = $client->listChatWorkflows();

    $this->assertArrayHasKey('wf1', $models);
    parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
    $this->assertArrayNotHasKey('tags', $query);
  }

  /**
   * The Chat Hub agent name, when set, wins over the workflow name.
   *
   * @covers ::listChatWorkflows
   */
  public function testChatHubAgentNameWinsAsTheLabel(): void {
    $client = $this->buildClient([
      $this->workflowListing([
        [
          'id' => 'wf1',
          'name' => 'boring-internal-name',
          'nodes' => [
            $this->chatTrigger('hook-1', [
              'public' => TRUE,
              'agentName' => 'Concierge',
            ]),
          ],
        ],
      ]),
    ]);

    $this->assertSame('Concierge', $client->listChatWorkflows()['wf1']['label']);
  }

  /**
   * One workflow with two public chat triggers is two models, not one.
   *
   * Proven live: each public trigger registers its own webhook and answers
   * independently — the trigger is the door, the workflow is the building.
   *
   * @covers ::listChatWorkflows
   */
  public function testEachPublicChatTriggerIsItsOwnModel(): void {
    $client = $this->buildClient([
      $this->workflowListing([
        [
          'id' => 'wf1',
          'name' => 'Two Doors',
          'nodes' => [
            $this->chatTrigger('hook-front', ['public' => TRUE], 'Front Door'),
            $this->chatTrigger('hook-admin', ['public' => TRUE], 'Admin Door'),
          ],
        ],
      ]),
    ]);

    $models = $client->listChatWorkflows();

    $this->assertCount(2, $models);
    // The first door keeps the plain workflow id, so the common single-trigger
    // case never carries a composite id.
    $this->assertSame('hook-front', $models['wf1']['webhook_id']);
    $this->assertSame('hook-admin', $models['wf1::hook-admin']['webhook_id']);
    $this->assertSame('Two Doors — Front Door', $models['wf1']['label']);
    $this->assertSame('Two Doors — Admin Door', $models['wf1::hook-admin']['label']);
  }

  /**
   * The chat POST carries the contract and goes to the trigger's chat URL.
   *
   * @covers ::chatSend
   */
  public function testChatSendPostsTheChatContract(): void {
    $client = $this->buildClient([
      $this->workflowListing([
        ['id' => 'wf1', 'name' => 'Echo Agent', 'nodes' => [$this->chatTrigger('hook-1')]],
      ]),
      new Response(200, [], '{"output":"hello back"}'),
    ]);

    $reply = $client->chatSend('wf1', 'session-abc', 'hello', ['source' => 'drupal']);

    $this->assertSame('hello back', $reply);
    $request = $this->history[1]['request'];
    $this->assertSame('/webhook/hook-1/chat', $request->getUri()->getPath());
    $body = json_decode((string) $request->getBody(), TRUE);
    $this->assertSame('sendMessage', $body['action']);
    $this->assertSame('session-abc', $body['sessionId']);
    $this->assertSame('hello', $body['chatInput']);
    $this->assertSame(['source' => 'drupal'], $body['metadata']);
  }

  /**
   * The admin API key never travels to the chat webhook.
   *
   * The webhook is a separate, differently-authenticated surface; leaking the
   * management key to it would hand chat visitors an admin credential.
   *
   * @covers ::chatSend
   */
  public function testChatSendDoesNotLeakTheApiKey(): void {
    $client = $this->buildClient([
      $this->workflowListing([
        ['id' => 'wf1', 'name' => 'Echo Agent', 'nodes' => [$this->chatTrigger('hook-1')]],
      ]),
      new Response(200, [], '{"output":"ok"}'),
    ]);

    $client->chatSend('wf1', 's', 'hi');

    $this->assertFalse($this->history[1]['request']->hasHeader('X-N8N-API-KEY'));
  }

  /**
   * An unknown or unlisted workflow refuses before reaching the network.
   *
   * @covers ::chatSend
   */
  public function testChatSendRefusesAnUnknownModel(): void {
    $client = $this->buildClient([$this->workflowListing([])]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/not an available chat model/');
    $client->chatSend('nope', 's', 'hi');
  }

  /**
   * An answer without an output field is an error, not an empty bubble.
   *
   * A workflow whose last node forgot to name its field "output" — or a
   * misconfigured streaming trigger returning an empty 200 — must surface
   * loudly rather than render silence.
   *
   * @covers ::chatSend
   */
  public function testChatSendRejectsAnAnswerWithoutOutput(): void {
    $client = $this->buildClient([
      $this->workflowListing([
        ['id' => 'wf1', 'name' => 'Echo Agent', 'nodes' => [$this->chatTrigger('hook-1')]],
      ]),
      new Response(200, [], '{"data":"wrong shape"}'),
    ]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/"output"/');
    $client->chatSend('wf1', 's', 'hi');
  }

  /**
   * Agents are slow; the chat call gets a floor of 60 seconds.
   *
   * @covers ::chatSend
   */
  public function testChatTimeoutFloorIsSixtySeconds(): void {
    $client = $this->buildClient([
      $this->workflowListing([
        ['id' => 'wf1', 'name' => 'Echo Agent', 'nodes' => [$this->chatTrigger('hook-1')]],
      ]),
      new Response(200, [], '{"output":"ok"}'),
    ], ['timeout' => 5]);

    $client->chatSend('wf1', 's', 'hi');

    $this->assertSame(60, $this->history[1]['options']['timeout']);
  }

  /**
   * Loading a session posts the load contract to the trigger's chat URL.
   *
   * @covers ::loadPreviousSession
   */
  public function testLoadPreviousSessionPostsTheLoadContract(): void {
    $client = $this->buildClient([
      $this->workflowListing([
        ['id' => 'wf1', 'name' => 'History Agent', 'nodes' => [$this->chatTrigger('hook-1')]],
      ]),
      new Response(200, [], '{"data":[]}'),
    ]);

    $client->loadPreviousSession('wf1', 'session-abc');

    $request = $this->history[1]['request'];
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('/webhook/hook-1/chat', $request->getUri()->getPath());
    $body = json_decode((string) $request->getBody(), TRUE);
    $this->assertSame('loadPreviousSession', $body['action']);
    $this->assertSame('session-abc', $body['sessionId']);
    $this->assertArrayNotHasKey('chatInput', $body, 'A load is not a message.');
    $this->assertFalse($request->hasHeader('X-N8N-API-KEY'), 'The admin key must not travel to the chat webhook.');
  }

  /**
   * Serialised LangChain messages map to Drupal roles, oldest first.
   *
   * The `{data: [...]}` shape and the id-path role marker are exactly what a
   * Postgres Chat Memory node returns, so this proves we read a real memory.
   *
   * @covers ::loadPreviousSession
   * @covers ::roleOfLangchainMessage
   */
  public function testLoadPreviousSessionMapsLangchainMessages(): void {
    $data = json_encode([
      'data' => [
        [
          'type' => 'constructor',
          'id' => ['langchain_core', 'messages', 'HumanMessage'],
          'kwargs' => ['content' => 'hi'],
        ],
        [
          'type' => 'constructor',
          'id' => ['langchain_core', 'messages', 'AIMessage'],
          'kwargs' => ['content' => 'hello'],
        ],
        [
          'type' => 'constructor',
          'id' => ['langchain_core', 'messages', 'SystemMessage'],
          'kwargs' => ['content' => 'be nice'],
        ],
      ],
    ]);
    $client = $this->buildClient([
      $this->workflowListing([
        ['id' => 'wf1', 'name' => 'History Agent', 'nodes' => [$this->chatTrigger('hook-1')]],
      ]),
      new Response(200, [], $data),
    ]);

    $history = $client->loadPreviousSession('wf1', 's');

    $this->assertSame([
      ['role' => 'user', 'message' => 'hi'],
      ['role' => 'assistant', 'message' => 'hello'],
      ['role' => 'system', 'message' => 'be nice'],
    ], $history);
  }

  /**
   * A simpler `type: human|ai` encoding is understood too.
   *
   * @covers ::loadPreviousSession
   * @covers ::roleOfLangchainMessage
   */
  public function testLoadPreviousSessionUnderstandsTheSimpleTypeEncoding(): void {
    $data = json_encode([
      'data' => [
        ['type' => 'human', 'content' => 'ping'],
        ['type' => 'ai', 'content' => 'pong'],
      ],
    ]);
    $client = $this->buildClient([
      $this->workflowListing([
        ['id' => 'wf1', 'name' => 'History Agent', 'nodes' => [$this->chatTrigger('hook-1')]],
      ]),
      new Response(200, [], $data),
    ]);

    $this->assertSame([
      ['role' => 'user', 'message' => 'ping'],
      ['role' => 'assistant', 'message' => 'pong'],
    ], $client->loadPreviousSession('wf1', 's'));
  }

  /**
   * A workflow with no retrieving memory answers empty — that is not an error.
   *
   * @covers ::loadPreviousSession
   */
  public function testLoadPreviousSessionReturnsEmptyWhenNoTranscript(): void {
    foreach (['{"data":[]}', '{}', '[]'] as $payload) {
      $client = $this->buildClient([
        $this->workflowListing([
          ['id' => 'wf1', 'name' => 'History Agent', 'nodes' => [$this->chatTrigger('hook-1')]],
        ]),
        new Response(200, [], $payload),
      ]);
      $this->assertSame([], $client->loadPreviousSession('wf1', 's'), "Payload $payload should load nothing.");
    }
  }

  /**
   * An unknown or unlisted workflow refuses before reaching the network.
   *
   * @covers ::loadPreviousSession
   */
  public function testLoadPreviousSessionRefusesAnUnknownModel(): void {
    $client = $this->buildClient([$this->workflowListing([])]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/not an available chat model/');
    $client->loadPreviousSession('nope', 's');
  }

}
