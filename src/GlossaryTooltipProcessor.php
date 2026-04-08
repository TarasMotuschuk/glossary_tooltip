<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Replaces glossary terms in rendered content with tooltip markup.
 */
final class GlossaryTooltipProcessor {

  /**
   * Maximum tooltip description length.
   */
  private const int TOOLTIP_DESCRIPTION_LIMIT = 100;

  /**
   * Constructs the processor.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * Alters a render array and injects glossary tooltips.
   */
  public function alterBuild(array &$build): bool {
    $terms = $this->loadGlossaryTerms();
    if (!$terms) {
      return FALSE;
    }

    $changed = FALSE;
    foreach ($build as $key => &$item) {
      if (str_starts_with((string) $key, '#')) {
        continue;
      }
      if ($this->alterRenderArray($item, $terms)) {
        $changed = TRUE;
      }
    }

    return $changed;
  }

  /**
   * Recursively alters rendered field values.
   */
  private function alterRenderArray(array &$element, array $terms): bool {
    $changed = FALSE;

    if (
      isset($element['#type']) &&
      $element['#type'] === 'processed_text' &&
      !empty($element['#text'])
    ) {
      $processed_html = $this->processHtml($element['#text'], $terms);
      if ($processed_html !== $element['#text']) {
        $element['#text'] = $processed_html;
        $changed = TRUE;
      }
    }

    if (isset($element['#markup']) && is_string($element['#markup'])) {
      $processed_html = $this->processHtml($element['#markup'], $terms);
      if ($processed_html !== $element['#markup']) {
        $element['#markup'] = Markup::create($processed_html);
        $changed = TRUE;
      }
    }

    foreach ($element as $key => &$child) {
      if (
        $key === '#children' ||
        str_starts_with((string) $key, '#') ||
        !is_array($child)
      ) {
        continue;
      }
      if ($this->alterRenderArray($child, $terms)) {
        $changed = TRUE;
      }
    }

    return $changed;
  }

  /**
   * Replaces glossary terms inside HTML text nodes.
   */
  private function processHtml(string $html, array $terms): string {
    if (trim(strip_tags($html)) === '') {
      return $html;
    }

    $internal_errors = libxml_use_internal_errors(TRUE);
    $document = new \DOMDocument('1.0', 'UTF-8');
    $wrapped_html = '<?xml encoding="UTF-8"><div data-glossary-root="1">'
      . $html .
      '</div>';

    if (!$document->loadHTML($wrapped_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
      libxml_clear_errors();
      libxml_use_internal_errors($internal_errors);
      return $html;
    }

    $xpath = new \DOMXPath($document);
    $query = '//text()[normalize-space() != ""'
      . ' and not(ancestor::a)'
      . ' and not(ancestor::script)'
      . ' and not(ancestor::style)'
      . ' and not(ancestor::span[contains(concat(" ", normalize-space(@class), " "), " glossary-tooltip ")])]';
    $nodes = $xpath->query($query);

    if (!$nodes) {
      libxml_clear_errors();
      libxml_use_internal_errors($internal_errors);
      return $html;
    }

    $replacements = [];
    foreach ($nodes as $node) {
      $text = $node->nodeValue;
      $updated_text = $this->replaceTermsInText($text, $terms);
      if ($updated_text !== $text) {
        $replacements[] = [$node, $updated_text];
      }
    }

    foreach ($replacements as [$node, $updated_text]) {
      $fragment = $document->createDocumentFragment();
      $fragment->appendXML($updated_text);
      $node->parentNode->replaceChild($fragment, $node);
    }

    $result = '';
    $root = $document->getElementsByTagName('div')->item(0);
    if ($root) {
      foreach ($root->childNodes as $child) {
        $result .= $document->saveHTML($child);
      }
    }

    libxml_clear_errors();
    libxml_use_internal_errors($internal_errors);

    return $result ?: $html;
  }

  /**
   * Replaces glossary terms in a plain text fragment.
   */
  private function replaceTermsInText(string $text, array $terms): string {
    if ($text === '') {
      return $text;
    }

    $escaped_names = [];
    foreach (array_keys($terms) as $name) {
      $escaped_names[] = preg_quote($name, '/');
    }

    if (!$escaped_names) {
      return $text;
    }

    usort($escaped_names, static function (string $a, string $b): int {
      return strlen($b) <=> strlen($a);
    });

    $pattern = '/(?<![\p{L}\p{N}_-])('
      . implode('|', $escaped_names) .
      ')(?![\p{L}\p{N}_-])/iu';

    return (string) preg_replace_callback($pattern, function (array $matches) use ($terms): string {
      $matched_text = $matches[0];
      $term = $terms[mb_strtolower($matched_text)];

      return $this->renderTooltipMarkup($matched_text, $term);
    }, $text);
  }

  /**
   * Renders tooltip markup for a term occurrence.
   */
  private function renderTooltipMarkup(string $matched_text, array $term): string {
    $build = [
      '#theme' => 'glossary_tooltip',
      '#matched_text' => $matched_text,
      '#short_description' => $term['short_description'],
      '#is_trimmed' => $term['is_trimmed'],
      '#url' => $term['url'],
    ];

    return (string) $this->renderer->renderInIsolation($build);
  }

  /**
   * Loads published glossary terms indexed by normalized name.
   */
  private function loadGlossaryTerms(): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage->getQuery()
      ->condition('vid', 'glossary')
      ->condition('status', 1)
      ->execute();

    if (!$ids) {
      return [];
    }

    $terms = [];
    /** @var \Drupal\taxonomy\TermInterface[] $entities */
    $entities = $storage->loadMultiple($ids);
    foreach ($entities as $term) {
      if (!$term instanceof TermInterface) {
        continue;
      }

      $name = trim($term->label());
      $description = $this->normalizeDescription((string) $term->getDescription());
      if ($name === '' || $description === '') {
        continue;
      }

      $short_description = Unicode::truncate(
        $description,
        self::TOOLTIP_DESCRIPTION_LIMIT,
        TRUE,
        TRUE,
      );
      $terms[mb_strtolower($name)] = [
        'short_description' => $short_description,
        'is_trimmed' => Unicode::strlen($description) > Unicode::strlen($short_description),
        'url' => $term->toUrl()->toString(),
      ];
    }

    return $terms;
  }

  /**
   * Converts a taxonomy description to plain tooltip text.
   */
  private function normalizeDescription(string $description): string {
    $description = Html::decodeEntities(strip_tags($description));
    $description = preg_replace('/\s+/u', ' ', $description);

    return trim((string) $description);
  }

}
