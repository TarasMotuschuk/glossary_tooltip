<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Hook;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\glossary_tooltip\GlossaryTooltipProcessor;

/**
 * Object-oriented hooks for the Glossary Tooltip module.
 */
final readonly class GlossaryTooltipHooks {

  /**
   * Constructs the hook handler.
   */
  public function __construct(
    private GlossaryTooltipProcessor $processor,
  ) {}

  /**
   * Implements hook_entity_view_alter().
   */
  #[Hook('entity_view_alter')]
  public function entityViewAlter(
    array &$build,
    EntityInterface $entity,
    EntityViewDisplayInterface $entity_view_display,
  ): void {
    if ($entity->getEntityTypeId() !== 'node') {
      return;
    }

    if ($this->processor->alterBuild($build)) {
      $build['#attached']['library'][] = 'glossary_tooltip/frontend';
    }
  }

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'glossary_tooltip' => [
        'variables' => [
          'matched_text' => NULL,
          'short_description' => NULL,
          'is_trimmed' => FALSE,
          'url' => NULL,
        ],
        'template' => 'glossary-tooltip',
      ],
    ];
  }

}
