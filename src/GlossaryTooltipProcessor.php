<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Render\MarkupInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Replaces glossary terms in rendered content with tooltip markup.
 */
final class GlossaryTooltipProcessor implements TrustedCallbackInterface {

  /**
   * Cache tag prefix for node bundle field-exclusion settings.
   */
  private const NODE_BUNDLE_SETTINGS_TAG_PREFIX = 'glossary_tooltip:node_bundle:';

  /**
   * Maximum tooltip description length.
   */
  private const TOOLTIP_DESCRIPTION_LIMIT = 100;

  /**
   * Constructs the processor.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * Marks configured node fields that should be excluded from processing.
   *
   * @param array<string, mixed> $build
   *   The node build array.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The rendered entity.
   */
  public function markDisabledFields(array &$build, EntityInterface $entity): bool {
    if ($entity->getEntityTypeId() !== 'node') {
      return FALSE;
    }

    CacheableMetadata::createFromRenderArray($build)
      ->addCacheTags([self::getNodeBundleSettingsCacheTag($entity->bundle())])
      ->applyTo($build);

    $disabled_field_ids = $this->configFactory
      ->get('glossary_tooltip.settings')
      ->get('disabled_field_ids') ?? [];

    if (!is_array($disabled_field_ids) || $disabled_field_ids === []) {
      return FALSE;
    }

    $bundle = $entity->bundle();
    $changed = FALSE;

    foreach ($build as $field_name => &$element) {
      if (str_starts_with($field_name, '#') || !is_array($element)) {
        continue;
      }

      if (!in_array($bundle . '.' . $field_name, $disabled_field_ids, TRUE)) {
        continue;
      }

      $element['#attributes']['class'][] = 'glossary-tooltip-skip';
      $changed = TRUE;
    }

    return $changed;
  }

  /**
   * Alters a render array and injects glossary tooltips.
   *
   * @param array<string, mixed> $build
   *   The render array to alter.
   */
  public function alterBuild(array &$build): bool {
    $glossary_data = $this->loadGlossaryTerms();
    CacheableMetadata::createFromRenderArray($build)
      ->merge($glossary_data['cacheability'])
      ->applyTo($build);
    $sanitized = $this->sanitizeRenderArray($build);
    $terms = $glossary_data['terms'];

    if (!$terms) {
      return $sanitized;
    }

    $callbacks = $build['#post_render'] ?? [];
    $callback = [self::class, 'postRenderGlossary'];

    if (!in_array($callback, $callbacks, TRUE)) {
      $build['#post_render'][] = $callback;

      return TRUE;
    }

    return $sanitized;
  }

  /**
   * Builds the cache tag used for a node bundle's tooltip field settings.
   */
  public static function getNodeBundleSettingsCacheTag(string $bundle): string {
    return sprintf('%s%s', self::NODE_BUNDLE_SETTINGS_TAG_PREFIX, $bundle);
  }

  /**
   * Recursively normalizes unsafe link titles before rendering.
   *
   * @param array<string, mixed> $element
   *   A render array element.
   */
  private function sanitizeRenderArray(array &$element): bool {
    $changed = FALSE;

    if ($this->sanitizeLinkElement($element)) {
      $changed = TRUE;
    }

    foreach ($element as $key => &$child) {
      if (str_starts_with((string) $key, '#') || !is_array($child)) {
        continue;
      }

      if ($this->sanitizeRenderArray($child)) {
        $changed = TRUE;
      }
    }

    return $changed;
  }

  /**
   * Prevents broken link render arrays from crashing page rendering.
   *
   * @param array<string, mixed> $element
   *   A render array element.
   */
  private function sanitizeLinkElement(array &$element): bool {
    if (
      ($element['#type'] ?? NULL) !== 'link'
      || !array_key_exists('#title', $element)
    ) {
      return FALSE;
    }

    $title = $element['#title'];

    if (
      $title instanceof MarkupInterface
      || is_string($title)
    ) {
      return FALSE;
    }

    if ($title === NULL) {
      $element['#title'] = '';

      return TRUE;
    }

    if (
      is_scalar($title)
      || $title instanceof \Stringable
    ) {
      $element['#title'] = (string) $title;

      return TRUE;
    }

    return FALSE;
  }

  /**
   * Injects glossary tooltips into rendered markup at post-render time.
   *
   * @param string $markup
   *   The rendered markup.
   * @param array<string, mixed> $element
   *   The render array element.
   */
  public static function postRenderGlossary(
    string $markup,
    array $element,
  ): string {
    return \Drupal::service(self::class)
      ->processRenderedMarkup($markup);
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['postRenderGlossary'];
  }

  /**
   * Processes rendered markup without altering render-array cacheability.
   */
  public function processRenderedMarkup(string $markup): string {
    $glossary_data = $this->loadGlossaryTerms();
    $terms = $glossary_data['terms'];

    if (!$terms) {
      return $markup;
    }

    return $this->processHtml($markup, $terms);
  }

