# EditTrace Architecture

EditTrace answers one question: *"Where do I edit this?"* The architecture keeps the DOM inspector generic and pushes all system-specific knowledge into providers.

```
DOM element (browser)
    ↓  inspector/selection.ts, inspector/context.ts
ElementContext (tag, text, href, src, alt, id, classes, allow-listed dataset, ≤10 ancestors, trace token)
    ↓  POST /wp-json/edittrace/v1/inspect
REST\Controller  →  Inspector\ElementContext::from_array()  (sanitize + bound)
    ↓
Inspector\TraceContext (restored TraceSession registry + page context + settings)
    ↓
Inspector\SourceResolver → Providers (priority order) → SourceCandidate[]
    ↓  edittrace/source_candidates filter, dedupe, ranking
Inspector\SourceResult → edittrace/result filter → JSON
    ↓
panel/panel.ts renders Source / Location / Global / Actions / Confidence / Technical details
```

## Request-time tracing (the trace session)

Exact answers require knowing what rendered the page. When an **authorized** user loads a frontend page:

1. `Plugin::maybe_start_session()` (on `template_redirect`) checks `Security\Access::user_can_inspect()` **and** the per-user switch (`Support\UserPreferences`, toggled by the nonce-protected `Admin\Toggle` action from the toolbar) and creates a `TraceSession` with a random 128-bit token. Users who have not switched EditTrace on get no session, no instrumentation and no script.
2. Every provider gets `register_render_hooks( $session )` so it can observe rendering:
   - **Gutenberg** — `Integrations\Gutenberg\BlockInstrumenter` hooks `render_block_data` / `render_block`, tracks the block hierarchy and the *owning source* (post, `wp_template`, `wp_template_part`, `wp_block`, `wp_navigation`, code pattern, widget area) and stamps each block root with `data-edittrace-id="t…"` using `WP_HTML_Tag_Processor`.
   - **Elementor** — records which Elementor documents render (`elementor/frontend/before_get_builder_content`). Elementor's own `data-id` / `data-element_type` / `data-widget_type` / `data-elementor-id` attributes are used as-is.
   - **ACF** — `Integrations\ACF\RenderRegistry` hooks `acf/load_value` and `acf/format_value` and records field key/name/label/type, field group, owning object (post, options, term, user, block), repeater row / flexible layout when inside `have_rows()`, and value *fingerprints* (normalized text, normalized URLs, attachment ids). Raw sensitive values (password fields) are never stored.
   - **Navigation** — `Integrations\Navigation\MenuInstrumenter` stamps classic menu links via `nav_menu_link_attributes` (`data-edittrace-id` + `data-edittrace-kind="menu"`).
3. On `shutdown` the registry is persisted by `TransientTraceStorage` (transients; object cache when available) for 20 minutes. Expired traces are purged by WordPress' transient cleanup and an hourly EditTrace cron sweep.
4. The browser receives only the token, REST URL, nonce and the providers' DOM marker definitions.

The ids in HTML are opaque (`t1`, `t2`, …) and scoped to the token; they carry no database ids and grant no authorization. Anonymous visitors never trigger any of this.

## Resolution

`SourceResolver::resolve()` runs every non-fallback provider whose `supports()` returns true, in priority order:

| Priority | Provider | Evidence |
| --- | --- | --- |
| 100 | `gutenberg` | Opaque render marker → registry entry (exact) |
| 95 | `navigation` | Classic menu marker → registry entry (exact) |
| 90 | `elementor` | Elementor element/document ids → document data via Elementor's API (exact when found in the DOM's document; High when found in another document rendered on the page) |
| 80 | `acf` | Fingerprint match against fields loaded during the trace (exact for equal values; High for partial WYSIWYG/textarea matches; ambiguous when two fields hold the same value) |
| 50 | `media` | `wp-image-N` class, gallery `data-id`, upload URL → attachment |
| 20 | `queried_object` | The value verifiably occurs in the current post's stored content (Possible / High) |
| 5 | `database` (fallback) | Only on `/search`: bounded queries over posts, post meta, `_elementor_data`, options, terms. Never Exact. |

Ranking: confidence descending, then **depth** ascending (the evidence nearest to the clicked element wins), then provider priority. Providers demote themselves to `container` (0.5) when another system's marker sits between the clicked element and their own marker (data-driven via marker `requiredDatasetKeys`), so an Elementor widget inside a Post Content block beats the block, and an ACF value inside a PHP block beats the block. Media candidates never outrank a strong candidate that says where the image was placed; they are shown alongside.

`SourceResult` status: `exact` (top ≥ 1.0), `candidates` (top ≥ 0.4), `unknown`. When exact, weak text matches are hidden from the visible list; containers and media remain as "Also involved".

## Confidence

| Score | Label |
| --- | --- |
| 1.00 | Exact |
| 0.75 – 0.99 | High |
| 0.40 – 0.74 | Possible |
| < 0.40 | Unknown (hidden, listed in Technical details) |

## Frontend

`assets/src/` (TypeScript, bundled with esbuild into `assets/build/edittrace.js`):

- `inspector/selection.ts` — element classification, hover labels from markers, `resolveTarget` (nested spans → button), `meaningfulParent` / `meaningfulChild`.
- `inspector/context.ts` — builds the `ElementContext` (no `outerHTML`, no form values).
- `inspector/highlighter.ts` — fixed-position overlay boxes (no layout shift).
- `inspector/inspector.ts` — Inspector Mode state machine: events, keyboard, requests, panel state.
- `panel/panel.ts` — pure state → HTML rendering of the result panel.
- `api/client.ts` — REST client.

All UI lives in a Shadow DOM under `#edittrace-root` with `edittrace-` prefixed classes; the only light-DOM style is the crosshair cursor rule while inspecting.

## Source layout

```
edittrace.php                 bootstrap, autoloader
src/Plugin.php                composition root, hooks, provider registry
src/Inspector/                TraceSession, TraceRegistry, TraceStorage, ElementContext, TraceContext,
                              SourceCandidate, SourceResult, SourceResolver, Confidence
src/Providers/                SourceProviderInterface, AbstractProvider, Gutenberg/Elementor/ACF/
                              Navigation/Media/QueriedObject/DatabaseFallback providers
src/Integrations/             render-time instrumentation and system helpers (Gutenberg, Elementor, ACF, Navigation)
src/REST/Controller.php       edittrace/v1: POST /inspect, POST /search
src/Admin/                    admin bar node, asset loading, settings page
src/Security/Access.php       authorization gate
src/Search/                   text normalization, ignored strings, fallback search
src/Support/                  Options, Url, EditLinks, BlockLabels
assets/src/                   TypeScript inspector; assets/build/ compiled output
tests/php, tests/js, tests/e2e, tests/env
```

## Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `edittrace/providers` | filter | Register/replace providers (`SourceProviderInterface[]`). |
| `edittrace/source_candidates` | filter | Adjust candidates before ranking (`SourceCandidate[]`, `ElementContext`, `TraceContext`). |
| `edittrace/result` | filter | Adjust the final `SourceResult`. |
| `edittrace/user_can_inspect` | filter | Extend/restrict access (`bool`, `WP_User`). Cannot grant logged-out users. |
| `edittrace/tracing_enabled` | filter | Override the per-user on/off switch (`bool`, user id). |
| `edittrace/ignored_search_strings` | filter | Strings the fallback search ignores. |
| `edittrace/frontend_config` | filter | Configuration passed to the inspector script. |
| `edittrace/session_started` | action | A trace session started for this request (`TraceSession`). |
