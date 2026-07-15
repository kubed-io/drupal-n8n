<?php

declare(strict_types=1);

namespace Drupal\Tests\n8n\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
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
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger);

    return new N8nClient($http, $config_factory, $key_repository, $logger_factory);
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
   * "Configured" means an admin set it up — not that n8n is up.
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
    $this->assertStringContainsString($expected, $result['message']);
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
    $this->assertStringContainsString($expected, $result['message']);
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
    $this->assertStringContainsString('Could not reach n8n', $result['message']);
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

}
