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
   * With the opt-in off, nothing about the visitor or the roles travels.
   *
   * Even a role-restricted assistant stays silent — allowed_roles rides the same
   * one opt-in as the visitor's identity, so off means off for the whole block.
   */
  public function testOptInOffForwardsNothing(): void {
    $meta = $this->userContext(
      roles: ['anonymous' => 0, 'authenticated' => 0, 'content_editor' => 'content_editor', 'administrator' => 0],
      llmConfiguration: [],
    );

    $this->assertSame([], $meta);
  }

  /**
   * With the opt-in on, an open assistant forwards an EMPTY allowed-roles list.
   *
   * The key is always present under the opt-in, so a workflow never has to guess
   * whether it exists; an assistant open to everyone simply sends [].
   */
  public function testOptInOnOpenAssistantForwardsEmptyAllowedRoles(): void {
    $meta = $this->userContext(
      roles: ['anonymous' => 0, 'authenticated' => 0, 'content_editor' => 0, 'administrator' => 0],
      llmConfiguration: ['forward_user_context' => TRUE],
      userName: 'jdoe',
      userRoles: ['authenticated'],
    );

    $this->assertSame('jdoe', $meta['user'] ?? NULL);
    $this->assertSame(['authenticated'], $meta['user_roles'] ?? NULL);
    $this->assertSame([], $meta['allowed_roles'] ?? NULL);
  }

  /**
   * With the opt-in on, all three keys travel: visitor, roles, and the restriction.
   */
  public function testOptInOnRestrictedForwardsAllThree(): void {
    $meta = $this->userContext(
      roles: ['anonymous' => 0, 'authenticated' => 0, 'content_editor' => 'content_editor', 'administrator' => 0],
      llmConfiguration: ['forward_user_context' => TRUE],
      userName: 'jdoe',
      userRoles: ['authenticated', 'content_editor'],
    );

    $this->assertSame('jdoe', $meta['user'] ?? NULL);
    $this->assertSame(['authenticated', 'content_editor'], $meta['user_roles'] ?? NULL);
    $this->assertSame(['content_editor'], $meta['allowed_roles'] ?? NULL);
    // A user always carries a LIST of roles — never a scalar.
    $this->assertIsList($meta['user_roles']);
    $this->assertIsList($meta['allowed_roles']);
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
