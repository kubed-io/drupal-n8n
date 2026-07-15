<?php

declare(strict_types=1);

namespace Drupal\n8n;

/**
 * TEMPORARY — a deliberate defect, to prove the code-scanning chain is alive.
 *
 * PHPStan reporting "0 findings" and PHPStan being silently broken look exactly
 * the same from outside: an empty Security tab either way. This class exists so
 * we can tell them apart once, by planting something the scanner MUST catch and
 * checking that it surfaces as a PR annotation and a code-scanning alert.
 *
 * @todo DELETE THIS FILE once the chain is confirmed. It is not a test fixture
 *   and must never reach main.
 */
class ScannerProbe {

  /**
   * Calls a method that does not exist.
   *
   * PHPStan flags this at level 0, so our level 1 must see it:
   * "Call to an undefined method Drupal\n8n\ScannerProbe::thisMethodDoesNotExist()"
   * — identifier method.notFound.
   *
   * Nothing calls this. It cannot affect runtime; it only has to be analysed.
   */
  public function proveTheScannerRuns(): string {
    return $this->thisMethodDoesNotExist();
  }

}
