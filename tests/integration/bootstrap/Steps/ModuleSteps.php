<?php

declare(strict_types=1);

namespace Drupal\Tests\n8n\Integration\Steps;

use PHPUnit\Framework\Assert;

/**
 * The install gate every feature opens with.
 *
 * One step, and it earns its place: from the outside, "the module is
 * broken" and "the module was never enabled" look identical, and only one
 * of them is worth anybody's afternoon.
 */
trait ModuleSteps {

  /**
   * The modules that have to be on before any n8n behaviour is possible.
   *
   *   - n8n              the connection and its admin surface.
   *   - ai_provider_n8n  the provider that makes n8n an option.
   *   - key              where the API key lives, a hard dependency.
   */
  protected const REQUIRED_MODULES = ['n8n', 'ai_provider_n8n', 'key'];

  /**
   * Asserts this module, its provider submodule and Key are all enabled.
   *
   * The feature file says "the app", which is the sibling projects' word
   * for it; Drupal calls it a module, and the step reads the way the spec
   * does.
   *
   * Asked of the module handler rather than scraped from `pm:list`,
   * because the machine names overlap — `ai_provider_n8n` contains `n8n`,
   * so a substring check on a listing passes even when the module under
   * test is off.
   *
   * @Given the app is installed and enabled
   */
  public function theAppIsInstalledAndEnabled(): void {
    $enabled = (array) $this->drupalEvalJson(strtr(<<<'EVAL'
      $handler = \Drupal::moduleHandler();
      $enabled = [];
      foreach (json_decode('MODULES', TRUE) as $module) {
        if ($handler->moduleExists($module)) {
          $enabled[] = $module;
        }
      }
      echo json_encode($enabled);
      EVAL, ['MODULES' => json_encode(self::REQUIRED_MODULES)]));

    foreach (self::REQUIRED_MODULES as $module) {
      Assert::assertContains($module, $enabled, sprintf(
        'The "%s" module should be enabled on the site under test. Did the workflow install it?',
        $module,
      ));
    }
  }

}
