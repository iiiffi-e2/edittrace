# Developing EditTrace

## Prerequisites

- PHP 8.0+ with the `sqlite3`/`pdo_sqlite` extensions (for the local test site), Composer
- Node.js 20+
- Git (the local environment clones WordPress, Elementor and ACF from GitHub — no Docker or MySQL needed)

## Setup

```bash
composer install            # PHPUnit
npm install                 # esbuild, TypeScript, Vitest, Playwright
npm run build               # compiles assets/src → assets/build/edittrace.js
```

## Local WordPress site (SQLite)

`tests/env/setup.sh` builds a complete throwaway site with EditTrace, Elementor, ACF, the test theme and fixtures:

```bash
bash tests/env/setup.sh
php -S 127.0.0.1:8787 -t .wp-local/wordpress tests/env/router.php
# → http://127.0.0.1:8787  (admin / password)
```

Environment variables: `EDITTRACE_WP_ROOT`, `EDITTRACE_DEPS`, `EDITTRACE_SITE_URL`, `WP_VERSION`, `ELEMENTOR_VERSION`, and `EDITTRACE_SQLITE_DIR` / `EDITTRACE_ELEMENTOR_DIR` / `EDITTRACE_ACF_DIR` to reuse existing checkouts.

Fixture pages (see `tests/env/fixtures.php`):

| URL | What it exercises |
| --- | --- |
| `/` | Gutenberg homepage: heading, paragraph, group → columns → button, image block, synced pattern; header/footer template parts; block navigation; classic menu; ACF options in the footer; hook-printed untraceable text and option-backed text. |
| `/elementor-landing/` | Elementor page (containers, heading, text, button, image) plus a simulated Theme Builder header document. |
| `/acf-demo/` | ACF page fields rendered by a PHP block: text, WYSIWYG, link, image, (repeater with ACF PRO). |

The test theme (`tests/env/theme`) is a minimal block theme; the mu-plugin (`tests/env/mu-plugins`) registers ACF field groups and, when Elementor Pro is absent, a minimal `header` library document type so the Theme Builder code path can be exercised with Elementor free.

## Tests

```bash
vendor/bin/phpunit                       # unit + integration (needs the local site)
vendor/bin/phpunit --testsuite unit
npm test                                 # Vitest (jsdom) for the inspector
npm run typecheck
npm run test:e2e                         # Playwright against http://127.0.0.1:8787
```

PHPUnit loads WordPress from `.wp-local/wordpress` (override with `EDITTRACE_WP_ROOT`). Playwright uses the pre-installed Chromium at `/opt/pw-browsers/chromium` when present, or set `EDITTRACE_CHROME`.

Handy scripts:

```bash
node bin/dev-shot.mjs http://127.0.0.1:8787/ '.home-cta a' out.png          # inspect + screenshot
node bin/dev-candidates.mjs http://127.0.0.1:8787/acf-demo/ '.company-phone' # list all candidates
```

## Build and package

```bash
npm run build      # production bundle
npm run zip        # dist/edittrace-<version>.zip (installable)
```

## Coding conventions

- PHP: `declare(strict_types=1)`, namespaced under `EditTrace\`, PSR-4 in `src/`, WordPress coding style (tabs, Yoda-free but escaped output, `$wpdb->prepare()`).
- Providers isolate all system-specific logic; the inspector stays generic (see PROVIDERS.md).
- Anything that touches undocumented internals must be feature-detected, wrapped in try/catch, and fail safely.
- Never place database ids or values in the frontend HTML; use opaque registry ids.
- CSS lives in the shadow root and every class is `edittrace-` prefixed.
