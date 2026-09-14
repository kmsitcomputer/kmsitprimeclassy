#!/usr/bin/env bash
# Assembles the single-domain, shared-hosting-ready production package.
#
# Layout produced (matches backend/bootstrap/app.php's single-root detection:
# a `public_html/laravel.php` sibling of `backend/` switches Laravel's public
# path to that shared document root):
#
#   <output>/
#     public_html/   <- frontend build + laravel.php + .htaccess (the site's document root)
#     backend/       <- Laravel app, vendor installed for production, no .env (installer wizard writes it)
#
# Usage: ./deploy/build.sh [output-name]
# Produces deploy/output/<output-name>/ and deploy/output/<output-name>.zip

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NAME="${1:-primeclassy-singledomain-$(date +%Y%m%d)}"
OUT_DIR="$ROOT_DIR/deploy/output/$NAME"

echo "==> Building frontend"
(cd "$ROOT_DIR/frontend" && npm run build)

echo "==> Preparing staging directory: $OUT_DIR"
rm -rf "$OUT_DIR"
mkdir -p "$OUT_DIR/public_html"

echo "==> Assembling public_html/ (frontend build + single-domain front controller)"
cp -r "$ROOT_DIR/frontend/dist/." "$OUT_DIR/public_html/"
cp "$ROOT_DIR/deploy/public_html/laravel.php" "$OUT_DIR/public_html/laravel.php"
cp "$ROOT_DIR/deploy/public_html/.htaccess" "$OUT_DIR/public_html/.htaccess"
cp "$ROOT_DIR/deploy/public_html/config.js" "$OUT_DIR/public_html/config.js"

echo "==> Copying backend/ (excluding dev-only files)"
rsync -a \
  --exclude '.env' \
  --exclude '.env.bak' \
  --exclude '.env.testing' \
  --exclude '.git' \
  --exclude 'node_modules' \
  --exclude 'tests' \
  --exclude 'storage/framework/cache/data' \
  --exclude 'storage/framework/sessions' \
  --exclude 'storage/framework/views' \
  --exclude 'storage/logs' \
  --exclude 'storage/app/installed.lock' \
  --exclude 'database/database.sqlite' \
  --exclude '.phpunit.result.cache' \
  "$ROOT_DIR/backend/" "$OUT_DIR/backend/"

echo "==> Installing production PHP dependencies in the staged backend/"
(cd "$OUT_DIR/backend" && composer install --no-dev --optimize-autoloader --no-interaction)

echo "==> Linking public storage into the document root"
# Single-domain layout: the document root is public_html/, NOT backend/public/,
# so Laravel's own `storage:link` (which creates backend/public/storage) would
# be unreachable. Uploaded media is served under /storage/* — create the same
# link at the shared root so it resolves. Relative target keeps the package
# portable; preserved in the zip via `zip -y` below.
mkdir -p "$OUT_DIR/backend/storage/app/public"
ln -sfn "../backend/storage/app/public" "$OUT_DIR/public_html/storage"

echo "==> Clearing caches baked into the copy (installer wizard rebuilds these post-install)"
(cd "$OUT_DIR/backend" && php artisan config:clear && php artisan route:clear && php artisan view:clear) || true

# The commands above can themselves fail (e.g. view:clear on this
# single-domain layout, which has no Blade view path) and write a fresh
# laravel.log into the staged copy AFTER the rsync --exclude 'storage/logs'
# above already ran — that log would otherwise ship inside the zip, leaking
# this build machine's local file paths. Never ship logs from the build.
rm -f "$OUT_DIR/backend/storage/logs/"*.log

echo "==> Zipping"
# -y preserves the public_html/storage symlink instead of dereferencing it
# (which would embed the entire uploads directory in duplicate).
(cd "$ROOT_DIR/deploy/output" && zip -r -q -y "$NAME.zip" "$NAME")

echo "==> Done: deploy/output/$NAME.zip"
