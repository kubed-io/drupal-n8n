<?php

declare(strict_types=1);

namespace Drupal\n8n\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\n8n\N8nClient;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures and verifies the n8n connection from the command line.
 *
 * These are not a convenience. A deployment lifecycle has to bake the connection
 * with no human at a form, which is why every admin action on the settings page
 * has an equivalent here, and why a broken connection must exit non-zero — that
 * is what lets an install script fail loudly instead of shipping a site that
 * cannot reach n8n.
 *
 * The API key is never passed on this command line. `n8n:set-key` takes the
 * machine name of a Key entity, so a secret never lands in shell history or a
 * process list. See SECURITY.md.
 *
 * @todo Phase 4 — a `--domain` option. Domain config overrides NEVER apply in
 *   CLI: `drush --uri` does not populate the domain negotiation context, so
 *   ConfigFactory returns global values here no matter what. That means these
 *   commands currently read and write the GLOBAL connection while a site serving
 *   a domain with an override uses a different one. See
 *   saga/Chapter_1_Packing_the_Van.md §9.1 and Phase 4.
 */
class N8nCommands extends DrushCommands {

  /**
   * The constructor.
   *
   * @param \Drupal\n8n\N8nClient $client
   *   The n8n client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected N8nClient $client,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  /**
   * Return an instance of these Drush commands.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   *
   * @return \Drupal\n8n\Drush\Commands\N8nCommands
   *   The instance of Drush commands.
   */
  public static function create(ContainerInterface $container): N8nCommands {
    return new N8nCommands(
      $container->get('n8n.client'),
      $container->get('config.factory'),
    );
  }

  /**
   * Point this site at an n8n instance.
   */
  #[CLI\Command(name: 'n8n:set-url')]
  #[CLI\Argument(name: 'url', description: 'Base URL of the n8n instance, e.g. https://n8n.example.com')]
  #[CLI\Usage(name: 'drush n8n:set-url https://n8n.example.com', description: 'Set the n8n base URL.')]
  public function setUrl(string $url): int {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      $this->logger()->error(dt('Not a valid URL: @url', ['@url' => $url]));
      return self::EXIT_FAILURE;
    }

    $this->configFactory->getEditable(N8nClient::CONFIG_NAME)
      ->set('base_url', rtrim($url, '/'))
      ->save();

    $this->logger()->success(dt('n8n base URL set to @url', ['@url' => rtrim($url, '/')]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Choose the Key entity that holds the n8n API key.
   */
  #[CLI\Command(name: 'n8n:set-key')]
  #[CLI\Argument(name: 'key_id', description: 'Machine name of a Key entity holding the n8n API key. NOT the key itself.')]
  #[CLI\Usage(name: 'drush n8n:set-key n8n_api_key', description: 'Use the n8n_api_key Key entity.')]
  public function setKey(string $key_id): int {
    // Fail on a key that does not exist rather than storing a dangling
    // reference that only breaks later, at chat time, on someone else's watch.
    if (!$this->client->keyExists($key_id)) {
      $this->logger()->error(dt('No Key entity named @id. Create one at /admin/config/system/keys first.', ['@id' => $key_id]));
      return self::EXIT_FAILURE;
    }

    $this->configFactory->getEditable(N8nClient::CONFIG_NAME)
      ->set('api_key', $key_id)
      ->save();

    $this->logger()->success(dt('n8n API key will be read from the @id key.', ['@id' => $key_id]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Verify the n8n connection — the headless Test connection button.
   */
  #[CLI\Command(name: 'n8n:test')]
  #[CLI\Usage(name: 'drush n8n:test', description: 'Check that this site can reach n8n with the configured key.')]
  public function test(): int {
    $result = $this->client->testConnection();

    if ($result['status'] === 'ok') {
      $this->logger()->success($result['message']);
      return self::EXIT_SUCCESS;
    }

    // Non-zero is the whole point: an install script has to be able to stop.
    $this->logger()->error($result['message']);
    return self::EXIT_FAILURE;
  }

}
