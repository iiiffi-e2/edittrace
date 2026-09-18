#!/usr/bin/env bash
#
# Builds an installable plugin ZIP (dist/edittrace.zip) containing only
# runtime files: PHP source, compiled assets, readme.txt and docs.
#
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

npm run build >/dev/null
VERSION=$(grep -m1 "Version:" edittrace.php | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
STAGE="$(mktemp -d)/edittrace"
mkdir -p "$STAGE" dist

cp edittrace.php uninstall.php readme.txt README.md ARCHITECTURE.md PROVIDERS.md LICENSE "$STAGE/" 2>/dev/null || true
cp -R src "$STAGE/src"
mkdir -p "$STAGE/assets"
cp -R assets/build "$STAGE/assets/build"
mkdir -p "$STAGE/languages"

rm -f "dist/edittrace-$VERSION.zip" dist/edittrace.zip
( cd "$(dirname "$STAGE")" && zip -rq "$ROOT/dist/edittrace-$VERSION.zip" edittrace -x '*.DS_Store' )
cp "dist/edittrace-$VERSION.zip" dist/edittrace.zip
rm -rf "$(dirname "$STAGE")"
echo "Built dist/edittrace-$VERSION.zip"
