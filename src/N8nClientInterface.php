<?php

declare(strict_types=1);

namespace Drupal\n8n;

/**
 * Reads the n8n connection and talks to its REST API.
 *
 * Exists so consumers depend on the contract rather than the class — the AI
 * provider, the drush commands and a future webform handler all type-hint this.
 *
 * @see \Drupal\n8n\N8nClient
 * @see https://docs.n8n.io/api/
 */
interface N8nClientInterface {

  /**
   * Whether an admin has set both a base URL and a key.
   *
   * A configuration check, not a reachability one — callers on a request path
   * want this, because only testConnection() should touch the network.
   */
  public function isConfigured(): bool;

  /**
   * The configured base URL, without a trailing slash.
   */
  public function getBaseUrl(): string;

  /**
   * Resolves the n8n API key through the Key module.
   *
   * Empty rather than throwing when the key is missing or its entity was
   * deleted; callers decide whether that is fatal.
   */
  public function getApiKey(): string;

  /**
   * Whether a Key entity with this machine name exists.
   */
  public function keyExists(string $key_id): bool;

  /**
   * Verifies the URL and key against a live n8n.
   *
   * @return array
   *   'status' is 'ok' or 'error'; 'message' is translatable and safe to show a
   *   user.
   *
   * @see \Drupal\n8n\Form\N8nSettingsForm::testConnection()
   */
  public function testConnection(): array;

  /**
   * Issues a request against the n8n public API.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   A path under the base URL, starting with a slash.
   * @param array $query
   *   Query parameters.
   *
   * @return array
   *   The decoded JSON body, or an empty array if it was not JSON.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   *   When the request fails. Callers decide how loud that should be.
   */
  public function request(string $method, string $path, array $query = []): array;

  /**
   * Lists the workflows that qualify as chat models.
   *
   * A workflow qualifies when it is active and contains a Chat Trigger with
   * "Make Chat Publicly Available" switched on — an inactive or non-public
   * trigger has no registered webhook and cannot answer.
   *
   * @return array<string, array{label: string, webhook_id: string}>
   *   Keyed by workflow id.
   */
  public function listChatWorkflows(): array;

  /**
   * Sends one chat message to a workflow's chat webhook and returns the reply.
   *
   * @param string $workflow_id
   *   The workflow to talk to; must be one listChatWorkflows() returned.
   * @param string $session_id
   *   The conversation key; the workflow's memory node threads on it.
   * @param string $message
   *   The user's message.
   * @param array $metadata
   *   Arbitrary context the workflow may read as {{ $json.metadata }}.
   *
   * @return string
   *   The agent's answer.
   *
   * @throws \RuntimeException
   *   When the workflow is unknown, unreachable, or answers with nothing.
   */
  public function chatSend(string $workflow_id, string $session_id, string $message, array $metadata = []): string;

  /**
   * Loads a session's transcript from a workflow's chat memory.
   *
   * Posts n8n's `loadPreviousSession` action to the chat webhook — the same call
   * n8n's own `@n8n/chat` widget makes when it reopens a conversation — and maps
   * the reply into Drupal's history shape. It only returns turns when the
   * workflow answers with a retrieving memory (Postgres Chat Memory, or a
   * workflow that responds to the action by hand); Simple Memory or no memory
   * node yields an empty transcript.
   *
   * @param string $workflow_id
   *   The workflow to ask; must be one listChatWorkflows() returned.
   * @param string $session_id
   *   The conversation key whose transcript to load.
   *
   * @return list<array{role: string, message: string}>
   *   The past turns, oldest first, roles normalised to user/assistant/system.
   *   Empty when the workflow has no transcript for this session.
   *
   * @throws \RuntimeException
   *   When the workflow is unknown or unreachable.
   */
  public function loadPreviousSession(string $workflow_id, string $session_id): array;

}