  /**
   * Replaces glossary terms inside HTML text nodes.
   *
   * @param string $html
   *   The HTML to process.
   * @param array<string, array<string, mixed>> $terms
   *   Glossary term data indexed by normalized term name.
   */
  private function processHtml(
    string $html,
    array $terms,
  ): string {
    if (trim(strip_tags($html)) === '') {
      return $html;
    }

    $internal_errors = libxml_use_internal_errors(TRUE);
    $document = new \DOMDocument('1.0', 'UTF-8');
    $wrapped_html = sprintf('<?xml encoding="UTF-8"><div data-glossary-root="1">%s</div>', $html);

    if (
      !$document
        ->loadHTML(
          $wrapped_html,
          LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        )
    ) {
      libxml_clear_errors();
      libxml_use_internal_errors($internal_errors);

      return $html;
    }

    $xpath = new \DOMXPath($document);
    $query = '//text()[normalize-space() != ""'
      . ' and not(ancestor::a)'
      . ' and not(ancestor::script)'
      . ' and not(ancestor::style)'
      . ' and not(ancestor::*[contains(concat(" ", normalize-space(@class), " "), " glossary-tooltip-skip ")])'
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

      if (
        !$fragment->appendXML($updated_text)
        || !$node->parentNode
      ) {
        continue;
      }

      $node
        ->parentNode
        ->replaceChild($fragment, $node);
    }

    $result = '';
    $root = $document
      ->getElementsByTagName('div')
      ->item(0);

    if ($root) {
      foreach ($root->childNodes as $child) {
        $result .= $document
          ->saveHTML($child);
      }
    }

    libxml_clear_errors();
    libxml_use_internal_errors($internal_errors);

    return $result ?: $html;
  }

  /**
   * Replaces glossary terms in a plain text fragment.
   *
   * @param string $text
   *   The text fragment to process.
   * @param array<string, array<string, mixed>> $terms
   *   Glossary term data indexed by normalized term name.
   */
  private function replaceTermsInText(
    string $text,
    array $terms,
  ): string {
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
      $matched_text = (string) ($matches[0] ?? '');

      if ($matched_text === '') {
        return '';
      }

      $term = $terms[mb_strtolower($matched_text)] ?? NULL;

      if (!is_array($term)) {
        return $matched_text;
      }

      return $this->renderTooltipMarkup($matched_text, $term);
    }, $text);
  }

  /**
   * Renders tooltip markup for a term occurrence.
   *
   * @param string $matched_text
   *   The matched glossary term text as it appears in content.
   * @param array<string, mixed> $term
   *   Prepared glossary term data.
   */
  private function renderTooltipMarkup(
    string $matched_text,
    array $term,
  ): string {
    $short_description = (string) ($term['short_description'] ?? '');

    if ($matched_text === '' || $short_description === '') {
      return $matched_text;
    }

    $build = [
      '#theme' => 'glossary_tooltip',
      '#matched_text' => $matched_text,
      '#short_description' => $short_description,
      '#is_trimmed' => !empty($term['is_trimmed']) && !empty($term['url']),
      '#url' => (string) ($term['url'] ?? ''),
    ];

    return (string) $this->renderer
      ->renderInIsolation($build);
  }

  /**
   * Loads published glossary terms indexed by normalized name.
   *
   * @return array{
   *   terms: array<string, array<string, mixed>>,
   *   cacheability: \Drupal\Core\Cache\CacheableMetadata,
   *   }
   *   The prepared glossary term data and its cacheability metadata.
   */
  private function loadGlossaryTerms(): array {
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheTags([
      'taxonomy_term_list',
      'config:taxonomy.vocabulary.glossary',
    ]);

    $storage = $this
      ->entityTypeManager
      ->getStorage('taxonomy_term');
    $ids = $storage->getQuery()
      ->condition('vid', 'glossary')
      ->condition('status', 1)
      ->accessCheck(TRUE)
      ->execute();

    if (!$ids) {
      return [
        'terms' => [],
        'cacheability' => $cacheability,
      ];
    }

    $terms = [];
    /** @var \Drupal\taxonomy\TermInterface[] $entities */
    $entities = $storage->loadMultiple($ids);
    foreach ($entities as $term) {
      if (!$term instanceof TermInterface) {
        continue;
      }

      $cacheability->addCacheableDependency($term);

      $name = $this->normalizeTermName($term->label());
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
        'is_trimmed' => $description !== $short_description,
        'url' => $this->buildTermUrl($term),
      ];
    }

    return [
      'terms' => $terms,
      'cacheability' => $cacheability,
    ];
  }

  /**
   * Normalizes a term label to a safe lookup key.
   */
  private function normalizeTermName(?string $name): string {
    return trim((string) $name);
  }

  /**
   * Builds a term URL without letting invalid entities break rendering.
   */
  private function buildTermUrl(TermInterface $term): string {
    try {
      return $term
        ->toUrl()
        ->toString();
    }
    catch (\Throwable) {
      return '';
    }
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
