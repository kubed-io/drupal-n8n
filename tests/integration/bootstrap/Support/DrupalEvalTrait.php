<?php

declare(strict_types=1);

namespace Drupal\Tests\n8n\Integration\Support;

/**
 * Runs PHP inside the Drupal under test, for the surfaces drush has no command
 * for yet.
 *
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

}
