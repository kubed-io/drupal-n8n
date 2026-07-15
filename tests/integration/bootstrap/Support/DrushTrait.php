<?php

declare(strict_types=1);

namespace Drupal\Tests\n8n\Integration\Support;

/**
 * Runs drush against the Drupal under test.
 *
 * This is the Drupal counterpart of the sibling project's OccTrait. Every admin
 * action this module offers has a drush equivalent — that is a product
 * requirement, not a testing convenience, because a deployment lifecycle has to
 * bake the connection with no human at a form. Which means the suite can drive
 * the real admin surface without a browser.
 */
trait DrushTrait {

  /**
   * The last drush invocation's stdout.
   */
  protected string $drushOutput = '';

  /**
   * The last drush invocation's exit code.
   */
  protected int $drushExitCode = 0;

  /**
   * Runs drush and captures output and exit code.
   *
   * Does NOT throw on a non-zero exit: several scenarios assert that a command
   * fails loudly, so the exit code is data, not an error.
   *
   * @param string ...$args
   *   Arguments to pass to drush.
   *
   * @return string
   *   Trimmed stdout.
   */
  protected function drush(string ...$args): string {
    $binary = getenv('DRUSH') ?: 'drush';

    $command = $binary . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';

    $output = [];
    $code = 0;
    exec($command, $output, $code);

    $this->drushOutput = trim(implode("\n", $output));
    $this->drushExitCode = $code;

    return $this->drushOutput;
  }

  /**
   * The exit code of the last drush call.
   */
  protected function drushExitCode(): int {
    return $this->drushExitCode;
  }

  /**
   * The stdout of the last drush call.
   */
  protected function drushOutput(): string {
    return $this->drushOutput;
  }

}
