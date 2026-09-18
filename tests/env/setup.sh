#!/usr/bin/env bash
#
# Builds a throwaway WordPress site (SQLite, no Docker/MySQL needed) with
# EditTrace, Elementor and ACF installed, for local development and tests.
#
# Environment variables (all optional):
#   EDITTRACE_WP_ROOT   Directory containing a WordPress checkout (default: .wp-local/wordpress)
#   EDITTRACE_DEPS      Directory where dependencies are cloned      (default: .wp-local/deps)
#   EDITTRACE_SITE_URL  Site URL                                     (default: http://127.0.0.1:8787)
#   WP_VERSION          WordPress tag to clone                       (default: 7.1.1)
#   ELEMENTOR_VERSION   Elementor tag to clone                       (default: v3.35.9)
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
LOCAL_DIR="$PLUGIN_DIR/.wp-local"
DEPS="${EDITTRACE_DEPS:-$LOCAL_DIR/deps}"
WP_ROOT="${EDITTRACE_WP_ROOT:-$LOCAL_DIR/wordpress}"
SITE_URL="${EDITTRACE_SITE_URL:-http://127.0.0.1:8787}"
WP_VERSION="${WP_VERSION:-7.1.1}"
ELEMENTOR_VERSION="${ELEMENTOR_VERSION:-v3.35.9}"

mkdir -p "$DEPS" "$LOCAL_DIR"

clone() { # url dir [ref]
	local url="$1" dir="$2" ref="${3:-}"
	if [ ! -d "$dir/.git" ]; then
		echo "Cloning $url -> $dir"
		if [ -n "$ref" ]; then
			GIT_LFS_SKIP_SMUDGE=1 git clone --depth 1 --branch "$ref" "$url" "$dir"
		else
			GIT_LFS_SKIP_SMUDGE=1 git clone --depth 1 "$url" "$dir"
		fi
	fi
}

if [ ! -f "$WP_ROOT/wp-settings.php" ]; then
	clone https://github.com/WordPress/wordpress "$WP_ROOT" "$WP_VERSION"
fi

[ -d "${EDITTRACE_SQLITE_DIR:-}" ] || clone https://github.com/WordPress/sqlite-database-integration "$DEPS/sqlite-database-integration"
[ -d "${EDITTRACE_ELEMENTOR_DIR:-}" ] || clone https://github.com/elementor/elementor "$DEPS/elementor" "$ELEMENTOR_VERSION"
[ -d "${EDITTRACE_ACF_DIR:-}" ] || clone https://github.com/AdvancedCustomFields/acf "$DEPS/acf"

SQLITE_SRC="${EDITTRACE_SQLITE_DIR:-$DEPS/sqlite-database-integration}"
ELEMENTOR_SRC="${EDITTRACE_ELEMENTOR_DIR:-$DEPS/elementor}"
ACF_SRC="${EDITTRACE_ACF_DIR:-$DEPS/acf}"

# The SQLite monorepo ships the plugin with a symlinked database package; build a flat copy.
if [ ! -f "$SQLITE_SRC/build/plugin-sqlite-database-integration/load.php" ]; then
	(cd "$SQLITE_SRC" && bash bin/build-sqlite-plugin-zip.sh >/dev/null)
fi

PLUGINS="$WP_ROOT/wp-content/plugins"
mkdir -p "$PLUGINS" "$WP_ROOT/wp-content/database" "$WP_ROOT/wp-content/uploads" "$WP_ROOT/wp-content/mu-plugins"
ln -sfn "$PLUGIN_DIR" "$PLUGINS/edittrace"
ln -sfn "$SQLITE_SRC/build/plugin-sqlite-database-integration" "$PLUGINS/sqlite-database-integration"
ln -sfn "$ELEMENTOR_SRC" "$PLUGINS/elementor"
ln -sfn "$ACF_SRC" "$PLUGINS/advanced-custom-fields"
ln -sfn "$PLUGIN_DIR/tests/env/mu-plugins/edittrace-test-fixtures.php" "$WP_ROOT/wp-content/mu-plugins/edittrace-test-fixtures.php"
ln -sfn "$PLUGIN_DIR/tests/env/theme" "$WP_ROOT/wp-content/themes/edittrace-test-theme"

# SQLite drop-in.
sed "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$PLUGINS/sqlite-database-integration#" \
	"$SQLITE_SRC/packages/plugin-sqlite-database-integration/db.copy" > "$WP_ROOT/wp-content/db.php"

if [ ! -f "$WP_ROOT/wp-config.php" ]; then
cat > "$WP_ROOT/wp-config.php" <<CFG
<?php
define( 'DB_NAME', 'edittrace' );
define( 'DB_USER', '' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', '' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'DB_ENGINE', 'sqlite' );
define( 'AUTH_KEY',         'edittrace-local-auth-key' );
define( 'SECURE_AUTH_KEY',  'edittrace-local-secure-auth-key' );
define( 'LOGGED_IN_KEY',    'edittrace-local-logged-in-key' );
define( 'NONCE_KEY',        'edittrace-local-nonce-key' );
define( 'AUTH_SALT',        'edittrace-local-auth-salt' );
define( 'SECURE_AUTH_SALT', 'edittrace-local-secure-auth-salt' );
define( 'LOGGED_IN_SALT',   'edittrace-local-logged-in-salt' );
define( 'NONCE_SALT',       'edittrace-local-nonce-salt' );
\$table_prefix = 'wp_';
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', __DIR__ . '/wp-content/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'SCRIPT_DEBUG', true );
define( 'WP_HOME', '$SITE_URL' );
define( 'WP_SITEURL', '$SITE_URL' );
define( 'DISABLE_WP_CRON', true );
define( 'WP_AUTO_UPDATE_CORE', false );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'EDITTRACE_TEST_ENV', true );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
CFG
fi

php "$PLUGIN_DIR/tests/env/install.php" "$WP_ROOT" "$SITE_URL"
echo "WordPress ready at $SITE_URL (root: $WP_ROOT)"
echo "Start it with: php -S 127.0.0.1:8787 -t $WP_ROOT $PLUGIN_DIR/tests/env/router.php"
