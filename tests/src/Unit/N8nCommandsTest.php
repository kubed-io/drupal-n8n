<?php

declare(strict_types=1);

namespace Drupal\Tests\n8n\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\n8n\Drush\Commands\N8nCommands;
use Drupal\n8n\N8nClientInterface;
use Drupal\Tests\UnitTestCase;
use Drush\Log\DrushLoggerManager;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests the drush commands against a mock client and a real Drush logger type.
 *
 * The logger is mocked from DrushLoggerManager rather than LoggerInterface on
 * purpose: PHPUnit keeps the mocked signature, so `success(string $message)`
 * still rejects a TranslatableMarkup here exactly as it does in a live install.
 * A LoggerInterface mock would accept anything and prove nothing — which is how
 * a TypeError reached v0.0.2.
 *
 * Spec: features/admin-connection.feature
 *
 * @group n8n
 *
 * @coversDefaultClass \Drupal\n8n\Drush\Commands\N8nCommands
 */
class N8nCommandsTest extends UnitTestCase {

  /**
   * Builds the commands over a client whose testConnection() returns $result.
   */
  protected function buildCommands(array $result, MockObject $logger): N8nCommands {
    $client = $this->createMock(N8nClientInterface::class);
    $client->method('testConnection')->willReturn($result);

    $commands = new N8nCommands(
      $client,
      $this->createMock(ConfigFactoryInterface::class),
    );
    $commands->setLogger($logger);

    return $commands;
  }

  /**
   * A message as the client really returns one.
   *
   * The translation service is passed explicitly: unset, TranslatableMarkup
   * reaches for \Drupal::translation() when cast, which no unit test has.
   */
  protected function message(string $text): TranslatableMarkup {
    return new TranslatableMarkup($text, [], [], $this->getStringTranslationStub());
  }

  /**
   * A reachable n8n exits zero and says so, without tripping over the message.
   *
   * The message arrives as TranslatableMarkup because the settings form renders
   * it; drush's logger takes a string. Regression test for the TypeError that
   * failed the install job while the connection itself was fine.
   *
   * @covers ::test
   */
  public function testSuccessIsReportedAsStringAndExitsZero(): void {
    $logger = $this->createMock(DrushLoggerManager::class);
    $logger->expects($this->once())
      ->method('success')
      ->with('Connected to n8n.');

    $commands = $this->buildCommands([
      'status' => 'ok',
      'message' => $this->message('Connected to n8n.'),
    ], $logger);

    $this->assertSame(N8nCommands::EXIT_SUCCESS, $commands->test());
  }

  /**
   * A broken connection exits non-zero — this is what stops an install script.
   *
   * @covers ::test
   */
  public function testFailureIsReportedAsStringAndExitsNonZero(): void {
    $logger = $this->createMock(DrushLoggerManager::class);
    $logger->expects($this->once())
      ->method('error')
      ->with('n8n rejected the API key.');

    $commands = $this->buildCommands([
      'status' => 'error',
      'message' => $this->message('n8n rejected the API key.'),
    ], $logger);

    $this->assertNotSame(N8nCommands::EXIT_SUCCESS, $commands->test());
  }

}
