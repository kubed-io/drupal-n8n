<?php

declare(strict_types=1);

namespace Drupal\ai_provider_n8n;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Teaches the assistant runner to load history from n8n, when n8n is present.
 *
 * The runner lives in ai_assistant_api, an optional submodule of the AI module.
 * We only depend on `ai:ai`, so the runner service may or may not exist. This
 * provider swaps its class to N8nAssistantRunner IN PLACE — same service id,
 * same arguments, plus the n8n client prepended — but only when the service is
 * actually defined. If ai_assistant_api is not installed, it does nothing and
 * the provider still works for everything else.
 *
 * Copying the parent's arguments rather than hard-coding them, and prepending
 * the one new dependency, keeps this resilient to upstream constructor changes:
 * N8nAssistantRunner forwards the tail to the parent untouched.
 *
 * @see \Drupal\ai_provider_n8n\N8nAssistantRunner
 */
class AiProviderN8nServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if (!$container->hasDefinition('ai_assistant_api.runner')) {
      // ai_assistant_api is not installed — there is nothing to extend.
      return;
    }

    $definition = $container->getDefinition('ai_assistant_api.runner');
    $arguments = $definition->getArguments();
    array_unshift($arguments, new Reference('n8n.client'));

    $definition->setClass(N8nAssistantRunner::class);
    $definition->setArguments($arguments);
  }

}
