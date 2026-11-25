<?php

declare(strict_types=1);

namespace Drupal\Tests\content_assistant\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\content_assistant\Form\SettingsForm;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Adds a collection, then chooses its fields, through the settings form.
 *
 * @group content_assistant
 */
#[Group('content_assistant')]
#[RunTestsInSeparateProcesses]
class SettingsFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'serialization',
    'tool',
    'content_assistant',
  ];

  /**
   * Adding a collection takes two steps: content type, then fields.
   */
  public function testAddAndConfigureCollection(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['filter', 'node', 'content_assistant']);
    NodeType::create(['type' => 'news', 'name' => 'News'])->save();
    // Drupal 10 ships the body field storage with the Node module's config.
    if (!FieldStorageConfig::loadByName('node', 'body')) {
      FieldStorageConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'type' => 'text_with_summary'])->save();
    }
    FieldConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'bundle' => 'news', 'label' => 'Body'])->save();

    // A half-filled new collection is rejected.
    $form_state = (new FormState())->setValues([
      'max_results' => 20,
      'new' => ['label' => 'News', 'id' => '', 'bundle' => ''],
    ]);
    $this->container->get('form_builder')->submitForm(SettingsForm::class, $form_state);
    $this->assertNotEmpty($form_state->getErrors());

    // Step 1: label, machine name and content type.
    $form_state = (new FormState())->setValues([
      'max_results' => 15,
      'new' => ['label' => 'News', 'id' => 'news', 'bundle' => 'news'],
    ]);
    $this->container->get('form_builder')->submitForm(SettingsForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $collections = $this->config('content_assistant.settings')->get('collections');
    $this->assertSame('news', $collections[0]['id']);
    $this->assertSame('created', $collections[0]['date_field']);
    $this->assertSame(15, $this->config('content_assistant.settings')->get('max_results'));

    // Step 2: the fields of that content type are now offered.
    $form_state = (new FormState())->setValues([
      'max_results' => 15,
      'collections' => [
        'news' => [
          'label' => 'News items',
          'description' => 'Institutional news.',
          'date_field' => 'created',
          'group_field' => '',
          'search_fields' => ['body' => 'body'],
          'allow_drafts' => 1,
          'body_field' => 'body',
          // Programmatic submissions use NULL for an unchecked checkbox.
          'remove' => NULL,
        ],
      ],
      'new' => ['label' => '', 'id' => '', 'bundle' => ''],
    ]);
    $this->container->get('form_builder')->submitForm(SettingsForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $collection = $this->config('content_assistant.settings')->get('collections')[0];
    $this->assertSame('News items', $collection['label']);
    $this->assertSame(['body'], $collection['search_fields']);
    $this->assertSame(['body'], array_keys($form_state->getCompleteForm()['collections']['news']['search_fields']['#options']), 'The title is always searched, so it is not offered.');
    $this->assertTrue($collection['allow_drafts']);
    $this->assertSame('body', $collection['body_field']);

    // Removing it.
    $form_state = (new FormState())->setValues([
      'max_results' => 15,
      'collections' => ['news' => ['label' => 'News items', 'date_field' => 'created', 'remove' => 1]],
      'new' => ['label' => '', 'id' => '', 'bundle' => ''],
    ]);
    $this->container->get('form_builder')->submitForm(SettingsForm::class, $form_state);
    $this->assertSame([], $this->config('content_assistant.settings')->get('collections'));
  }

}
