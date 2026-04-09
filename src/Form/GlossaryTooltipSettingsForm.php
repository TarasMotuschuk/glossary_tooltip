<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\glossary_tooltip\GlossaryTooltipProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for glossary tooltip field targeting.
 */
final class GlossaryTooltipSettingsForm extends ConfigFormBase {

  /**
   * Supported field types.
   *
   * @var array<int, string>
   */
  private const array SUPPORTED_FIELD_TYPES = [
    'string',
    'string_long',
    'text',
    'text_long',
    'text_with_summary',
  ];

  /**
   * Constructs the settings form.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_field.manager'),
      $container->get('entity_type.bundle.info'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'glossary_tooltip_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['glossary_tooltip.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('glossary_tooltip.settings');
    $disabled_field_ids = $config->get('disabled_field_ids')
      ?? [];

    $form['description'] = [
      '#type' => 'item',
      '#markup' => (string) $this->t(
        'Choose which node text fields should be excluded from glossary
        tooltips. By default all supported fields are included.'
      ),
    ];

    $form['fields'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    $bundles = $this->bundleInfo->getBundleInfo('node');
    foreach ($bundles as $bundle_id => $bundle_info) {
      $options = [];
      $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle_id);

      foreach ($field_definitions as $field_name => $field_definition) {
        if (!in_array($field_definition->getType(), self::SUPPORTED_FIELD_TYPES, TRUE)) {
          continue;
        }

        $field_id = $bundle_id . '.' . $field_name;
        $options[$field_id] = $this->t('@label (@name)', [
          '@label' => $field_definition->getLabel(),
          '@name' => $field_name,
        ]);
      }

      if ($options === []) {
        continue;
      }

      $default_value = array_values(array_filter(
        array_keys($options),
        static fn (string $field_id): bool => in_array($field_id, $disabled_field_ids, TRUE),
      ));

      $form['fields'][$bundle_id] = [
        '#type' => 'details',
        '#title' => $bundle_info['label'] ?? $bundle_id,
        '#open' => FALSE,
      ];

      $form['fields'][$bundle_id]['excluded_fields'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Excluded fields'),
        '#options' => $options,
        '#default_value' => $default_value,
        '#description' => $this->t('Checked fields will be skipped by glossary tooltip processing.'),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $existing_disabled_field_ids = $this
      ->config('glossary_tooltip.settings')
      ->get('disabled_field_ids')
      ?? [];
    $submitted_fields = $form_state->getValue('fields')
      ?? [];
    $disabled_field_ids = [];

    foreach ($submitted_fields as $bundle_values) {
      if (
        !is_array($bundle_values)
        || !isset($bundle_values['excluded_fields'])
        || !is_array($bundle_values['excluded_fields'])
      ) {
        continue;
      }

      foreach ($bundle_values['excluded_fields'] as $field_id => $value) {
        if (!empty($value)) {
          $disabled_field_ids[] = $field_id;
        }
      }
    }

    $this->configFactory()
      ->getEditable('glossary_tooltip.settings')
      ->set('disabled_field_ids', $disabled_field_ids)
      ->save();

    $affected_bundles = [];
    foreach (array_merge($existing_disabled_field_ids, $disabled_field_ids) as $field_id) {
      if (!is_string($field_id) || !str_contains($field_id, '.')) {
        continue;
      }

      [$bundle] = explode('.', $field_id, 2);
      $affected_bundles[$bundle] = TRUE;
    }

    if ($affected_bundles !== []) {
      Cache::invalidateTags(array_map(
        [GlossaryTooltipProcessor::class, 'getNodeBundleSettingsCacheTag'],
        array_keys($affected_bundles),
      ));
    }

    parent::submitForm($form, $form_state);
  }

}
