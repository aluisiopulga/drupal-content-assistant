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
 * Counts a collection by group or by year.
 */
#[Tool(
  id: 'content_assistant_report',
  label: new TranslatableMarkup('Count content'),
  description: new TranslatableMarkup('Counts the items of a collection per group (for example articles per journal) or per year, optionally filtered by group and date range. Use it for reports and totals instead of listing items.'),
  operation: ToolOperation::Read,
  permission: 'use content assistant',
  input_definitions: [
    'collection' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Collection'),
      description: new TranslatableMarkup('The collection machine name, from the "List content collections" tool.'),
      constraints: ['NotBlank' => []],
    ),
    'by' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Count by'),
      description: new TranslatableMarkup('"group" or "year".'),
      constraints: ['Choice' => ['choices' => ['group', 'year']]],
    ),
    'group' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Group'),
      description: new TranslatableMarkup('Only count items of this group (name or part of it).'),
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
  ],
  output_definitions: [
    'total' => new OutputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Total'),
      description: new TranslatableMarkup('How many items match the filters.'),
    ),
    'by' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Count by'),
      description: new TranslatableMarkup('"group" or "year".'),
    ),
    'rows' => new ListOutputDefinition(
      label: new TranslatableMarkup('Rows'),
      description: new TranslatableMarkup('One row per group (largest first) or per year (newest first).'),
      item_definition: new MapOutputDefinition(
        label: new TranslatableMarkup('Row'),
        description: new TranslatableMarkup('Keys: key (group name or year), count.'),
      ),
    ),
  ],
)]
final class ReportTool extends ContentToolBase {

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $result = $this->library->report(
      (string) $values['collection'],
      (string) $values['by'],
      self::text($values, 'group'),
      self::text($values, 'date_from'),
      self::text($values, 'date_to'),
    );
    return ExecutableResult::success(
      new TranslatableMarkup('@total items counted.', ['@total' => $result['total']]),
      $result,
    );
  }

}
