# EditTrace V1 — Implementation Report

## What was implemented

**Core inspector (Milestone 1).** Plugin bootstrap with a self-contained PSR-4 autoloader, authorization gate (`Security\Access`: logged-in + allowed role + `edit_posts`, `edittrace/user_can_inspect` filter that can never grant anonymous access), admin bar toggle, trace session with a random 128-bit token and server-side registry persisted in short-lived transients, sanitized `ElementContext`, `SourceCandidate` / `SourceResult` model, confidence scoring, provider-based `SourceResolver`, REST namespace `edittrace/v1` (`POST /inspect`, `POST /search`), and a TypeScript inspector (hover highlighter with badges, bottom toolbar, keyboard navigation, meaningful parent/child selection, shadow-DOM result panel with Selected / Source / Location / Global / Usage / Actions / Confidence / Technical details).

**Gutenberg (Milestone 2).** `BlockInstrumenter` hooks `render_block_data` / `render_block`, builds the block hierarchy top-down (frame id stored as a private top-level key on the parsed block array, never in attributes), tracks the owning source (post, `wp_template`, `wp_template_part`, `wp_block`, `wp_navigation`, code pattern, widget area) and stamps block roots with an opaque `data-edittrace-id` via `WP_HTML_Tag_Processor`. `GutenbergProvider` resolves exact block candidates with human hierarchies (`Homepage → Hero → Columns → Column 2 → Buttons → Button`), correct edit links (post editor, site editor for templates/parts/navigation, pattern editor) and global-content flags.

**Elementor (Milestone 3).** `ElementLocator` (pure recursive search over document data), `Documents` (feature-detected wrapper over `Plugin::$instance->documents`, `get_elements_data()`, `get_edit_url()`, widget/element managers, dynamic tag parsing, Theme Builder detection via `get_location()` or library template types). `ElementorProvider` walks up to the nearest Elementor element and document wrapper, resolves against likely documents only (DOM document → documents rendered during the trace → queried object), returns widget/container hierarchy with `Edit in Elementor`, marks Theme Builder / library documents as global, and reports Pro global widgets.

**ACF (Milestone 4).** `RenderRegistry` observes `acf/load_value` and `acf/format_value` and records field key/name/label/type, field group lineage, owning object (post, options, term, user, ACF block), repeater row / flexible layout (when inside `have_rows()`), and value fingerprints (normalized text, URLs, attachment ids). `ACFProvider` matches clicked text / href / image against those fingerprints: exact for equal values, High for partial WYSIWYG/textarea matches, ambiguous (0.9) when two fields hold the same value. Options fields are global; the options page URL is resolved from field-group location rules when ACF PRO's `acf_get_options_pages()` exists.

**Core sources (Milestone 5).** Media Library provider (`wp-image-N`, gallery `data-id`, upload URL incl. size suffixes and `-scaled`), classic menu instrumentation (`nav_menu_link_attributes`) and provider, block navigation via the Gutenberg source stack, core data blocks mapped to their real sources (post title/excerpt/date/author/featured image → the post; site title/tagline → Settings → General; site logo → site editor/customizer), third-party dynamic blocks reported as high-confidence containers.

**Fallback search (Milestone 6).** `FallbackSearch` runs bounded, prepared queries over posts (title exact, content LIKE), post meta (exact then LIKE, private keys excluded, ACF labels resolved), `_elementor_data` (JSON-encoded needle, element located and hierarchy reported), options (sensitive names excluded, core settings mapped to screens) and terms — at most 14 queries / 2.5 s / 10 results. Only runs from `POST /search`; the panel triggers it automatically when a result is Unknown. Fallback candidates are never Exact.

**Per-user switch.** EditTrace is off for every user until they switch it on from the toolbar (nonce-protected admin action stored in user meta). While off, no trace session, instrumentation, markup or script exists for that user; the toolbar only offers "EditTrace" → turn on (which reopens the page in Inspector Mode). While on, the toolbar toggles Inspector Mode and offers "Turn off EditTrace".

**Production pass (Milestone 7).** Depth-aware ranking (nearest evidence wins; enclosing blocks/elements demote themselves to containers; media never outranks placement), weak-noise filtering, accessibility (roles, aria-live, focus management, keyboard support), responsive bottom-sheet panel, narrow-screen launcher, transient purge cron, uninstall cleanup, settings page, documentation and installable ZIP.

## Exact vs best-effort

| Exact (1.0) | Best-effort / lower confidence |
| --- | --- |
| Any block stamped during the trace (page blocks, template parts, synced patterns, navigation links, core data blocks) | Third-party dynamic blocks → container (0.8) with explanation |
| Elementor element id found in the DOM-declared document | Element found in another rendered document (0.95); document-only match (0.7–0.9) |
| ACF value equal to the clicked text / href / attachment, single match | Partial WYSIWYG/textarea match (0.85); two fields with the same value (0.9 each); link href-only match (0.75–0.9) |
| Classic menu items stamped during the trace | — |
| Attachment by class/data-id/URL | — |
| — | Queried object content contains the value (0.5–0.85) |
| — | Fallback search hits (0.5–0.9), never Exact |
| Repeater row / flexible layout when read inside `have_rows()` | Row/layout omitted when the whole repeater is formatted at once |
| ACF Options page link when the field group's `options_page` rule matches a registered page | No link (with explanation) on ACF free or when no rule matches; secondary "Field Group Settings" link when available |

