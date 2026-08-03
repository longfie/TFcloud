#!/bin/sh
set -eu

APP_DIR=/app
VENDOR_DIR="$APP_DIR/vendor"
LOCK_HASH_FILE="$APP_DIR/runtime/.composer.lock.sha256"

# Keep Composer packages out of the image. Install them on first startup (or
# when composer.lock changes) into the persistent tf_vendor volume.
lock_hash="$(sha256sum "$APP_DIR/composer.lock" | cut -d ' ' -f1)"
stored_hash=""
if [ -f "$LOCK_HASH_FILE" ]; then
  stored_hash="$(cat "$LOCK_HASH_FILE")"
fi

if [ ! -f "$VENDOR_DIR/autoload.php" ] || [ "$stored_hash" != "$lock_hash" ]; then
  echo "Installing production Composer dependencies..."
  composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader
  printf '%s\n' "$lock_hash" > "$LOCK_HASH_FILE.tmp"
  mv "$LOCK_HASH_FILE.tmp" "$LOCK_HASH_FILE"
fi

# The installer writes the generated application keys and database settings to
# the persistent runtime volume. Load them before Webman starts so a recreated
# container keeps using the same encryption keys.
if [ -f /app/runtime/.env ]; then
  set -a
  # InstallService writes shell-compatible double-quoted values.
  . /app/runtime/.env
  set +a
elif [ -f /app/.env ]; then
  set -a
  . /app/.env
  set +a
fi

exec "$@"
