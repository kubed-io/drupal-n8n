<?php

declare(strict_types=1);

namespace Drupal\n8n\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\n8n\N8nClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures the connection to an n8n instance.
 *
 * Deliberately small: a URL, a Key entity, a timeout. Everything else about n8n
 * is configured in n8n.
 *
 * @see README.md#who-owns-what
 * @see features/admin-connection.feature
 */
class N8nSettingsForm extends ConfigFormBase {

  // The client owns the config name; referencing it here keeps the form, the
  // client and the drush commands on the same object by construction.
  public const CONFIG_NAME = N8nClient::CONFIG_NAME;

  /**
   * The n8n client.
   */
  protected N8nClient $client;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->client = $container->get('n8n.client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'n8n_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::CONFIG_NAME);

    $form['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('n8n base URL'),
      '#description' => $this->t('For example <code>https://n8n.example.com</code>, or an in-cluster address like <code>http://n8n:5678</code>. No trailing slash.'),
      '#default_value' => $config->get('base_url'),
      '#required' => TRUE,
    ];

    // We store only the Key entity's machine name, so the secret can live in a
    // file, an env var or a secrets manager — and can never be echoed back here.
    // @see SECURITY.md#secrets-policy
    $form['api_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('n8n API key'),
      '#description' => $this->t('The key holding your n8n API key. Used to list workflows. Create one at <a href=":url">Keys</a>.', [
        ':url' => '/admin/config/system/keys',
      ]),
      '#default_value' => $config->get('api_key'),
      '#required' => TRUE,
    ];

    $form['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Request timeout'),
      '#description' => $this->t('Seconds to wait for n8n before giving up. Keep this below your web server timeout so a slow agent fails cleanly rather than hanging a page.'),
      '#default_value' => $config->get('timeout') ?: 30,
      '#min' => 1,
      '#max' => 300,
      '#field_suffix' => $this->t('seconds'),
    ];

    $form['test'] = [
      '#type' => 'details',
      '#title' => $this->t('Test connection'),
      '#open' => TRUE,
      '#description' => $this->t('Save your settings first, then test. This asks n8n for one workflow.'),
    ];
    $form['test']['test_connection'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test connection'),
      '#submit' => ['::testConnection'],
      // Not a config save, so do not validate the required fields above.
      '#limit_validation_errors' => [],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Submit handler for the Test connection button.
   */
  public function testConnection(array &$form, FormStateInterface $form_state): void {
    $result = $this->client->testConnection();

    if ($result['status'] === 'ok') {
      $this->messenger()->addStatus($result['message']);
      return;
    }
    $this->messenger()->addError($result['message']);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::CONFIG_NAME)
      ->set('base_url', rtrim((string) $form_state->getValue('base_url'), '/'))
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('timeout', (int) $form_state->getValue('timeout'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
