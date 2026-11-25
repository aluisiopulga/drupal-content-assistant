<?php

declare(strict_types=1);

namespace Drupal\content_assistant;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Reads and drafts content for the assistant tools.
 *
 * A "collection" is a content type the site administrator exposed to the
 * assistant, with the fields that give it meaning: the date that orders it,
 * the field that groups it (a journal, a category, a department) and the
 * text fields that keyword searches look in.
 *
 * Every read goes through entity queries with access checks, and every item
 * is checked again with the "view" operation, so the assistant never shows
 * content the current user could not open in the browser.
 */
class ContentLibrary {

  /**
   * Hard limit on items per call, whatever the configuration says.
   */
  public const HARD_LIMIT = 50;

  /**
   * Field types the group field may have.
   */
  public const GROUP_FIELD_TYPES = [
    'entity_reference',
    'list_string',
    'list_integer',
    'string',
  ];

  /**
   * Field types the date field may have.
   */
  public const DATE_FIELD_TYPES = ['created', 'changed', 'timestamp', 'datetime'];

  /**
   * Field types keyword searches can look in.
   */
  public const SEARCH_FIELD_TYPES = [
    'string',
    'string_long',
    'text',
    'text_long',
    'text_with_summary',
  ];

  /**
   * Field types a draft body can be written to.
   */
  public const BODY_FIELD_TYPES = ['text_long', 'text_with_summary', 'string_long'];

