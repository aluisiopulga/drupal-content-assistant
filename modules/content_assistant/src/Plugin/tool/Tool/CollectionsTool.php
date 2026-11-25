<?php

declare(strict_types=1);

namespace Drupal\content_assistant\Plugin\tool\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\content_assistant\Tool\ContentToolBase;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\ListOutputDefinition;
use Drupal\tool\TypedData\MapOutputDefinition;

/**
 * Lists the content collections the assistant can work with.
 */
#[Tool(
  id: 'content_assistant_collections',
  label: new TranslatableMarkup('List content collections'),
  description: new TranslatableMarkup('Lists the content collections of this site that you can search (for example articles or news), with their groups (such as journal names) and whether drafts can be created. Call this first to learn the collection machine names and group names.'),
  operation: ToolOperation::Read,
  permission: 'use content assistant',
  output_definitions: [
    'collections' => new ListOutputDefinition(
      label: new TranslatableMarkup('Collections'),
      description: new TranslatableMarkup('One entry per collection.'),
      item_definition: new MapOutputDefinition(
        label: new TranslatableMarkup('Collection'),
        description: new TranslatableMarkup('Keys: collection (machine name), label, description, total (items you can see), groups (list of {value, label}), date_field, allows_drafts.'),
      ),
    ),
  ],
)]
final class CollectionsTool extends ContentToolBase {

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $collections = $this->library->describe();
    return ExecutableResult::success(
      new TranslatableMarkup('@count collections available.', ['@count' => count($collections)]),
      ['collections' => $collections],
    );
  }

}
