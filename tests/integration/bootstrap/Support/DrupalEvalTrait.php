<?php

declare(strict_types=1);

namespace Drupal\Tests\n8n\Integration\Support;

/**
 * Runs PHP inside the Drupal under test.
 *
 * For the surfaces drush has no command for yet.
 * drush php:eval chokes on any serious quoting, so the code is written to a temp
 * file and required — the same trick the live-cluster dev loop uses. Each helper
 * prints JSON on its last line so the step definitions get structured data back
 * rather than scraping human output.
 */
trait DrupalEvalTrait {

  /**
   * Evaluates PHP inside Drupal and returns the decoded JSON it printed.
   *
   * @param string $code
   *   PHP, without an opening tag, ending in something that echoes JSON.
   *
   * @return mixed
   *   The decoded JSON from the script's output.
   */
  protected function drupalEvalJson(string $code): mixed {
    $file = tempnam(sys_get_temp_dir(), 'behat-n8n-') . '.php';
    file_put_contents($file, "<?php\n" . $code);
    try {
      $output = $this->drush('php:eval', 'require "' . $file . '";');
    }
    finally {
      @unlink($file);
    }
    $decoded = json_decode($output, TRUE);
    if ($decoded === NULL && trim($output) !== 'null') {
      throw new \RuntimeException("Expected JSON from Drupal, got:\n$output");
    }
    return $decoded;
  }

  /**
   * The models the n8n provider offers right now, as {model_id: label}.
   */
  protected function providerModels(): array {
    return (array) $this->drupalEvalJson(<<<'PHP'
      $models = \Drupal::service('ai.provider')->createInstance('n8n')->getConfiguredModels('chat');
      echo json_encode($models);
      PHP);
  }

  /**
   * Sends one message through the real provider and returns the reply text.
   *
   * This is the exact call the assistant pipeline makes — same ChatInput, same
   * tags shape — minus the chat block above it.
   */
  protected function providerChat(string $model_id, string $message, string $session = 'behat-session', string $system_prompt = ''): string {
    $code = strtr(<<<'PHP'
      $input = new \Drupal\ai\OperationType\Chat\ChatInput([
        new \Drupal\ai\OperationType\Chat\ChatMessage('user', MESSAGE),
      ]);
      if (PROMPT !== '') {
        $input->setSystemPrompt(PROMPT);
      }
      $out = \Drupal::service('ai.provider')->createInstance('n8n')
        ->chat($input, MODEL, ['ai_agents', 'ai_agents_behat_helper', 'ai_agents_thread_' . SESSION]);
      echo json_encode(['text' => $out->getNormalized()->getText()]);
      PHP, [
        'MESSAGE' => var_export($message, TRUE),
        'MODEL' => var_export($model_id, TRUE),
        'SESSION' => var_export($session, TRUE),
        'PROMPT' => var_export($system_prompt, TRUE),
      ]);

    return (string) $this->drupalEvalJson($code)['text'];
  }

  /**
   * Creates an agent-backed assistant pointed at an n8n workflow.
   *
   * Mirrors what the assistant form does: a zero-tools companion agent whose
   * system_prompt is the assistant's instructions, plus an assistant that names
   * it and picks the n8n provider + the workflow as its model. Idempotent, so a
   * re-run replaces rather than collides.
   *
   * The two `[]` defaults are not optional: an ai_assistant saved with a null
   * llm_configuration or specific_error_messages becomes unloadable AND
   * undeletable through the entity API. See saga Chapter 2 §1.1c.
   */
  protected function createN8nAssistant(string $id, string $workflow_id, string $instructions, int $history_length = 2, string $allow_history = 'session_one_thread', array $roles = [], array $configuration = [], string $label = ''): void {
    $this->drupalEvalJson(strtr(<<<'PHP'
      $etm = \Drupal::entityTypeManager();
      foreach (['ai_assistant', 'ai_agent'] as $type) {
        if ($existing = $etm->getStorage($type)->load(ID)) {
          $existing->delete();
        }
      }
      $etm->getStorage('ai_agent')->create([
        'id' => ID, 'label' => ID, 'description' => 'behat',
        'system_prompt' => INSTRUCTIONS, 'tools' => [],
        'orchestration_agent' => TRUE, 'triage_agent' => FALSE, 'max_loops' => 3,
      ])->save();
      $etm->getStorage('ai_assistant')->create([
        'id' => ID, 'label' => LABEL, 'description' => 'behat', 'ai_agent' => ID,
        'llm_provider' => 'n8n', 'llm_model' => WORKFLOW, 'llm_configuration' => CONFIGURATION,
        'instructions' => INSTRUCTIONS, 'allow_history' => ALLOW_HISTORY,
        'history_context_length' => HISTORY, 'assistant_message' => 'hi',
        'no_results_message' => 'no results', 'error_message' => 'error: [error_message]',
        'specific_error_messages' => [], 'actions_enabled' => [], 'roles' => ROLES,
        'system_prompt' => '', 'pre_action_prompt' => '', 'preprompt_instructions' => '',
        'use_function_calling' => FALSE,
      ])->save();
      echo json_encode(TRUE);
      PHP, [
        'ID' => var_export($id, TRUE),
        'LABEL' => var_export($label !== '' ? $label : $id, TRUE),
        'WORKFLOW' => var_export($workflow_id, TRUE),
        'INSTRUCTIONS' => var_export($instructions, TRUE),
        'HISTORY' => var_export((string) $history_length, TRUE),
        'ALLOW_HISTORY' => var_export($allow_history, TRUE),
        'ROLES' => var_export($roles, TRUE),
        'CONFIGURATION' => var_export($configuration, TRUE),
      ]));
  }

