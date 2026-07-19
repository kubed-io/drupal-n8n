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

}
