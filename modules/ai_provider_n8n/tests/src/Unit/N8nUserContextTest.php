<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_n8n\Unit;

use Drupal\ai_provider_n8n\Plugin\AiProvider\N8nProvider;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Tests\UnitTestCase;

/**
 * The visitor identity + access-list half of the Drupal signature.
 *
 * Proves userContextMetadata() in isolation: allowed_roles is the assistant's
 * OWN enabled roles (context, not a gate — Drupal already enforced it), and
 * user/user_roles are PII that travel ONLY when the assistant opts in. Every
 * key is absent when it has nothing to say.
 *
 * Spec: features/user-context.feature
 *
 * @group n8n
 * @group ai_provider_n8n
 *
 * @coversDefaultClass \Drupal\ai_provider_n8n\Plugin\AiProvider\N8nProvider
 */
class N8nUserContextTest extends UnitTestCase {

  /**
   * A restricted assistant forwards its enabled roles; opt-in off sends no PII.
   */
  public function testRestrictedAssistantForwardsAllowedRolesOnly(): void {
    $meta = $this->userContext(
      roles: ['anonymous' => 0, 'authenticated' => 0, 'content_editor' => 'content_editor', 'administrator' => 0],
      llmConfiguration: [],
    );

    $this->assertSame(['content_editor'], $meta['allowed_roles'] ?? NULL);
    $this->assertArrayNotHasKey('user', $meta);
    $this->assertArrayNotHasKey('user_roles', $meta);
  }

  /**
   * An assistant open to everyone (no role enabled) forwards nothing here.
   */
  public function testUnrestrictedAssistantForwardsNoAllowedRoles(): void {
    $meta = $this->userContext(
      roles: ['anonymous' => 0, 'authenticated' => 0, 'content_editor' => 0, 'administrator' => 0],
      llmConfiguration: [],
    );

    $this->assertSame([], $meta);
  }

  /**
   * With the opt-in on, the visitor's name and role list travel.
   */
  public function testOptInForwardsVisitorIdentity(): void {
    $meta = $this->userContext(
      roles: [],
      llmConfiguration: ['forward_user_context' => TRUE],
      userName: 'jdoe',
      userRoles: ['authenticated', 'content_editor'],
    );

    $this->assertSame('jdoe', $meta['user'] ?? NULL);
    $this->assertSame(['authenticated', 'content_editor'], $meta['user_roles'] ?? NULL);
    // A user always carries a LIST of roles — never a scalar.
    $this->assertIsList($meta['user_roles']);
  }

  /**
   * A missing assistant yields nothing rather than erroring.
   */
  public function testMissingAssistantYieldsEmpty(): void {
    $this->assertSame([], $this->userContext(assistantExists: FALSE));
  }

  /**
   * Builds the provider with mocked services and returns userContextMetadata().
   */
  private function userContext(
    array $roles = [],
    array $llmConfiguration = [],
    string $userName = 'someone',
    array $userRoles = ['authenticated'],
    bool $assistantExists = TRUE,
  ): array {
    $assistant = $this->createMock(ConfigEntityInterface::class);
    $assistant->method('get')->willReturnMap([
      ['roles', $roles],
      ['llm_configuration', $llmConfiguration],
    ]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($assistantExists ? $assistant : NULL);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->with('ai_assistant')->willReturn(TRUE);
    $etm->method('getStorage')->with('ai_assistant')->willReturn($storage);

    $currentUser = $this->createMock(AccountProxyInterface::class);
    $currentUser->method('getAccountName')->willReturn($userName);
    $currentUser->method('getRoles')->willReturn($userRoles);

    $provider = (new \ReflectionClass(N8nProvider::class))->newInstanceWithoutConstructor();
    $this->setProtected($provider, 'entityTypeManager', $etm);
    $this->setProtected($provider, 'currentUser', $currentUser);

    $method = new \ReflectionMethod($provider, 'userContextMetadata');
    $method->setAccessible(TRUE);
    return $method->invoke($provider, 'kubed_assistant');
  }

  /**
   * Sets a protected property on the provider instance.
   */
  private function setProtected(object $object, string $property, mixed $value): void {
    $ref = new \ReflectionProperty($object, $property);
    $ref->setAccessible(TRUE);
    $ref->setValue($object, $value);
  }

}
