<?php

declare(strict_types=1);

namespace Drupal\Tests\content_assistant\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolInvocation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Runs the content assistant tools the way MCP and AI callers do.
 *
 * @group content_assistant
 */
#[Group('content_assistant')]
#[RunTestsInSeparateProcesses]
class ContentAssistantToolsTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'options',
    'datetime',
    'taxonomy',
    'node',
    'serialization',
    'tool',
    'content_assistant',
  ];

  /**
   * A reader: may use the tools and view published content.
   */
  protected AccountInterface $reader;

  /**
   * An editor: may also create news drafts.
   */
  protected AccountInterface $editor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node', 'content_assistant']);

    // User 1 bypasses every check: create it first and leave it unused.
    $this->createUser();

    Vocabulary::create(['vid' => 'journals', 'name' => 'Journals'])->save();
    $journals = [];
    foreach (['JPH', 'JPH Supplement', 'Tropical Notes'] as $name) {
      $term = Term::create(['vid' => 'journals', 'name' => $name]);
      $term->save();
      $journals[$name] = $term->id();
    }

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'news', 'name' => 'News'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_journal',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_journal',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Journal',
      'settings' => ['handler_settings' => ['target_bundles' => ['journals' => 'journals']]],
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_published',
      'entity_type' => 'node',
      'type' => 'datetime',
      'settings' => ['datetime_type' => 'date'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_published',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Published on',
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'type' => 'text_with_summary',
    ])->save();
    foreach (['article', 'news'] as $bundle) {
      FieldConfig::create([
        'field_name' => 'body',
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => 'Body',
      ])->save();
    }

    // Twelve JPH articles, one a month from January 2024 back to February
    // 2023, so the newest is "JPH article 1".
    for ($i = 1; $i <= 12; $i++) {
      $date = (new \DateTimeImmutable('2024-01-15'))->modify('-' . ($i - 1) . ' months');
      $this->article("JPH article $i", $journals['JPH'], $date->format('Y-m-d'), $i === 3 ? 'Dengue outbreaks in the northeast' : 'Public health research');
    }
    $this->article('Supplement article', $journals['JPH Supplement'], '2024-06-01');
    $this->article('Tropical Notes article', $journals['Tropical Notes'], '2025-03-10', 'Dengue vector control');
    $this->article('Unpublished JPH article', $journals['JPH'], '2025-12-01', '', FALSE);

    $this->config('content_assistant.settings')->set('collections', [
      [
        'id' => 'articles',
        'label' => 'Articles',
        'description' => 'Scientific articles; the group is the journal.',
        'bundle' => 'article',
        'date_field' => 'field_published',
        'group_field' => 'field_journal',
        'search_fields' => ['body'],
        'allow_drafts' => FALSE,
        'body_field' => 'body',
      ],
      [
        'id' => 'news',
        'label' => 'News',
        'description' => 'News items.',
        'bundle' => 'news',
        'date_field' => 'created',
        'group_field' => '',
        'search_fields' => ['body'],
        'allow_drafts' => TRUE,
        'body_field' => 'body',
      ],
    ])->save();

    $this->reader = $this->createUser(['access content', 'use content assistant']);
    $this->editor = $this->createUser([
      'access content',
      'use content assistant',
      'create drafts with content assistant',
      'create news content',
    ]);
  }

  /**
   * Creates an article.
   */
  protected function article(string $title, int|string $journal, string $date, string $body = '', bool $published = TRUE): void {
    Node::create([
      'type' => 'article',
      'title' => $title,
      'field_journal' => $journal,
      'field_published' => $date,
      'body' => ['value' => $body, 'format' => 'plain_text'],
      'status' => $published ? 1 : 0,
    ])->save();
  }

  /**
   * Runs a tool as a user.
   */
  protected function runTool(string $tool, array $values, AccountInterface $account): ToolInvocation {
    return $this->container->get('tool.runner')->run($tool, values: $values, account: $account);
  }

  /**
   * Runs a tool that must succeed and returns its output values.
   */
  protected function toolOutput(string $tool, array $values, ?AccountInterface $account = NULL): array {
    $result = $this->runTool($tool, $values, $account ?? $this->reader)->getResult();
    $this->assertTrue($result->isSuccess(), (string) $result->getMessage());
    return $result->getContextValues();
  }

  /**
   * The collections tool lists the collections and the journals.
   */
  public function testCollections(): void {
    $collections = $this->toolOutput('content_assistant_collections', [])['collections'];

    $this->assertSame(['articles', 'news'], array_column($collections, 'collection'));
    $this->assertSame(14, $collections[0]['total'], 'The unpublished article is not counted for a reader.');
    $this->assertSame(['JPH', 'JPH Supplement', 'Tropical Notes'], array_column($collections[0]['groups'], 'label'));
    $this->assertFalse($collections[0]['allows_drafts']);
    $this->assertTrue($collections[1]['allows_drafts']);
  }

  /**
   * The 10 latest of JPH: exact journal match, newest first, published only.
   */
  public function testLatestItemsOfJournal(): void {
    $output = $this->toolOutput('content_assistant_search', [
      'collection' => 'articles',
      'group' => 'jph',
      'limit' => 10,
    ]);

    $this->assertSame(12, $output['total']);
    $this->assertSame(['JPH'], $output['groups_matched'], 'The exact name wins over "JPH Supplement".');
    $this->assertCount(10, $output['items']);
    $this->assertSame('JPH article 1', $output['items'][0]['title']);
    $this->assertSame('2024-01-15', $output['items'][0]['date']);
    $this->assertSame('JPH', $output['items'][0]['group']);
    $this->assertSame('JPH article 10', $output['items'][9]['title']);
    $this->assertStringContainsString('/node/', $output['items'][0]['url']);

    // A partial name matches every journal that contains it.
    $partial = $this->toolOutput('content_assistant_search', ['collection' => 'articles', 'group' => 'tropical']);
    $this->assertSame(['Tropical Notes'], $partial['groups_matched']);
    $this->assertSame(1, $partial['total']);

    $none = $this->toolOutput('content_assistant_search', ['collection' => 'articles', 'group' => 'Nature']);
    $this->assertSame(0, $none['total']);
    $this->assertSame([], $none['items']);
  }

  /**
   * Keywords look in the title and the body; dates narrow the results.
   */
  public function testKeywordsAndDates(): void {
    $dengue = $this->toolOutput('content_assistant_search', ['collection' => 'articles', 'keywords' => 'dengue']);
    $this->assertSame(['Tropical Notes article', 'JPH article 3'], array_column($dengue['items'], 'title'));

    $both = $this->toolOutput('content_assistant_search', ['collection' => 'articles', 'keywords' => 'dengue northeast']);
    $this->assertSame(['JPH article 3'], array_column($both['items'], 'title'));

    $in_2023 = $this->toolOutput('content_assistant_search', [
      'collection' => 'articles',
      'date_from' => '2023-01-01',
      'date_to' => '2023-12-31',
    ]);
    $this->assertSame(11, $in_2023['total']);

    $bad = $this->runTool('content_assistant_search', ['collection' => 'articles', 'date_from' => '15/01/2024'], $this->reader)->getResult();
    $this->assertFalse($bad->isSuccess());
    $this->assertStringContainsString('YYYY-MM-DD', (string) $bad->getMessage());

    $unknown = $this->runTool('content_assistant_search', ['collection' => 'invoices'], $this->reader)->getResult();
    $this->assertFalse($unknown->isSuccess());
    $this->assertStringContainsString('articles, news', (string) $unknown->getMessage());
  }

  /**
   * Counts per journal and per year.
   */
  public function testReport(): void {
    $by_group = $this->toolOutput('content_assistant_report', ['collection' => 'articles', 'by' => 'group']);
    $this->assertSame(14, $by_group['total']);
    $this->assertSame([
      ['key' => 'JPH', 'count' => 12],
      ['key' => 'JPH Supplement', 'count' => 1],
      ['key' => 'Tropical Notes', 'count' => 1],
    ], $by_group['rows']);

    $by_year = $this->toolOutput('content_assistant_report', [
      'collection' => 'articles',
      'by' => 'year',
      'group' => 'JPH',
    ]);
    $this->assertSame([
      ['key' => '2024', 'count' => 1],
      ['key' => '2023', 'count' => 11],
    ], $by_year['rows']);

    $news = $this->runTool('content_assistant_report', ['collection' => 'news', 'by' => 'group'], $this->reader)->getResult();
    $this->assertFalse($news->isSuccess(), 'News has no group field.');
  }

  /**
   * Users who may see unpublished content see it through the tools too.
   */
  public function testUnpublishedForEditorsOfAllContent(): void {
    $admin = $this->createUser(['access content', 'use content assistant', 'bypass node access']);
    $output = $this->toolOutput('content_assistant_search', ['collection' => 'articles', 'group' => 'JPH', 'limit' => 1], $admin);
    $this->assertSame(13, $output['total']);
    $this->assertSame('Unpublished JPH article', $output['items'][0]['title']);
    $this->assertFalse($output['items'][0]['published']);
  }

  /**
   * Drafts are created unpublished, only where allowed, only by editors.
   */
  public function testCreateDraft(): void {
    $values = [
      'collection' => 'news',
      'title' => 'New issue of JPH',
      'body' => '<p>The December issue is out.</p>',
      'summary' => 'December issue',
    ];

    $draft = $this->toolOutput('content_assistant_create_draft', $values, $this->editor);
    $this->assertSame('draft', $draft['status']);
    $this->assertStringContainsString('/edit', $draft['edit_url']);

    $node = Node::load($draft['id']);
    $this->assertFalse($node->isPublished());
    $this->assertSame('news', $node->bundle());
    $this->assertSame((int) $this->editor->id(), (int) $node->getOwnerId());
    $this->assertSame('<p>The December issue is out.</p>', $node->get('body')->value);
    $this->assertSame('December issue', $node->get('body')->summary);

    // Scripts and event handlers in the text are stripped.
    $unsafe = $this->toolOutput('content_assistant_create_draft', [
      'body' => '<p onclick="steal()">Hi</p><script>steal()</script>',
    ] + $values, $this->editor);
    $this->assertSame('<p>Hi</p>steal()', Node::load($unsafe['id'])->get('body')->value);

    // A reader does not have the draft permission.
    $denied = $this->runTool('content_assistant_create_draft', $values, $this->reader)->getResult();
    $this->assertFalse($denied->isSuccess());

    // Articles do not allow drafts, even for the editor.
    $articles = $this->runTool('content_assistant_create_draft', ['collection' => 'articles'] + $values, $this->editor)->getResult();
    $this->assertFalse($articles->isSuccess());

    // The draft permission alone is not enough without "create news content".
    $no_create = $this->createUser(['access content', 'use content assistant', 'create drafts with content assistant']);
    $this->assertFalse($this->runTool('content_assistant_create_draft', $values, $no_create)->getResult()->isSuccess());

    $this->assertCount(2, \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['type' => 'news']));
  }

  /**
   * Without the tool permission, nothing runs.
   */
  public function testPermissionRequired(): void {
    $visitor = $this->createUser(['access content']);
    foreach (['content_assistant_collections', 'content_assistant_search'] as $tool) {
      $result = $this->runTool($tool, ['collection' => 'articles'], $visitor)->getResult();
      $this->assertFalse($result->isSuccess(), $tool);
    }
  }

}
