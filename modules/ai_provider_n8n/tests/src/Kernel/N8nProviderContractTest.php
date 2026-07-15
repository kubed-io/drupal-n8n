<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_n8n\Kernel;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Enum\AiModelCapability;
use Drupal\KernelTests\KernelTestBase;

/**
 * Pins the contract that keeps n8n an assistant and never an agent.
 *
 * This is the most important test in the repo, and it is testing Drupal as much
 * as it is testing us. The rule — "n8n appears where an assistant picks a
 * provider, and nowhere an agent does" — is not implemented with form alters. It
 * falls out of two facts:
 *
 *   - we support `chat` and declare no capabilities;
 *   - ai_agents asks for the `chat_with_tools` / `chat_with_complex_json`
 *     pseudo-operations, which resolve to `chat` filtered by an
 *     AiModelCapability.
 *
 * So if a future Drupal release changes how capability filtering works, this
 * test goes red — and it should, loudly, because the guarantee would be gone
 * while everything still appeared to function.
 *
 * Spec: features/agent-exclusion.feature
 *
 * @group n8n
 * @group ai_provider_n8n
 *
 * @coversDefaultClass \Drupal\ai_provider_n8n\Plugin\AiProvider\N8nProvider
 */
class N8nProviderContractTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'file',
    'ai',
    'n8n',
    'ai_provider_n8n',
  ];

  /**
   * The AI provider plugin manager.
   */
  protected AiProviderPluginManager $providerManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['n8n']);
    $this->providerManager = $this->container->get('ai.provider');
  }

  /**
   * The plugin is discovered by the AI module.
   */
  public function testProviderIsDiscovered(): void {
    $definitions = $this->providerManager->getDefinitions();
    $this->assertArrayHasKey('n8n', $definitions, 'The n8n provider plugin is discovered by ai.provider.');
  }

  /**
   * We claim chat, and nothing else.
   *
   * @covers ::getSupportedOperationTypes
   */
  public function testSupportsChatAndNothingElse(): void {
    $provider = $this->providerManager->createInstance('n8n');
    $this->assertSame(['chat'], $provider->getSupportedOperationTypes());
  }

  /**
   * We declare no capabilities — this is the exclusion mechanism.
   *
   * If someone "helpfully" adds a capability here, n8n becomes selectable as an
   * agent brain and starts quietly misbehaving. That is why this is asserted
   * rather than left to the base class's default.
   */
  public function testDeclaresNoCapabilities(): void {
    $provider = $this->providerManager->createInstance('n8n');
    $this->assertSame([], $provider->getSupportedCapabilities(), 'The n8n provider must declare no model capabilities.');
  }

  /**
   * Anything asking for a capability is asking for a raw model. We refuse.
   *
   * @covers ::isUsable
   * @covers ::getConfiguredModels
   *
   * @dataProvider rawModelCapabilities
   */
  public function testRefusesCapabilityRequests(AiModelCapability $capability): void {
    $provider = $this->providerManager->createInstance('n8n');

    $this->assertFalse(
      $provider->isUsable('chat', [$capability]),
      sprintf('The n8n provider must not be usable for %s.', $capability->value),
    );
    $this->assertSame(
      [],
      $provider->getConfiguredModels('chat', [$capability]),
      sprintf('The n8n provider must offer no models for %s.', $capability->value),
    );
  }

  /**
   * The capabilities an agent-shaped caller asks for.
   */
  public static function rawModelCapabilities(): array {
    return [
      'tools / function calling' => [AiModelCapability::ChatTools],
      'complex JSON output' => [AiModelCapability::ChatJsonOutput],
      'structured response' => [AiModelCapability::ChatStructuredResponse],
    ];
  }

  /**
   * An unconfigured connection means no provider and no models.
   *
   * Without this, a site that has enabled the module but never set a URL would
   * offer n8n in the assistant dropdown and fail at chat time instead of simply
   * not being there yet.
   */
  public function testUnconfiguredProviderIsNotUsable(): void {
    $provider = $this->providerManager->createInstance('n8n');

    $this->assertFalse($provider->isUsable('chat'), 'An unconfigured n8n provider is not usable.');
    $this->assertSame([], $provider->getConfiguredModels('chat'), 'An unconfigured n8n provider offers no models.');
  }

  /**
   * We are not offered for an operation we do not support.
   */
  public function testUnsupportedOperationIsRefused(): void {
    $provider = $this->providerManager->createInstance('n8n');

    $this->assertFalse($provider->isUsable('embeddings'));
    $this->assertSame([], $provider->getConfiguredModels('embeddings'));
  }

}