  /**
   * Sends a multi-turn history through the provider and returns the echoed request.
   *
   * Proves C1: even handed a full conversation, the provider forwards only the
   * newest user message. The tags mirror what the agent runner produces.
   */
  protected function providerChatWithHistory(string $model_id, string $newest, string $session = 'behat-history'): string {
    return (string) $this->drupalEvalJson(strtr(<<<'PHP'
      $ns = 'Drupal\ai\OperationType\Chat\\';
      $input = new ($ns . 'ChatInput')([
        new ($ns . 'ChatMessage')('user', 'an older question'),
        new ($ns . 'ChatMessage')('assistant', 'an older answer'),
        new ($ns . 'ChatMessage')('user', NEWEST),
      ]);
      $out = \Drupal::service('ai.provider')->createInstance('n8n')
        ->chat($input, MODEL, ['ai_agents', 'ai_agents_behat_helper', 'ai_agents_thread_' . SESSION]);
      echo json_encode(['text' => $out->getNormalized()->getText()]);
      PHP, [
        'NEWEST' => var_export($newest, TRUE),
        'MODEL' => var_export($model_id, TRUE),
        'SESSION' => var_export($session, TRUE),
      ]))['text'];
  }

  /**
   * Sends a message through the full assistant pipeline and returns the reply.
   *
   * This is the whole product path minus the chat block: the runner runs the
   * companion agent, which calls our provider, which posts to n8n. Against the
   * Echo Agent fixture the reply text is the echoed request, so a caller can
   * read back exactly what n8n received.
   *
   * The chat runs as user 1 so a role-restricted assistant clears the runner's
   * access gate — the harness stands in for a logged-in visitor who may use the
   * assistant, which is the only path this suite exercises.
   *
   * The optional context is what the chat block would have sent — its
   * current_route is the page the box is on. It rides on the runner exactly as
   * the block sets it, so the page-context subscriber sees the same event it
   * sees in production. An empty context is the no-page default.
   *
   * Returns a JSON object: reply is the model's text (the Echo Agent's echoed
   * request), and provider_calls is how many times the provider posted to n8n
   * during the turn — the reliable, in-request witness for the one-call
   * passthrough, counted whether or not n8n persists the execution.
   *
   * @return string
   *   JSON: {"reply": string, "provider_calls": int}.
   */
  protected function chatThroughAssistant(string $id, string $message, array $context = []): string {
    return (string) $this->drupalEvalJson(strtr(<<<'PHP'
      $chat_context = \Drupal::service('n8n.chat_context');
      $chat_context->resetProviderCalls();
      $switcher = \Drupal::service('account_switcher');
      $admin = \Drupal::entityTypeManager()->getStorage('user')->load(1);
      $switcher->switchTo($admin);
      try {
        $runner = \Drupal::service('ai_assistant_api.runner');
        $runner->setAssistant(\Drupal::entityTypeManager()->getStorage('ai_assistant')->load(ID));
        $runner->setUserMessage(new \Drupal\ai_assistant_api\Data\UserMessage(MSG));
        $runner->setContext(CONTEXT);
        $runner->setThrowException(TRUE);
        $text = $runner->process()->getNormalized()->getText();
      }
      finally {
        $switcher->switchBack();
      }
      echo json_encode([
        'reply' => $text,
        'provider_calls' => $chat_context->getProviderCallCount(),
      ]);
      PHP, [
        'ID' => var_export($id, TRUE),
        'MSG' => var_export($message, TRUE),
        'CONTEXT' => var_export($context, TRUE),
      ]));
  }

  /**
   * Loads an assistant's shown transcript through the decorated runner.
   *
   * Goes through `ai_assistant_api.runner` — the service the n8n provider swaps
   * for N8nAssistantRunner — so an n8n-memory-mode assistant returns what the
   * workflow hands back, not Drupal's store. This is the exact call the chat box
   * makes when it paints prior turns on open.
   *
   * @return list<array{role: string, message: string}>
   *   The transcript the runner would show, oldest first.
   */
  protected function loadAssistantHistory(string $id): array {
    return (array) $this->drupalEvalJson(strtr(<<<'PHP'
      $runner = \Drupal::service('ai_assistant_api.runner');
      $runner->setAssistant(\Drupal::entityTypeManager()->getStorage('ai_assistant')->load(ID));
      echo json_encode(array_values($runner->getMessageHistory()));
      PHP, ['ID' => var_export($id, TRUE)]));
  }

}