## Known limitations

- **Page caching.** Traces are collected during a real render. If a caching layer serves a cached page to a logged-in admin, no trace exists and only DOM evidence (Elementor ids, `wp-image` classes) plus fallback search remain. Most caches bypass logged-in users.
- **Values loaded before `template_redirect`** (e.g. ACF fields read in `init`) are not observed; ACF's per-request value cache may then serve them without firing hooks later.
- **Elementor dynamic tags** are shown as details on the widget; the content candidate itself comes from the ACF provider when the field was read during the render.
- **Elementor Theme Builder usage counts** and ACF option usage counts are not computed (scope is flagged as global instead).
- **Unsynced patterns** inserted into content are ordinary blocks and resolve to the page; `core/pattern` code patterns have no edit URL (theme/plugin files).
- **ACF free** has no options page UI, repeaters or flexible content; those code paths are tested with local field groups and a loop simulation, not a PRO installation. Elementor Pro's Theme Builder is simulated with a minimal `header` document type in the test environment; the provider only relies on public document APIs plus feature detection.
- **Block widgets** in classic-theme sidebars are reported as "Widget Area → Edit Widgets" without the specific widget id.
- The Elementor test checkout has no built JS/CSS assets (git tag without a build), so the test site logs Elementor script errors that are unrelated to EditTrace.

## Security measures

- No output, script, style, metadata, registry or REST access for logged-out users (automated PHPUnit + Playwright coverage).
- Per-user opt-in switch: nothing is loaded or traced until the user turns EditTrace on (`admin-post` action with nonce + capability check, `wp_safe_redirect`).
- Role allow-list + `edit_posts`; `edittrace/user_can_inspect` cannot grant anonymous users.
- REST: authentication, `X-WP-Nonce` (`wp_rest`) verification, permission callback, JSON schema validation of the trace token, full sanitization/bounding of the element context (tag, text ≤ 1000, URLs via `esc_url_raw`, classes/dataset allow-lists, ≤ 10 ancestors).
- Trace registries are bound to the user id that created them, expire after 20 minutes, are capped at 4000 entries, and store fingerprints instead of raw values; password-type fields are never recorded.
- No `outerHTML`, no form values, no cookies/nonces in trace data; frontend HTML only carries opaque `t…` ids.
- All SQL uses `$wpdb->prepare()`; fallback search excludes private meta keys, transients and sensitive option names, and never echoes option values.
- Panel rendering escapes every value; edit links are restricted to http(s)/relative URLs; `rel="noopener"` on all links.

## Performance considerations

- Anonymous requests: one option read on `template_redirect`, nothing else.
- Authorized requests: block/menu instrumentation is O(blocks); ACF fingerprinting O(fields read); Elementor records document ids only. Registry persisted once at shutdown (object cache when available).
- Inspect requests touch only registry data, the specific Elementor documents involved, and attachment lookups (memoized per request). No database scans.
- Fallback search: on request only, LIMITed queries, time budget, exact before partial, ignored generic strings, needle length limits.
- Frontend bundle: 37 KB minified, loaded deferred, only for authorized users; hover work is throttled with `requestAnimationFrame`; overlays are fixed-position (no layout shift).

## Test coverage and results

| Suite | Count | Result |
| --- | --- | --- |
| PHPUnit unit (normalization, URLs, confidence, registry/session/storage, element context, Elementor locator, options, resolver ranking/filters/errors) | 24 tests | pass |
| PHPUnit integration (access + anonymous, REST auth/nonce/schema, Gutenberg instrumentation incl. synced pattern & template part & site title, Elementor page/header/fallbacks, ACF registry/matching/ambiguity/fingerprints/loops, media, classic menus, queried object, fallback search + sensitive options) | 29 tests | pass |
| Vitest (context extraction incl. form-value exclusion, selection/meaningful parent-child/labels, panel states/escaping/actions) | 15 tests | pass |
| Playwright E2E (core inspector, Gutenberg heading/nested button/template part/pattern/navigation, Elementor heading/nested widgets/Theme Builder header, ACF page fields/link/image/WYSIWYG/options, media, classic menu, site title, unknown content, fallback search, anonymous user) | 20 tests | pass |

Run with `vendor/bin/phpunit`, `npm test`, `npm run test:e2e` (see DEVELOPMENT.md).

## Recommended V1.1 priorities

1. Usage information for global sources (Theme Builder conditions, template part usage, ACF option usage) in the Usage section.
2. Elementor dynamic-tag content candidates without relying on the ACF render hook (parse tag settings into an explicit ACF/post-field candidate).
3. Block widget ids and Customizer/theme-mod sources for classic themes.
4. Deep links: Elementor `#e:run:panel/editor/open` style element focus when a documented API exists; site editor block focus.
5. Provider packages for Bricks, Divi, Beaver Builder, WPBakery using the documented provider API.
6. Optional persistent trace store (custom table) for sites without an object cache and very large pages.
