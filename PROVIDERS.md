# EditTrace Providers

A **provider** maps a clicked element to zero or more `SourceCandidate`s for one content system. The DOM inspector knows nothing about Gutenberg, Elementor or ACF; it only understands *DOM markers* that providers declare. Adding support for another builder (Bricks, Divi, Beaver Builder…) means adding one provider class and registering it — the inspector does not change.

## The interface

```php
namespace EditTrace\Providers;

interface SourceProviderInterface {
    public function get_name(): string;            // unique machine name, e.g. "bricks"
    public function get_priority(): int;           // higher runs first (100 = render markers, 5 = fallback)
    public function supports( ElementContext $context, TraceContext $trace ): bool;
    /** @return SourceCandidate[] */
    public function resolve( ElementContext $context, TraceContext $trace ): array;
    public function is_fallback(): bool;           // true = only runs for POST /search
    public function register_render_hooks( TraceSession $session ): void; // authorized renders only
    /** @return array<int,array<string,mixed>> */
    public function get_dom_markers(): array;
}
```

Extend `AbstractProvider` to get sensible defaults (`priority 10`, no markers, no render hooks) and helpers:

- `$this->candidate( $source_type )` — creates a `SourceCandidate` bound to this provider.
- `$this->closer_foreign_marker_depth( $context, $trace, $own_depth )` — tells you whether another provider's marker sits closer to the clicked element than yours, so you can demote yourself to a container.

## ElementContext

What the browser sent, sanitized and bounded (`src/Inspector/ElementContext.php`):

| Property | Meaning |
| --- | --- |
| `tag`, `text`, `href`, `src`, `alt`, `id`, `classes[]` | The clicked element. Text ≤ 1000 chars, no form values. |
| `dataset` | Allow-listed `data-*` attributes (`edittrace*` plus the keys your markers request). |
| `ancestors[]` | Up to 10 ancestors, nearest first, each with `tag`, `id`, `classes`, `dataset`. |
| `chain()` | Element followed by ancestors. |
| `nearest_with_dataset( $key )`, `nearest_with_class( $regex )` | Find the nearest node (self first) with a dataset key / class; returns node + depth. |

## TraceContext

- `get_registry()` — the `TraceRegistry` filled while the page rendered, or `null` when the trace expired / was not sent. Use `by_kind( $kind )` and `get( $id )`.
- `get_page()` — page context recorded at render: `object_type`, `object_id`, `post_type`, `title`, `edit_url`, `template`, `url`.
- `remember( $key, $callable )` — per-request memo shared between providers.
- `note( $text )` — diagnostic shown in Technical details.
- `get( 'dom_markers' )` — every provider's markers (set by the resolver).

## SourceCandidate

Fill the fields the panel understands:

| Field | Example |
| --- | --- |
| `system` | `Elementor`, `Advanced Custom Fields` |
| `source_type` / `source_id` | `elementor_widget` / `821` |
| `source_label` / `source_name` | `Page` / `Homepage`, `Options Page` / `Site Options` |
| `item_label` / `item_name` / `item_key` | `Widget` / `Button` / `button`, `Field` / `Phone Number` / `phone_number` |
| `hierarchy[]` | `['Homepage', 'Hero', 'CTA Container', 'Button']` |
| `edit_url` / `edit_label` | Only documented, real admin URLs. Use `Support\EditLinks`. |
| `actions[]` | Secondary `{label,url}` actions (`add_action()`). |
| `global` / `global_note` | `mark_global( $note )` for template parts, options, theme builder templates… |
| `role` | `content` (default), `structure`, `container`, `media` |
| `depth` | Ancestor distance of your evidence from the clicked element (0 = the element itself). Used for ranking. |
| `details` | Extra label ⇒ value rows (`Field Group`, `Row 2`, `Destination`). |
| `technical` | Non-sensitive metadata for Technical details (`postId`, `documentId`, `elementId`, `blockName`, `blockPath`, `fieldKey`, `objectId`, `template`…). |
| `with_confidence( $score, $reason )` | 0.00–1.00 plus a one-sentence justification. |

### Confidence rules

- `1.0` only when the evidence proves this element came from this source (render marker, element id found in the document, equal fingerprint with no other equal candidate).
- `0.75–0.99` when strongly supported but not proven (value found in another rendered document; partial WYSIWYG match; identical values in two fields).
- `0.40–0.74` for plausible matches (stored content contains the value).
- Fallback (`is_fallback() === true`) providers must never return `1.0`.

