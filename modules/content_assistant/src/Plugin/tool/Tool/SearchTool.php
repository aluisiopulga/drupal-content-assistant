<?php

declare(strict_types=1);

namespace Drupal\content_assistant\Plugin\tool\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\content_assistant\Tool\ContentToolBase;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\ListOutputDefinition;
use Drupal\tool\TypedData\MapOutputDefinition;
use Drupal\tool\TypedData\OutputDefinition;

/**
 * Searches a collection, newest first.
 */
#[Tool(
  id: 'content_assistant_search',
  label: new TranslatableMarkup('Search content'),
  description: new TranslatableMarkup('Finds items in a collection, newest first. With no keywords it returns the latest items, for example "the 10 latest articles of journal X": set group to the journal name and limit to 10. Keywords must all appear in the title or the searchable text. Only content you are allowed to view is returned.'),
  operation: ToolOperation::Read,
  permission: 'use content assistant',
  input_definitions: [
    'collection' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Collection'),
      description: new TranslatableMarkup('The collection machine name, from the "List content collections" tool.'),
      constraints: ['NotBlank' => []],
    ),
    'keywords' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Keywords'),
      description: new TranslatableMarkup('Words that must all appear in the item. Leave empty to list the latest items.'),
      required: FALSE,
    ),
    'group' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Group'),
      description: new TranslatableMarkup('A group name or part of it, such as a journal title or acronym. An exact name match wins over partial matches.'),
      required: FALSE,
    ),
    'date_from' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('From'),
      description: new TranslatableMarkup('Earliest date, YYYY-MM-DD.'),
      required: FALSE,
    ),
    'date_to' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('To'),
      description: new TranslatableMarkup('Latest date, YYYY-MM-DD.'),
      required: FALSE,
    ),
    'limit' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Limit'),
      description: new TranslatableMarkup('How many items to return. The site caps this value.'),
      required: FALSE,
      constraints: ['Range' => ['min' => 1, 'max' => 50]],
    ),
  ],
  output_definitions: [
    'total' => new OutputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Total'),
      description: new TranslatableMarkup('How many items match, including the ones not returned.'),
    ),
    'groups_matched' => new ListOutputDefinition(
      label: new TranslatableMarkup('Groups matched'),
      description: new TranslatableMarkup('The group names the group filter matched.'),
      item_definition: new OutputDefinition(
        data_type: 'string',
        label: new TranslatableMarkup('Group'),
        description: new TranslatableMarkup('A group name.'),
      ),
    ),
    'items' => new ListOutputDefinition(
      label: new TranslatableMarkup('Items'),
      description: new TranslatableMarkup('The items, newest first.'),
      item_definition: new MapOutputDefinition(
        label: new TranslatableMarkup('Item'),
        description: new TranslatableMarkup('Keys: id, title, date (YYYY-MM-DD), group, published, url.'),
      ),
    ),
  ],
)]
final class SearchTool extends ContentToolBase {

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $result = $this->library->search(
      (string) $values['collection'],
      self::text($values, 'keywords'),
      self::text($values, 'group'),
      self::text($values, 'date_from'),
      self::text($values, 'date_to'),
      isset($values['limit']) ? (int) $values['limit'] : NULL,
    );
    return ExecutableResult::success(
      new TranslatableMarkup('@shown of @total matching items returned.', [
        '@shown' => count($result['items']),
        '@total' => $result['total'],
      ]),
      $result,
    );
  }

}
