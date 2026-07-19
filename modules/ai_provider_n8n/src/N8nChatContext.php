<?php

declare(strict_types=1);

namespace Drupal\ai_provider_n8n;

/**
 * Holds the page the chat box is on, for the length of one request.
 *
 * The provider cannot see the page directly: when chat() runs it is handling the
 * chat POST, not the page the visitor is reading. Drupal knows the page only
 * upstream, in the chat context the block sends, which the assistant pipeline
 * hands to agents through AiAssistantPassContextToAgentEvent — fired just before
 * the message goes out. ChatContextSubscriber catches that event and stashes the
 * page path here; the provider reads it back in the same request when it builds
 * the Drupal signature.
 *
 * A plain container service is request-scoped in Drupal — the container is rebuilt
 * per request — so no reset is needed between requests.
 *
 * @see \Drupal\ai_provider_n8n\EventSubscriber\ChatContextSubscriber
 * @see \Drupal\ai_provider_n8n\Plugin\AiProvider\N8nProvider::pageContextMetadata()
 * @see features/page-context.feature
 */
class N8nChatContext {

  /**
   * The path of the page the chat box is on, or NULL when unknown.
   *
   * @var string|null
   */
  protected ?string $path = NULL;

  /**
   * How many times the provider posted to n8n during this request.
   *
   * A passthrough assistant must reach n8n exactly once per turn: selecting
   * Drupal agents hands n8n a list, it never fans out into a call per agent.
   * The provider tallies each chat() here so a test can prove that from inside
   * the same request, independent of whether n8n happens to persist the
   * execution — the reliable witness the "one call" guarantee needs.
   *
   * @var int
   */
  protected int $providerCalls = 0;

  /**
   * Records the page path the chat box reported.
   *
   * @param string|null $path
   *   The page path, or NULL to clear it.
   */
  public function setPath(?string $path): void {
    $this->path = $path;
  }

  /**
   * The page path the chat box is on, or NULL when there is none.
   *
   * @return string|null
   *   The page path.
   */
  public function getPath(): ?string {
    return $this->path;
  }

  /**
   * Tallies one provider call to n8n.
   */
  public function recordProviderCall(): void {
    $this->providerCalls++;
  }

  /**
   * Resets the provider-call tally to zero.
   */
  public function resetProviderCalls(): void {
    $this->providerCalls = 0;
  }

  /**
   * How many times the provider has posted to n8n this request.
   *
   * @return int
   *   The provider-call count.
   */
  public function getProviderCallCount(): int {
    return $this->providerCalls;
  }

}
