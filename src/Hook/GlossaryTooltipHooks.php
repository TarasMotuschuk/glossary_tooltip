<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Hook;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\glossary_tooltip\GlossaryTooltipProcessor;
use Drupal\taxonomy\TermInterface;

/**
 * Object-oriented hooks for the Glossary Tooltip module.
 */
final class GlossaryTooltipHooks {

  use StringTranslationTrait;

  /**
   * Constructs the hook handler.
   */
  public function __construct(
    private GlossaryTooltipProcessor $processor,
  ) {}

  /**
   * Implements hook_entity_view_alter().
   *
   * @param array<string, mixed> $build
   *   The render array for the entity view.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The rendered entity.
   * @param \Drupal\Core\Entity\Display\EntityViewDisplayInterface $entity_view_display
   *   The entity view display.
   */
  #[Hook('entity_view_alter')]
  public function entityViewAlter(
    array &$build,
    EntityInterface $entity,
    EntityViewDisplayInterface $entity_view_display,
  ): void {
    $this->processor
      ->markDisabledFields($build, $entity);

    if (
      $this->processor
        ->alterBuild($build)
    ) {
      $build['#attached']['library'][] = 'glossary_tooltip/frontend';
    }
  }

  /**
   * Implements hook_block_view_alter().
   *
   * @param array<string, mixed> $build
   *   The block render array.
   * @param \Drupal\Core\Block\BlockPluginInterface $block
   *   The block plugin instance.
   */
  #[Hook('block_view_alter')]
  public function blockViewAlter(array &$build, BlockPluginInterface $block): void {
    if ($this->processor->alterBuild($build)) {
      $build['#attached']['library'][] = 'glossary_tooltip/frontend';
    }
  }

  /**
   * Implements hook_theme().
   *
   * @return array<string, array<string, mixed>>
   *   Theme hook definitions.
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'glossary_tooltip' => [
        'variables' => [
          'matched_text' => '',
          'short_description' => '',
          'is_trimmed' => FALSE,
          'url' => '',
        ],
        'template' => 'glossary-tooltip',
      ],
    ];
  }

  /**
   * Implements hook_form_taxonomy_term_form_alter().
   *
   * @param array<string, mixed> $form
   *   The taxonomy term form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  #[Hook('form_taxonomy_term_form_alter')]
  public function taxonomyTermFormAlter(array &$form, FormStateInterface $form_state): void {
    $term = $form_state
      ->getFormObject()
      ->getEntity();

    if (
      !$term instanceof TermInterface
      || $term->bundle() !== 'glossary'
    ) {
      return;
    }

    foreach ([
      ['description'],
      ['description', 'widget', 0],
      ['description', 'widget', 0, 'value'],
    ] as $path) {
      $element = &$form;

      foreach ($path as $key) {
        if (!isset($element[$key])) {
          unset($element);
          continue 2;
        }

        $element = &$element[$key];
      }

      $element['#required'] = TRUE;
      unset($element);
    }

    $form['#validate'][] = [$this, 'validateGlossaryDescription'];
  }

  /**
   * Validates that glossary terms always have a description.
   *
   * @param array<string, mixed> $form
   *   The taxonomy term form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateGlossaryDescription(array &$form, FormStateInterface $form_state): void {
    $description_value = trim((string) (
      $form_state->getValue(['description', 0, 'value'])
      ?? $form_state->getValue(['description', 'value'])
      ?? ''
    ));

    if ($description_value === '') {
      $form_state
        ->setErrorByName(
          'description][0][value',
          $this->t('Description is required for glossary terms.')
        );
    }
  }

}
