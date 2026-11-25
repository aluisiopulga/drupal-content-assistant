<?php

declare(strict_types=1);

namespace Drupal\content_assistant\Tool;

use Drupal\content_assistant\ContentLibrary;
use Drupal\tool\Tool\ToolBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for the content assistant tools.
 *
 * The tools are thin: they read their inputs and hand them to the content
 * library service, which does the querying and the access checks. Invalid
 * input is reported by throwing \InvalidArgumentException, which the Tool
 * API turns into a correctable failure for the caller.
 */
abstract class ContentToolBase extends ToolBase {

  /**
   * The content library.
   */
  protected ContentLibrary $library;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->library = $container->get('content_assistant.library');
    return $instance;
  }

  /**
   * A trimmed string input, or NULL when empty.
   */
  protected static function text(array $values, string $name): ?string {
    $value = $values[$name] ?? NULL;
    return is_string($value) && trim($value) !== '' ? trim($value) : NULL;
  }

}