## DOM markers

Markers tell the inspector which elements carry source information, how to label them on hover, and which `data-*` keys to send to the server:

```php
public function get_dom_markers(): array {
    return array(
        array(
            'selector'             => '.brxe-element[data-brx-id]',   // CSS selector
            'label'                => 'Bricks',                        // hover badge prefix
            'typeFromDataset'      => 'brxType',                       // optional: dataset key naming the element type
            'typeFallbackDataset'  => 'brxKind',                       // optional
            'typeFromClassPrefix'  => 'brxe-',                         // optional: class prefix naming the type
            'datasetKeys'          => array( 'brxId', 'brxType' ),     // dataset keys to transmit
            'requiredDatasetKeys'  => array( 'brxId' ),                // server-side marker test (for demotion logic)
            'forbiddenDatasetKeys' => array(),
        ),
    );
}
```

Dataset keys use the DOM `dataset` property names (`data-brx-id` → `brxId`; `data-element_type` → `element_type`).

## Render hooks

`register_render_hooks( TraceSession $session )` is called on `template_redirect` only for authorized users. Use it to observe rendering and store what you learn in the registry:

```php
$id = $session->get_registry()->register( 'bricks_element', array( 'post_id' => 12, 'element_id' => 'abc' ) );
// optionally stamp your HTML with data-edittrace-id="$id"
```

Registry entries must be serializable and must not contain secrets or large values; store fingerprints instead. The registry is capped (4000 entries) and expires with the trace.

## Registering a provider

```php
add_filter( 'edittrace/providers', function ( array $providers ): array {
    $providers[] = new My_Bricks_Provider();
    return $providers;
} );
```

## Minimal example

```php
final class My_Bricks_Provider extends \EditTrace\Providers\AbstractProvider {
    public function get_name(): string { return 'bricks'; }
    public function get_priority(): int { return 90; }

    public function get_dom_markers(): array {
        return array( array(
            'selector'            => '[data-brx-id]',
            'label'               => 'Bricks',
            'datasetKeys'         => array( 'brxId' ),
            'requiredDatasetKeys' => array( 'brxId' ),
        ) );
    }

    public function supports( $context, $trace ): bool {
        return function_exists( 'bricks_is_builder' ) && null !== $context->nearest_with_dataset( 'brxId' );
    }

    public function resolve( $context, $trace ): array {
        $found = $context->nearest_with_dataset( 'brxId' );
        $page  = $trace->get_page();
        // ... look the element up in Bricks' stored data for the queried post ...
        $c = $this->candidate( 'bricks_element' );
        $c->system = 'Bricks'; $c->source_name = $page['title']; $c->depth = $found['depth'];
        $c->edit_url = add_query_arg( 'bricks', 'run', get_permalink( (int) $page['object_id'] ) );
        $c->edit_label = 'Edit in Bricks';
        return array( $c->with_confidence( 1.0, 'Element id found in Bricks data.' ) );
    }
}
```

## Built-in providers

| Provider | File | Notes |
| --- | --- | --- |
| `gutenberg` | `Providers/GutenbergProvider.php`, `Integrations/Gutenberg/BlockInstrumenter.php` | Exact blocks, block path, owning source; core data blocks mapped to their real settings; third-party dynamic blocks reported as containers (0.8). |
| `navigation` | `Providers/NavigationProvider.php`, `Integrations/Navigation/MenuInstrumenter.php` | Classic menus. Block navigation is handled by `gutenberg`. |
| `elementor` | `Providers/ElementorProvider.php`, `Integrations/Elementor/*` | Uses `Plugin::$instance->documents`, `get_elements_data()`, `get_edit_url()`, widget/element managers, dynamic tag parsing. Theme Builder detected via `get_location()` (feature-detected) or library template types. |
| `acf` | `Providers/ACFProvider.php`, `Integrations/ACF/*` | Render registry via `acf/load_value` + `acf/format_value`; `acf_decode_post_id`, `acf_get_field`, `acf_get_field_group`, `acf_get_loop`, `acf_get_options_pages` (PRO, feature-detected). |
| `media` | `Providers/MediaProvider.php` | `attachment_url_to_postid`, `wp-image-N`, `data-id`. |
| `queried_object` | `Providers/QueriedObjectProvider.php` | Weak evidence, only when the value verifiably occurs in the queried post. |
| `database` | `Providers/DatabaseFallbackProvider.php`, `Search/FallbackSearch.php` | On request only; bounded, prepared queries; sensitive option names excluded. |
