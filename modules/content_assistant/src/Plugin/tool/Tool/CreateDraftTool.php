<?php

declare(strict_types=1);

namespace Drupal\content_assistant\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\content_assistant\Tool\ContentToolBase;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\OutputDefinition;

/**
 * Creates an unpublished draft.
 */
#[Tool(
  id: 'content_assistant_create_draft',
  label: new TranslatableMarkup('Create a draft'),
  description: new TranslatableMarkup('Creates an UNPUBLISHED draft (for example a news item) in a collection that allows drafts. Nothing is published: a person reviews and publishes the draft. Returns the edit link. Show the user the title and text and get their confirmation before calling this tool.'),
  operation: ToolOperation::Write,
  destructive: FALSE,
  permission: 'create drafts with content assistant',
  input_definitions: [
    'collection' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Collection'),
      description: new TranslatableMarkup('The collection machine name. It must allow drafts.'),
      constraints: ['NotBlank' => []],
    ),
    'title' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Title'),
      description: new TranslatableMarkup('The title, up to 255 characters.'),
      constraints: ['NotBlank' => []],
    ),
    'body' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Body'),
      description: new TranslatableMarkup('The text, as simple HTML (paragraphs, links, lists). It is filtered by the text format when displayed.'),
      constraints: ['MediaType' => ['type' => 'text/html']],
    ),
    'summary' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Summary'),
      description: new TranslatableMarkup('An optional short summary, used when the body field has one.'),
      required: FALSE,
    ),
  ],
  output_definitions: [
    'id' => new OutputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('ID'),
      description: new TranslatableMarkup('The new content ID.'),
    ),
    'title' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Title'),
      description: new TranslatableMarkup('The saved title.'),
    ),
    'status' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Status'),
      description: new TranslatableMarkup('Always "draft".'),
    ),
    'edit_url' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Edit URL'),
      description: new TranslatableMarkup('Where a person reviews and publishes the draft.'),
    ),
    'view_url' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('View URL'),
      description: new TranslatableMarkup('The draft page, visible only to people allowed to see unpublished content.'),
    ),
  ],
)]
final class CreateDraftTool extends ContentToolBase {

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $draft = $this->library->createDraft(
      (string) $values['collection'],
      (string) $values['title'],
      (string) $values['body'],
      self::text($values, 'summary'),
    );
    return ExecutableResult::success(
      new TranslatableMarkup('Draft "@title" created. Review and publish it at @url', [
        '@title' => $draft['title'],
        '@url' => $draft['edit_url'],
      ]),
      $draft,
    );
  }

  /**
   * {@inheritdoc}
   *
   * On top of the declared permission, the user must be able to create the
   * collection's content type.
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    $allowed = $this->library->canCreateDraft((string) ($values['collection'] ?? ''), $account);
    $result = AccessResult::allowedIf($allowed)->setCacheMaxAge(0);
    return $return_as_object ? $result : $result->isAllowed();
  }

}
