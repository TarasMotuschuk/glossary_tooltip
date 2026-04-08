# AGENTS.md

## Project Variants

This task should be implemented and reviewed in **two separate variants**.

### Variant 1 — Broad compatibility
- Compatible with **Drupal 9, 10, and 11**
- The module should be written in a way that preserves cross-version compatibility
- Avoid APIs or patterns that would break support for older supported Drupal core versions

### Variant 2 — Modern implementation
- Target platform: **Drupal 11.3+**
- PHP version: **8.4+**
- Use the **latest relevant dependencies**
- Prefer a **modern Drupal architecture**
- Use **OOP hooks** where applicable
- The implementation may assume current best practices and does not need to preserve backward compatibility with Drupal 9/10

## General Development Expectations

- The submission must be a **single installable module**
- Do not include full Drupal project directories such as:
    - `docroot`
    - `core`
    - vendorized Drupal core files
    - other default Drupal distribution directories
- Only the custom module itself should be submitted

## Local Verification Environment

For verification and local testing, use **Lando**.

The code should be checked against the following image:

`https://hub.docker.com/repository/docker/timtom6891/drupal-cs/tags/latest`

## Verification Notes

- Ensure the module can be installed and tested inside a Lando-based local environment
- Use the above image for code style and validation checks where relevant
- The compatibility target must be explicit depending on the selected variant:
    - Variant 1 → Drupal 9/10/11 compatible
    - Variant 2 → Drupal 11.3+ and PHP 8.4+ only

## Implementation Guidance

### For Variant 1
- Prioritize compatibility and conservative API usage
- Keep the implementation stable across multiple core versions
- Avoid features available only in the newest Drupal releases

### For Variant 2
- Prioritize clean architecture and modern patterns
- Use current Drupal coding practices
- Prefer strict typing, dependency injection, and modern extension points
- Use OOP hook implementations where supported and appropriate

## Deliverable

Prepare the module so it can be reviewed as one standalone custom module, with the chosen variant clearly identified.