<?php

declare(strict_types=1);

namespace Drupal\ai_provider_n8n;

use Drupal\ai_assistant_api\AiAssistantApiRunner;
use Drupal\n8n\N8nClientInterface;

/**
 * Lets an n8n-backed assistant load its shown transcript from n8n's own memory.
 *
 * The base runner owns three "Allow history" modes: none, and two Session modes
 * that keep Drupal's own copy of the transcript in the visitor's session store.
 * This subclass adds one more — "Session (from n8n memory)" — for the n8n
 * provider only: instead of repainting the chat box from Drupal's copy, it asks
 * the workflow to hand back the conversation for this session and paints from
 * that. Drupal and n8n then show ONE transcript instead of two loosely-synced
 * copies.
 *
 * It is installed by swapping the class of the `ai_assistant_api.runner` service
 * in AiProviderN8nServiceProvider, so every consumer of that service — the chat
 * box that renders history, the controller that answers a turn — gets this
 * behaviour transparently. On any assistant that is not n8n + n8n-memory mode,
 * every method falls straight through to the parent, unchanged.
 *
 * WHY DISPLAY-ONLY, NOT REPLAYED TO THE AGENT. The n8n agent already threads its
 * own recall by sessionId through its memory node; feeding the loaded turns back
 * into the request would double-count them. N8nProvider::chat() forwards only the
 * newest user message regardless, so the loaded history reaches the chat box and
 * never the agent — recall stays n8n's job, display becomes shared.
 *
 * @see \Drupal\ai_provider_n8n\AiProviderN8nServiceProvider
 * @see \Drupal\n8n\N8nClientInterface::loadPreviousSession()
 * @see features/session-memory.feature
 * @see README.md#where-the-conversation-is-remembered
 */
class N8nAssistantRunner extends AiAssistantApiRunner {

  /**
   * The Allow history mode that sources the shown transcript from n8n.
   */
  public const HISTORY_MODE = 'session_from_n8n';

  /**
   * The provider id this mode is meaningful for.
   */
  protected const PROVIDER_ID = 'n8n';

  /**
   * The n8n client, prepended to the parent's constructor arguments.
   *
   * The remaining arguments are forwarded verbatim to the parent, so an upstream
   * change to the parent's constructor signature flows through without touching
   * this class.
   *
   * @var \Drupal\n8n\N8nClientInterface
   */
  protected N8nClientInterface $n8nClient;

  /**
   * Constructs the runner, prepending the n8n client to the parent's arguments.
   *
   * The tail is forwarded to the parent verbatim, so a change to the parent's
   * constructor signature flows through without touching this class.
   *
   * @param \Drupal\n8n\N8nClientInterface $n8n_client
   *   The n8n client, used to load a session's transcript.
   * @param mixed ...$arguments
   *   The parent runner's constructor arguments, in order.
   */
  public function __construct(N8nClientInterface $n8n_client, ...$arguments) {
    parent::__construct(...$arguments);
    $this->n8nClient = $n8n_client;
  }

  /**
   * {@inheritdoc}
   *
   * For the n8n-memory mode, give the conversation a stable per-assistant,
   * per-user key — the same shape as "one thread per session" — so the id we
   * send on both the chat turn and the loadPreviousSession call agree, and n8n's
   * memory threads them together.
   */
  public function getThreadsKey() {
    if ($this->isN8nHistoryMode() && $this->threadId === '') {
      $this->threadId = 'assistant_thread_' . $this->assistant->id() . '_' . $this->currentUser->id();
    }
    return parent::getThreadsKey();
  }

  /**
   * {@inheritdoc}
   *
   * For the n8n-memory mode, the transcript comes from the workflow, not the
   * Drupal session store. Every other mode is the parent's, untouched.
   */
  public function getMessageHistory() {
    if (!$this->isN8nHistoryMode()) {
      return parent::getMessageHistory();
    }
    return $this->loadHistoryFromN8n();
  }

  /**
   * Whether this assistant sources its shown transcript from n8n's memory.
   */
  protected function isN8nHistoryMode(): bool {
    return $this->assistant
      && $this->assistant->get('llm_provider') === self::PROVIDER_ID
      && $this->assistant->get('allow_history') === self::HISTORY_MODE;
  }

  /**
   * Loads and bounds the transcript n8n holds for this session.
   *
   * A load failure must never stop the chat box opening, so any error yields an
   * empty transcript — the box simply opens fresh. The result is bounded the
   * same way the base runner bounds a Drupal-stored transcript: the last
   * history_context_length pairs plus the newest message.
   *
   * @return list<array{role: string, message: string}>
   *   The past turns, oldest first.
   */
  protected function loadHistoryFromN8n(): array {
    $workflow_id = (string) $this->assistant->get('llm_model');
    if ($workflow_id === '') {
      return [];
    }

    try {
      $history = $this->n8nClient->loadPreviousSession($workflow_id, $this->getThreadsKey());
    }
    catch (\Throwable) {
      return [];
    }

    if ($history) {
      $keep = (int) $this->assistant->get('history_context_length') * 2 + 1;
      $history = array_slice($history, -$keep, $keep);
    }

    return $history;
  }

}
