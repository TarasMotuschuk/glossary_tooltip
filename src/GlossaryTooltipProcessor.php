<?php

namespace Drupal\glossary_tooltip;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\taxonomy\TermInterface;

/**
 * Replaces glossary terms in rendered content with tooltip markup.
 */
class GlossaryTooltipProcessor {

  /**
   * Maximum tooltip description length.
   */
  protected const TOOLTIP_DESCRIPTION_LIMIT = 100;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs the processor.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

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
      if (strpos((string) $key, '#') === 0) {
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
  protected function alterRenderArray(array &$element, array $terms): bool {
    $changed = FALSE;

    if (isset($element['#type']) && $element['#type'] === 'processed_text' && !empty($element['#text'])) {
      $processed = $this->processHtml($element['#text'], $terms);
      if ($processed !== $element['#text']) {
        $element['#text'] = $processed;
        $changed = TRUE;
      }
    }

    if (isset($element['#markup']) && is_string($element['#markup'])) {
      $processed = $this->processHtml($element['#markup'], $terms);
      if ($processed !== $element['#markup']) {
        $element['#markup'] = Markup::create($processed);
        $changed = TRUE;
      }
    }

    foreach ($element as $key => &$child) {
      if ($key === '#children' || strpos((string) $key, '#') === 0 || !is_array($child)) {
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
  protected function processHtml(string $html, array $terms): string {
    if (trim(strip_tags($html)) === '') {
      return $html;
    }

    $internalErrors = libxml_use_internal_errors(TRUE);
    $document = new \DOMDocument('1.0', 'UTF-8');
    $wrappedHtml = '<?xml encoding="UTF-8"><div data-glossary-root="1">' . $html . '</div>';

    if (!$document->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
      libxml_clear_errors();
      libxml_use_internal_errors($internalErrors);
      return $html;
    }

    $xpath = new \DOMXPath($document);
    $query = '//text()[normalize-space() != "" and not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::span[contains(concat(" ", normalize-space(@class), " "), " glossary-tooltip ")])]';
    $nodes = $xpath->query($query);

    if (!$nodes) {
      libxml_clear_errors();
      libxml_use_internal_errors($internalErrors);
      return $html;
    }

    $replacements = [];
    foreach ($nodes as $node) {
      $text = $node->nodeValue;
      $updated = $this->replaceTermsInText($text, $terms);
      if ($updated !== $text) {
        $replacements[] = [$node, $updated];
      }
    }

    foreach ($replacements as [$node, $updated]) {
      $fragment = $document->createDocumentFragment();
      $fragment->appendXML($updated);
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
    libxml_use_internal_errors($internalErrors);

    return $result ?: $html;
  }

  /**
   * Replaces glossary terms in a plain text fragment.
   */
  protected function replaceTermsInText(string $text, array $terms): string {
    if ($text === '') {
      return $text;
    }

    $escapedNames = [];
    foreach (array_keys($terms) as $name) {
      $escapedNames[] = preg_quote($name, '/');
    }

    if (!$escapedNames) {
      return $text;
    }

    usort($escapedNames, static function (string $a, string $b): int {
      return strlen($b) <=> strlen($a);
    });

    $pattern = '/(?<![\p{L}\p{N}_-])(' . implode('|', $escapedNames) . ')(?![\p{L}\p{N}_-])/iu';

    return (string) preg_replace_callback($pattern, function (array $matches) use ($terms): string {
      $matchedText = $matches[0];
      $term = $terms[mb_strtolower($matchedText)];

      return $this->buildTooltipMarkup($matchedText, $term);
    }, $text);
  }

  /**
   * Creates tooltip markup for a term occurrence.
   */
  protected function buildTooltipMarkup(string $matchedText, array $term): string {
    $fullDescription = trim(strip_tags($term['description']));
    $shortDescription = mb_substr($fullDescription, 0, self::TOOLTIP_DESCRIPTION_LIMIT);
    $isTrimmed = mb_strlen($fullDescription) > self::TOOLTIP_DESCRIPTION_LIMIT;

    if ($isTrimmed) {
      $shortDescription = rtrim($shortDescription) . '...';
    }

    $output = '<span class="glossary-tooltip" tabindex="0">';
    $output .= '<span class="glossary-tooltip__term">' . htmlspecialchars($matchedText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
    $output .= '<span class="glossary-tooltip__bubble" role="tooltip">';
    $output .= '<span class="glossary-tooltip__description">' . htmlspecialchars($shortDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';

    if ($isTrimmed) {
      $output .= ' <a class="glossary-tooltip__more" href="' . htmlspecialchars($term['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Read more</a>';
    }

    $output .= '</span></span>';

    return $output;
  }

  /**
   * Loads published glossary terms indexed by normalized name.
   */
  protected function loadGlossaryTerms(): array {
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
      $description = trim((string) $term->getDescription());
      if ($name === '' || $description === '') {
        continue;
      }

      $terms[mb_strtolower($name)] = [
        'description' => $description,
        'url' => $term->toUrl()->toString(),
      ];
    }

    return $terms;
  }

}