  /**
   * Base fields never offered in the settings.
   *
   * The title is always searched, and the others are bookkeeping.
   */
  public const HIDDEN_FIELDS = [
    'title',
    'type',
    'revision_log',
    'revision_timestamp',
    'revision_uid',
  ];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected AccountInterface $currentUser,
    protected DateFormatterInterface $dateFormatter,
    protected LoggerChannelInterface $logger,
    protected ?object $formatRepository = NULL,
  ) {}

  /**
   * The configured collections, keyed by machine name.
   *
   * @return array<string, array>
   *   Collection settings.
   */
  public function collections(): array {
    $collections = [];
    foreach ($this->configFactory->get('content_assistant.settings')->get('collections') ?? [] as $collection) {
      $collections[$collection['id']] = $collection + [
        'description' => '',
        'date_field' => 'created',
        'group_field' => '',
        'search_fields' => [],
        'allow_drafts' => FALSE,
        'body_field' => '',
      ];
    }
    return $collections;
  }

  /**
   * One collection.
   *
   * @throws \InvalidArgumentException
   *   When there is no collection with that machine name.
   */
  public function collection(string $id): array {
    $collections = $this->collections();
    if (!isset($collections[$id])) {
      $known = $collections ? implode(', ', array_keys($collections)) : 'none';
      throw new \InvalidArgumentException(sprintf('Unknown collection "%s". Available collections: %s.', $id, $known));
    }
    return $collections[$id];
  }

  /**
   * The maximum number of items one call may return.
   */
  public function maxResults(): int {
    $configured = (int) ($this->configFactory->get('content_assistant.settings')->get('max_results') ?: 20);
    return max(1, min($configured, self::HARD_LIMIT));
  }

  /**
   * Describes every collection for the assistant.
   *
   * @return array<int, array>
   *   One entry per collection: machine name, label, description, how many
   *   items the user can see, the groups and whether drafts are allowed.
   */
  public function describe(): array {
    $result = [];
    foreach ($this->collections() as $id => $collection) {
      $result[] = [
        'collection' => $id,
        'label' => $collection['label'],
        'description' => $collection['description'],
        'total' => $this->count($this->baseQuery($collection)),
        'groups' => $collection['group_field'] ? $this->groups($collection) : [],
        'date_field' => $collection['date_field'],
        'allows_drafts' => (bool) $collection['allow_drafts'],
      ];
    }
    return $result;
  }

  /**
   * Searches a collection, newest first.
   *
   * @param string $id
   *   The collection machine name.
   * @param string|null $keywords
   *   Words that must all appear in the title or a searchable field.
   * @param string|null $group
   *   Part of a group name, such as a journal title or acronym.
   * @param string|null $from
   *   Earliest date, YYYY-MM-DD.
   * @param string|null $to
   *   Latest date, YYYY-MM-DD.
   * @param int|null $limit
   *   How many items to return. Capped by the configuration.
   *
   * @return array{total: int, items: array<int, array>, groups_matched: array<int, string>}
   *   The number of matches, the items and the groups the filter matched.
   */
  public function search(string $id, ?string $keywords = NULL, ?string $group = NULL, ?string $from = NULL, ?string $to = NULL, ?int $limit = NULL): array {
    $collection = $this->collection($id);
    $query = $this->baseQuery($collection);

    $groups_matched = [];
    if ($group !== NULL && trim($group) !== '') {
      $groups_matched = $this->applyGroup($query, $collection, trim($group));
      if ($groups_matched === []) {
        return ['total' => 0, 'items' => [], 'groups_matched' => []];
      }
    }
    if ($keywords !== NULL && trim($keywords) !== '') {
      $this->applyKeywords($query, $collection, $keywords);
    }
    $this->applyDates($query, $collection, $from, $to);

    $total = $this->count(clone $query);
    $limit = max(1, min($limit ?? $this->maxResults(), $this->maxResults()));
    $ids = $query
      ->sort($collection['date_field'], 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, $limit)
      ->execute();

    $items = [];
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($ids) as $node) {
      if ($node instanceof NodeInterface && $node->access('view', $this->currentUser)) {
        $items[] = $this->summarize($node, $collection);
      }
    }

    return ['total' => $total, 'items' => $items, 'groups_matched' => array_values($groups_matched)];
  }

  /**
   * Counts a collection by group or by year.
   *
   * @return array{total: int, by: string, rows: array<int, array{key: string, count: int}>}
   *   One row per group or year, largest groups or newest years first.
   */
  public function report(string $id, string $by, ?string $group = NULL, ?string $from = NULL, ?string $to = NULL): array {
    $collection = $this->collection($id);
    if (!in_array($by, ['group', 'year'], TRUE)) {
      throw new \InvalidArgumentException('"by" must be "group" or "year".');
    }
    if ($by === 'group' && !$collection['group_field']) {
      throw new \InvalidArgumentException(sprintf('The collection "%s" has no group field.', $id));
    }

    $prepare = function (QueryInterface $query) use ($collection, $group, $from, $to): bool {
      if ($group !== NULL && trim($group) !== '' && $this->applyGroup($query, $collection, trim($group)) === []) {
        return FALSE;
      }
      $this->applyDates($query, $collection, $from, $to);
      return TRUE;
    };

    $query = $this->baseQuery($collection);
    if (!$prepare($query)) {
      return ['total' => 0, 'by' => $by, 'rows' => []];
    }
    $total = $this->count(clone $query);

    $rows = $by === 'group'
      ? $this->countByGroup($collection, $prepare)
      : $this->countByYear($collection, $prepare);

    return ['total' => $total, 'by' => $by, 'rows' => $rows];
  }

  /**
   * Creates an unpublished draft in a collection that allows it.
   *
   * Access is not checked here: the tool checks it before this runs.
   *
   * @return array{id: int, title: string, status: string, edit_url: string, view_url: string}
   *   The new draft.
   *
   * @throws \InvalidArgumentException
   *   When the collection does not allow drafts or the values are invalid.
   */
  public function createDraft(string $id, string $title, string $body, ?string $summary = NULL): array {
    $collection = $this->collection($id);
    if (!$collection['allow_drafts']) {
      throw new \InvalidArgumentException(sprintf('The collection "%s" does not allow drafts.', $id));
    }
    $title = trim($title);
    if ($title === '') {
      throw new \InvalidArgumentException('The title must not be empty.');
    }

    $values = [
      'type' => $collection['bundle'],
      'title' => mb_substr($title, 0, 255),
      'uid' => $this->currentUser->id(),
      'status' => 0,
    ];

    $definitions = $this->fieldDefinitions($collection['bundle']);
    $body_field = $collection['body_field'];
    if ($body_field && isset($definitions[$body_field])) {
      $type = $definitions[$body_field]->getType();
      // The text comes from an AI model, which may have read untrusted
      // content. Strip scripts, styles and event attributes whatever text
      // format the user's default is.
      $item = ['value' => $type === 'string_long' ? strip_tags($body) : Xss::filterAdmin($body)];
      if ($type !== 'string_long') {
        $item['format'] = $this->draftFormat();
      }
      if ($type === 'text_with_summary' && $summary !== NULL) {
        $item['summary'] = Xss::filterAdmin($summary);
      }
      $values[$body_field] = $item;
    }
    if (isset($definitions['moderation_state'])) {
      $values['moderation_state'] = 'draft';
    }

    $node = $this->entityTypeManager->getStorage('node')->create($values);
    $violations = $node->validate();
    if ($violations->count() > 0) {
      $messages = [];
      foreach ($violations as $violation) {
        $messages[] = $violation->getPropertyPath() . ': ' . strip_tags((string) $violation->getMessage());
      }
      throw new \InvalidArgumentException('The draft is not valid. ' . implode(' ', $messages));
    }
    $node->save();

    $this->logger->info('Draft %title (node @nid) created in collection %collection by the content assistant.', [
      '%title' => $node->label(),
      '@nid' => $node->id(),
      '%collection' => $id,
    ]);

    return [
      'id' => (int) $node->id(),
      'title' => (string) $node->label(),
      'status' => 'draft',
      'edit_url' => $node->toUrl('edit-form', ['absolute' => TRUE])->toString(),
      'view_url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ];
  }

  /**
   * Whether the current user may create drafts in a collection.
   */
  public function canCreateDraft(string $id, ?AccountInterface $account = NULL): bool {
    $collections = $this->collections();
    if (empty($collections[$id]['allow_drafts'])) {
      return FALSE;
    }
    return $this->entityTypeManager->getAccessControlHandler('node')
      ->createAccess($collections[$id]['bundle'], $account ?? $this->currentUser);
  }

  /**
   * Field definitions of a content type.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface[]
   *   Keyed by field name.
   */
  public function fieldDefinitions(string $bundle): array {
    return $this->entityFieldManager->getFieldDefinitions('node', $bundle);
  }

  /**
   * Field names of a content type with one of the given types.
   *
   * @return array<string, string>
   *   Field labels keyed by field name.
   */
  public function fieldOptions(string $bundle, array $types): array {
    $options = [];
    foreach ($this->fieldDefinitions($bundle) as $name => $definition) {
      if (in_array($definition->getType(), $types, TRUE) && !in_array($name, self::HIDDEN_FIELDS, TRUE)) {
        $options[$name] = $definition->getLabel() . ' (' . $name . ')';
      }
    }
    return $options;
  }

  /**
   * The base query: one collection, access checked, published unless allowed.
   */
  protected function baseQuery(array $collection): QueryInterface {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $collection['bundle']);
    // Entity query access checks do not hide unpublished nodes on sites
    // without node access modules, so do it here.
    if (!$this->currentUser->hasPermission('bypass node access') && !$this->currentUser->hasPermission('view any unpublished content')) {
      $query->condition('status', 1);
    }
    return $query;
  }

  /**
   * Runs a count query.
   */
  protected function count(QueryInterface $query): int {
    return (int) $query->count()->execute();
  }

  /**
   * Adds the group condition.
   *
   * @return array<string|int, string>
   *   The matched group labels, keyed by stored value. Empty if none matched.
   */
  protected function applyGroup(QueryInterface $query, array $collection, string $group): array {
    if (!$collection['group_field']) {
      throw new \InvalidArgumentException(sprintf('The collection "%s" has no group field.', $collection['id']));
    }
    $matches = $this->matchGroups($collection, $group);
    if ($matches === []) {
      return [];
    }
    $definition = $this->fieldDefinitions($collection['bundle'])[$collection['group_field']];
    $column = $definition->getType() === 'entity_reference' ? '.target_id' : '.value';
    $query->condition($collection['group_field'] . $column, array_keys($matches), 'IN');
    return $matches;
  }

  /**
   * Finds the groups whose name contains the given text.
   *
   * Exact matches win: asking for "JPH" when one group is called "JPH" and
   * another "JPH Supplements" returns only the first.
   *
   * @return array<string|int, string>
   *   Group labels keyed by stored value.
   */
  protected function matchGroups(array $collection, string $text): array {
    $needle = mb_strtolower($text);
    $exact = [];
    $partial = [];
    foreach ($this->groups($collection, 1000) as $group) {
      $label = mb_strtolower($group['label']);
      $value = mb_strtolower((string) $group['value']);
      if ($label === $needle || $value === $needle) {
        $exact[$group['value']] = $group['label'];
      }
      elseif (str_contains($label, $needle) || str_contains($value, $needle)) {
        $partial[$group['value']] = $group['label'];
      }
    }
    return $exact ?: $partial;
  }

  /**
   * Lists the groups of a collection.
   *
   * For a reference field, the referenced entities the user can view; for a
   * list field, its allowed values; for a text field, the distinct values in
   * use.
   *
   * @return array<int, array{value: string|int, label: string}>
   *   The groups, sorted by label.
   */
  public function groups(array $collection, int $limit = 200): array {
    $definition = $this->fieldDefinitions($collection['bundle'])[$collection['group_field']] ?? NULL;
    if (!$definition instanceof FieldDefinitionInterface) {
      return [];
    }

    $groups = [];
    switch ($definition->getType()) {
      case 'entity_reference':
        $target_type = $definition->getSetting('target_type');
        $storage = $this->entityTypeManager->getStorage($target_type);
        $query = $storage->getQuery()->accessCheck(TRUE)->range(0, $limit);
        $bundles = $definition->getSetting('handler_settings')['target_bundles'] ?? NULL;
        $bundle_key = $this->entityTypeManager->getDefinition($target_type)->getKey('bundle');
        if ($bundles && $bundle_key) {
          $query->condition($bundle_key, array_values($bundles), 'IN');
        }
        foreach ($storage->loadMultiple($query->execute()) as $entity) {
          if ($entity->access('view label', $this->currentUser)) {
            $groups[] = ['value' => (int) $entity->id(), 'label' => (string) $entity->label()];
          }
        }
        break;

      case 'list_string':
      case 'list_integer':
        foreach ($definition->getFieldStorageDefinition()->getSetting('allowed_values') ?? [] as $value => $label) {
          $groups[] = ['value' => $value, 'label' => (string) $label];
        }
        break;

      default:
        $result = $this->entityTypeManager->getStorage('node')->getAggregateQuery()
          ->accessCheck(TRUE)
          ->condition('type', $collection['bundle'])
          ->groupBy($collection['group_field'] . '.value')
          ->range(0, $limit)
          ->execute();
        foreach ($result as $row) {
          $value = $row[$collection['group_field'] . '_value'] ?? NULL;
          if ($value !== NULL && $value !== '') {
            $groups[] = ['value' => $value, 'label' => (string) $value];
          }
        }
    }

    usort($groups, fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
    return array_slice($groups, 0, $limit);
  }

  /**
   * Adds the keyword condition: every word in the title or a search field.
   */
  protected function applyKeywords(QueryInterface $query, array $collection, string $keywords): void {
    $words = array_slice(array_filter(preg_split('/\s+/u', trim($keywords)) ?: [], fn(string $word): bool => mb_strlen($word) > 1), 0, 8);
    $fields = array_merge(['title'], $collection['search_fields']);
    $definitions = $this->fieldDefinitions($collection['bundle']);
    foreach ($words as $word) {
      $any = $query->orConditionGroup();
      foreach ($fields as $field) {
        if (isset($definitions[$field])) {
          $any->condition($field === 'title' ? 'title' : $field . '.value', $word, 'CONTAINS');
        }
      }
      $query->condition($any);
    }
  }

  /**
   * Adds the date range.
   */
  protected function applyDates(QueryInterface $query, array $collection, ?string $from, ?string $to): void {
    foreach (['>=' => $from, '<=' => $to] as $operator => $date) {
      if ($date === NULL || trim($date) === '') {
        continue;
      }
      $date = trim($date);
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
        throw new \InvalidArgumentException(sprintf('"%s" is not a date in the YYYY-MM-DD format.', $date));
      }
      $end_of_day = $operator === '<=';
      $query->condition($collection['date_field'], $this->storedDate($collection, $date, $end_of_day), $operator);
    }
  }

  /**
   * Converts a YYYY-MM-DD date to the way the date field stores it.
   */
  protected function storedDate(array $collection, string $date, bool $end_of_day): string|int {
    $definition = $this->fieldDefinitions($collection['bundle'])[$collection['date_field']];
    if ($definition->getType() === 'datetime') {
      if ($definition->getFieldStorageDefinition()->getSetting('datetime_type') === 'date') {
        return $date;
      }
      return $date . ($end_of_day ? 'T23:59:59' : 'T00:00:00');
    }
    $time = new \DateTimeImmutable($date . ($end_of_day ? ' 23:59:59' : ' 00:00:00'), new \DateTimeZone(date_default_timezone_get()));
    return $time->getTimestamp();
  }

  /**
   * Counts per group, largest first.
   */
  protected function countByGroup(array $collection, callable $prepare): array {
    $field = $collection['group_field'];
    $definition = $this->fieldDefinitions($collection['bundle'])[$field];
    $column = $definition->getType() === 'entity_reference' ? 'target_id' : 'value';

    $query = $this->entityTypeManager->getStorage('node')->getAggregateQuery()
      ->accessCheck(TRUE)
      ->condition('type', $collection['bundle'])
      ->groupBy($field . '.' . $column)
      ->aggregate('nid', 'COUNT');
    if (!$this->currentUser->hasPermission('bypass node access') && !$this->currentUser->hasPermission('view any unpublished content')) {
      $query->condition('status', 1);
    }
    $prepare($query);

    $labels = [];
    foreach ($this->groups($collection, 1000) as $group) {
      $labels[(string) $group['value']] = $group['label'];
    }

    $rows = [];
    foreach ($query->execute() as $row) {
      $value = (string) ($row[$field . '_' . $column] ?? '');
      $rows[] = [
        'key' => $value === '' ? '(none)' : ($labels[$value] ?? $value),
        'count' => (int) $row['nid_count'],
      ];
    }
    usort($rows, fn(array $a, array $b): int => $b['count'] <=> $a['count'] ?: strnatcasecmp($a['key'], $b['key']));
    return $rows;
  }

  /**
   * Counts per year, newest first.
   */
  protected function countByYear(array $collection, callable $prepare): array {
    $first = $this->edgeYear($collection, $prepare, 'ASC');
    $last = $this->edgeYear($collection, $prepare, 'DESC');
    if ($first === NULL || $last === NULL) {
      return [];
    }

    $rows = [];
    for ($year = $last; $year >= max($first, $last - 99); $year--) {
      $query = $this->baseQuery($collection);
      $prepare($query);
      $this->applyDates($query, $collection, $year . '-01-01', $year . '-12-31');
      $count = $this->count($query);
      if ($count > 0) {
        $rows[] = ['key' => (string) $year, 'count' => $count];
      }
    }
    return $rows;
  }

  /**
   * The year of the oldest or newest item.
   */
  protected function edgeYear(array $collection, callable $prepare, string $direction): ?int {
    $query = $this->baseQuery($collection);
    $prepare($query);
    $ids = $query->sort($collection['date_field'], $direction)->range(0, 1)->execute();
    $node = $ids ? $this->entityTypeManager->getStorage('node')->load(reset($ids)) : NULL;
    if (!$node instanceof NodeInterface) {
      return NULL;
    }
    $date = $this->dateOf($node, $collection);
    return $date ? (int) substr($date, 0, 4) : NULL;
  }

  /**
   * The short description of one item.
   */
  protected function summarize(NodeInterface $node, array $collection): array {
    return [
      'id' => (int) $node->id(),
      'title' => (string) $node->label(),
      'date' => $this->dateOf($node, $collection),
      'group' => $collection['group_field'] ? $this->groupOf($node, $collection['group_field']) : '',
      'published' => $node->isPublished(),
      'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ];
  }

  /**
   * The item's date as YYYY-MM-DD.
   */
  protected function dateOf(NodeInterface $node, array $collection): string {
    $field = $collection['date_field'];
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '';
    }
    $value = $node->get($field)->value;
    if (is_numeric($value)) {
      return $this->dateFormatter->format((int) $value, 'custom', 'Y-m-d');
    }
    return substr((string) $value, 0, 10);
  }

  /**
   * The item's group names, comma separated.
   */
  protected function groupOf(NodeInterface $node, string $field): string {
    if (!$node->hasField($field)) {
      return '';
    }
    $labels = [];
    $items = $node->get($field);
    $type = $items->getFieldDefinition()->getType();
    foreach ($items as $item) {
      if ($type === 'entity_reference') {
        if ($item->entity && $item->entity->access('view label', $this->currentUser)) {
          $labels[] = (string) $item->entity->label();
        }
      }
      elseif (str_starts_with($type, 'list_')) {
        $allowed = $items->getFieldDefinition()->getFieldStorageDefinition()->getSetting('allowed_values') ?? [];
        $labels[] = (string) ($allowed[$item->value] ?? $item->value);
      }
      else {
        $labels[] = (string) $item->value;
      }
    }
    return implode(', ', $labels);
  }

  /**
   * The text format for draft bodies: the user's default format.
   */
  protected function draftFormat(): string {
    // Drupal 11.4 replaced filter_default_format() with a service, injected
    // when it exists, so the module runs on Drupal 10.5 through 12.
    if ($this->formatRepository !== NULL) {
      return $this->formatRepository->getDefaultFormat($this->currentUser)->id();
    }
    return filter_default_format($this->currentUser);
  }

}
