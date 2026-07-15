<?php

declare(strict_types=1);

namespace Drupal\Tests\n8n\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Proves the test harness itself works.
 *
 * This test asserts nothing about the module on purpose. It exists so that a red
 * suite means "the code is broken" rather than "the runner never started" — when
 * you are standing up CI, those two look identical from the outside.
 *
 * If this is the only test that fails, the problem is the harness: the bootstrap
 * path in phpunit.xml.dist, the autoloader, or the working directory. If this
 * passes and others fail, the problem is real.
 *
 * Do not delete it, and do not make it clever.
 *
 * @group n8n
 */
class HarnessTest extends UnitTestCase {

  /**
   * The runner runs.
   */
  public function testTheHarnessRuns(): void {
    $this->assertTrue(TRUE, 'PHPUnit executed a test in this module.');
  }

  /**
   * Drupal's UnitTestCase bootstrapped, so the Drupal test base is reachable.
   */
  public function testDrupalTestBaseIsAvailable(): void {
    $this->assertInstanceOf(UnitTestCase::class, $this);
  }

}
