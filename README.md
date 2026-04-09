# glossary_tooltip
Test task. Drupal Module that will allow admin to add words in the glossary with the description.

# Technical Specifications

## INSTRUCTIONS:

To perform the tasks, you will need to locally deploy the Drupal on your laptop, write the functionality, etc. in the custom module.

Please note that this variant targets Drupal 10+

Then you will be asked to send the module and present it on an interview. Please make sure to submit the test task as a single module that can be installed on Drupal 11.3+. There's no need to include folders like `docroot`, `core`, or any other default Drupal directories. The task is specifically to provide the module itself, nothing more.

## TASK

Create module that will be compatible with Drupal 10+.

| Module Details | Description                                                                                                                                                                                                                                                                              |
|---|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Module Name** | Glossary Tooltip                                                                                                                                                                                                                                                                         |
| **Mandatory** | no                                                                                                                                                                                                                                                                                       |
| **Use case** | Drupal 10+                                                                                                                                                                                                                                                                               |
| **Short Description** | The words correspondent / identical to the items included in the glossary vocabulary are highlighted                                                                                                                                                                                     |
| **Behaviour** | Create a functionality that will allow admin to add words in the glossary with the description. After the term is added and published, the content of the page will be checked and if the word exists in the page it will add description to it. You can check behaviour on the designs. |
| **Recommended usage** | Any content type                                                                                                                                                                                                                                                                         |
| **Designs** | Optional (does not count on task review)                                                                                                                                                                                                                                                 |

**Figma**  
https://www.figma.com/file/A2sm4Jy2GDlOYWu52kVLbY/Optional-Designs-Back-End?node-id=0%3A1

### Sub tasks

1. Create vocabulary for glossary terms.
2. Terms must have Title and Description (textfield, long)
3. Create functionality to scan the text inside the content type.
4. Create functionality to add description when the title word of the term exists in the page content.

### Optional

1. Create install function that will create the vocabulary on module install
2. Limit the description on the page up to 100 chars, if the description is longer add “Read more” button that will lead user to the term page itself.

## Current Module Notes

- The module injects glossary tooltips into the final rendered HTML, so it can work with formatted text, plain text, nested paragraph output, and Layout Builder-rendered content.
- Glossary terms are loaded from the `glossary` vocabulary, and the term description is used as tooltip content.
- A settings form is available at `/admin/config/content/glossary-tooltip`.
- The settings form allows excluding specific text fields per node bundle from glossary tooltip processing.

## Frontend Assets

Frontend styling is built from SCSS sources stored in `assets/src` and compiled to `assets/dist`.

### Build commands

```bash
cd assets
npm install
npm run build
```

Available scripts:

- `npm run build` builds both expanded and minified CSS files
- `npm run build:css` builds `assets/dist/glossary_tooltip.css`
- `npm run build:min` builds `assets/dist/glossary_tooltip.min.css`
- `npm run watch` watches SCSS changes and rebuilds the expanded CSS output
