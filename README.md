# EditTrace

**Click anything on your WordPress site. See exactly where to edit it.**

EditTrace adds an inspector to the frontend of your site for logged-in administrators. Activate it from the toolbar, hover over anything you can see, click it, and EditTrace tells you where that content comes from — the page, a Gutenberg block, a template part, a synced pattern, an Elementor widget, an Elementor Theme Builder template, an ACF field, an ACF Options page, a menu, the Media Library — and gives you a direct "Edit" button.

```
Request a Demo

SOURCE        Elementor · Homepage · Widget: Button
LOCATION      Homepage → Hero Container → CTA Container → Button
ACTIONS       [Edit in Elementor]
CONFIDENCE    Exact
```

## What it traces (V1)

| System | Coverage |
| --- | --- |
| **Gutenberg / block editor** | Pages, posts and custom post types; every core block including nested groups/columns; block templates, template parts, synced patterns, navigation blocks, Query Loop post fields, Site Title/Tagline/Logo. |
| **Elementor** | Pages and posts, containers/sections/columns, widgets and nested widgets, saved templates, Theme Builder headers/footers and other theme documents (reported as global content), global widgets, dynamic tag hints. |
| **Advanced Custom Fields** | Fields on posts/pages/CPTs, terms, users and ACF blocks; Options Page fields (global); text, textarea, WYSIWYG, URL, link, image, file, gallery, number, select/radio/checkbox, post object/relationship, taxonomy; repeater and flexible-content sub fields (row/layout best-effort). |
| **WordPress core** | Media Library attachments, classic menus (Appearance → Menus), block navigation menus, site settings, current queried object, bounded fallback search over posts, custom fields, Elementor data, options and terms. |

Everything is scored with a confidence (Exact / High / Possible / Unknown). EditTrace never presents a probable match as exact and never invents a result: when it does not know, it says so and offers the next best step.

## Requirements

- WordPress 6.5+ (block themes and classic themes)
- PHP 8.0+
- Optional: Elementor 3.x, Advanced Custom Fields 6.x (free or PRO)

## Installation

1. Download `dist/edittrace.zip` (or build it with `npm install && npm run zip`).
2. Plugins → Add New → Upload Plugin → activate.
3. Visit any frontend page while logged in as an administrator and click **EditTrace** in the toolbar.

## Using the inspector

- **Hover** to highlight the element under the cursor. A badge names what it is (`Elementor · Heading`, `Gutenberg · Button`, `Menu · Link`).
- **Click** to freeze the selection and open the result panel.
- **↑ / ↓** (or the toolbar buttons) move to a meaningful parent or child element.
- **Enter** inspects the current selection. **Esc** closes the panel, then exits Inspector Mode.
- The panel shows the source, its location within the container, a **Global content** warning when edits affect more than one page, the edit actions, the confidence, and collapsed **Technical details**.
- When no exact source is found, EditTrace searches WordPress storage for the clicked value (can be disabled in settings) and lists possible sources ranked by confidence.

## Settings

Settings → EditTrace:

- **Enable EditTrace**
- **Allowed roles** (administrators always have access; users also need `edit_posts`)
- **Fallback search** and **Maximum search results**
- **Developer debug mode** (full technical details in the panel)

## Security and privacy

- Logged-out visitors receive no EditTrace scripts, styles, markup, metadata or REST access. This is verified by an automated test.
- Trace data collected while rendering a page is stored server-side under a random per-request token and expires after 20 minutes. The browser only receives the token and opaque element ids.
- The inspector never transmits `outerHTML` or values of form fields; only tag names, text, link/image URLs, ids, classes and allow-listed data attributes.
- All REST requests require authentication, a valid REST nonce and the EditTrace capability check; all input is sanitized and bounded; all SQL uses `$wpdb->prepare()`.

## Documentation

- [ARCHITECTURE.md](ARCHITECTURE.md) — how a click becomes a result
- [PROVIDERS.md](PROVIDERS.md) — the provider API and how to add a builder
- [DEVELOPMENT.md](DEVELOPMENT.md) — local environment, tests, builds

## License

GPL-2.0-or-later.
