<?php

declare(strict_types=1);

namespace Drupal\Tests\n8n\Integration\Support;

/**
 * Runs PHP inside the Drupal under test.
 *
 * For the surfaces drush has no command for — chiefly the AI module's provider
 * services, which have no CLI of their own.
 *
 * `drush php:eval` chokes on any serious quoting, so the code is written to a
 * temp file and required — the same trick the live-cluster dev loop uses. Every
 * helper prints JSON on its last line, so step definitions get structured data
 * back rather than scraping human output.
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

}
