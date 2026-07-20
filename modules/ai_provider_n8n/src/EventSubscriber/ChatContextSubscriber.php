<?php

declare(strict_types=1);

namespace Drupal\ai_provider_n8n\EventSubscriber;

use Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent;
use Drupal\ai_provider_n8n\N8nChatContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Stashes the page the assistant is answering from into a request-scoped store.
 *
 * The assistant pipeline dispatches AiAssistantPassContextToAgentEvent just
 * before it runs the agent, carrying the chat context the block sent — today
 * that bundle's current_route is the page path. We copy it into N8nChatContext
 * so the n8n provider can read it back when it builds the Drupal signature, on
 * the same request, without the provider needing to know anything about pages.
 *
 * This fires for every agent-backed assistant, whatever its provider. Storing a
 * path is harmless when the provider is not n8n — nothing reads it — so there is
 * no provider check here.
 *
 * The event name is a string literal, NOT the event's ::EVENT_NAME constant, on
 * purpose: the module only depends on `ai:ai`, and the event class lives in the
 * optional ai_assistant_api submodule. Referencing the constant would autoload
 * that class when the subscriber list is compiled and fatal on a site without
 * ai_assistant_api. The literal keeps this subscriber inert-but-safe there; the
 * event simply never fires, and getSubscribedEvents() never touches the class.
 *
 * @see \Drupal\ai_provider_n8n\N8nChatContext
 * @see \Drupal\ai_provider_n8n\AiProviderN8nServiceProvider
 * @see features/page-context.feature
 */
class ChatContextSubscriber implements EventSubscriberInterface {

  /**
   * The event dispatched by ai_assistant_api just before the agent runs.
   */
  protected const PASS_CONTEXT_EVENT = 'ai_assistant.pass_context_to_agent';

  /**
   * The request-scoped store for the page the chat box is on.
   */
  protected N8nChatContext $chatContext;

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\ai_provider_n8n\N8nChatContext $chat_context
   *   The request-scoped page store.
   */
  public function __construct(N8nChatContext $chat_context) {
    $this->chatContext = $chat_context;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [self::PASS_CONTEXT_EVENT => 'onPassContext'];
  }

  /**
   * Records the page path the chat context carries.
   *
   * @param \Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent $event
   *   The context-passing event.
   */
  public function onPassContext(AiAssistantPassContextToAgentEvent $event): void {
    $route = $event->getContext()['current_route'] ?? NULL;
    if (is_string($route) && $route !== '') {
      $this->chatContext->setPath($route);
    }
  }

}
