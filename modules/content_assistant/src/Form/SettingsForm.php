<?php

declare(strict_types=1);

namespace Drupal\content_assistant\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\content_assistant\ContentLibrary;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Chooses the content the assistant can see and where it may draft.
 *
 * Each collection is one content type. A new collection needs only a label,
 * a machine name and its content type; once saved, its fields can be
 * chosen, so the lists only show fields that content type really has.
 */
class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected ContentLibrary $library,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.bundle.info'),
      $container->get('content_assistant.library'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_assistant_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['content_assistant.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('content_assistant.settings');
    $bundles = array_map(fn(array $info): string => (string) $info['label'], $this->bundleInfo->getBundleInfo('node'));

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Choose which content types AI assistants can search, list and count, and where they may create unpublished drafts. Assistants only see content the user they act for can view. Grant the "Use content assistant tools" permission to let a role use them.') . '</p>',
    ];

    $form['max_results'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum items per answer'),
      '#default_value' => $config->get('max_results') ?? 20,
      '#min' => 1,
      '#max' => ContentLibrary::HARD_LIMIT,
      '#required' => TRUE,
    ];

    $form['collections'] = ['#tree' => TRUE];
    foreach ($this->library->collections() as $id => $collection) {
      $form['collections'][$id] = $this->collectionElement($collection, $bundles);
    }

    $form['new'] = [
      '#type' => 'details',
      '#title' => $this->t('Add a collection'),
      '#open' => $this->library->collections() === [],
      '#tree' => TRUE,
      'label' => [
        '#type' => 'textfield',
        '#title' => $this->t('Label'),
        '#description' => $this->t('For example "Articles" or "News".'),
      ],
      'id' => [
        '#type' => 'machine_name',
        '#title' => $this->t('Machine name'),
        '#required' => FALSE,
        '#machine_name' => [
          'exists' => [$this, 'collectionExists'],
          'source' => ['new', 'label'],
        ],
      ],
      'bundle' => [
        '#type' => 'select',
        '#title' => $this->t('Content type'),
        '#options' => $bundles,
        '#empty_option' => $this->t('- Select -'),
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * The settings of one existing collection.
   */
  protected function collectionElement(array $collection, array $bundles): array {
    $bundle = $collection['bundle'];
    $element = [
      '#type' => 'details',
      '#title' => $this->t('@label (@id) - @type', [
        '@label' => $collection['label'],
        '@id' => $collection['id'],
        '@type' => $bundles[$bundle] ?? $bundle,
      ]),
      'id' => ['#type' => 'value', '#value' => $collection['id']],
      'bundle' => ['#type' => 'value', '#value' => $bundle],
      'label' => [
        '#type' => 'textfield',
        '#title' => $this->t('Label'),
        '#default_value' => $collection['label'],
        '#required' => TRUE,
      ],
      'description' => [
        '#type' => 'textarea',
        '#title' => $this->t('Description for the assistant'),
        '#description' => $this->t('Explain what this content is, in a sentence or two. For example: "Scientific articles from the institution\'s journals; the group is the journal."'),
        '#default_value' => $collection['description'],
        '#rows' => 2,
      ],
    ];

    if (!isset($bundles[$bundle])) {
      $element['missing'] = [
        '#markup' => '<p>' . $this->t('The content type %type no longer exists. Remove this collection.', ['%type' => $bundle]) . '</p>',
      ];
    }
    else {
      $element['date_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Date field'),
        '#description' => $this->t('Orders results (newest first) and powers date filters and yearly counts.'),
        '#options' => $this->library->fieldOptions($bundle, ContentLibrary::DATE_FIELD_TYPES),
        '#default_value' => $collection['date_field'],
        '#required' => TRUE,
      ];
      $element['group_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Group field'),
        '#description' => $this->t('Optional. The field that groups the content, such as the journal of an article or the category of a news item: a reference, a list or a plain text field.'),
        '#options' => $this->library->fieldOptions($bundle, ContentLibrary::GROUP_FIELD_TYPES),
        '#empty_option' => $this->t('- None -'),
        '#default_value' => $collection['group_field'],
      ];
      $element['search_fields'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Searchable text fields'),
        '#description' => $this->t('Keyword searches look in the title and in these fields.'),
        '#options' => $this->library->fieldOptions($bundle, ContentLibrary::SEARCH_FIELD_TYPES),
        '#default_value' => $collection['search_fields'],
      ];
      $element['allow_drafts'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Allow the assistant to create unpublished drafts of this content type'),
        '#description' => $this->t('Drafts are never published by the assistant. The user also needs the "Create drafts with the content assistant" permission and permission to create this content type.'),
        '#default_value' => $collection['allow_drafts'],
      ];
      $element['body_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Body field for drafts'),
        '#options' => $this->library->fieldOptions($bundle, ContentLibrary::BODY_FIELD_TYPES),
        '#empty_option' => $this->t('- None -'),
        '#default_value' => $collection['body_field'],
        '#states' => [
          'visible' => [
            ':input[name="collections[' . $collection['id'] . '][allow_drafts]"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    $element['remove'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Remove this collection'),
    ];

    return $element;
  }

  /**
   * Machine name callback: whether a collection already uses the name.
   */
  public function collectionExists(string $id): bool {
    return isset($this->library->collections()[$id]);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $new = $form_state->getValue('new');
    $filled = array_filter([$new['label'] ?? '', $new['id'] ?? '', $new['bundle'] ?? ''], fn($value): bool => $value !== '');
    if ($filled && count($filled) < 3) {
      $form_state->setErrorByName('new', $this->t('To add a collection, fill in the label, the machine name and the content type.'));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $collections = [];
    foreach ($form_state->getValue('collections') ?? [] as $values) {
      if (!empty($values['remove'])) {
        continue;
      }
      $collections[] = [
        'id' => $values['id'],
        'label' => trim($values['label']),
        'description' => trim($values['description'] ?? ''),
        'bundle' => $values['bundle'],
        'date_field' => $values['date_field'] ?? 'created',
        'group_field' => $values['group_field'] ?? '',
        'search_fields' => array_values(array_filter($values['search_fields'] ?? [])),
        'allow_drafts' => (bool) ($values['allow_drafts'] ?? FALSE),
        'body_field' => $values['body_field'] ?? '',
      ];
    }

    $new = $form_state->getValue('new');
    if (!empty($new['id']) && !empty($new['bundle'])) {
      $collections[] = [
        'id' => $new['id'],
        'label' => trim($new['label']),
        'description' => '',
        'bundle' => $new['bundle'],
        'date_field' => 'created',
        'group_field' => '',
        'search_fields' => [],
        'allow_drafts' => FALSE,
        'body_field' => '',
      ];
      $this->messenger()->addStatus($this->t('Collection %label added. Choose its fields below.', ['%label' => $new['label']]));
    }

    $this->config('content_assistant.settings')
      ->set('max_results', (int) $form_state->getValue('max_results'))
      ->set('collections', $collections)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
