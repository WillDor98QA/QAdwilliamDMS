#!/usr/bin/env bash
# Throwaway local WordPress site for manual and browser testing.
# Lives in .dev/site (git-ignored), uses the private MySQL from bin/test-db.sh,
# symlinks the plugin, and writes outgoing email to .dev/mail.log.
#
#   bin/dev-site.sh setup   # build + install + seed (idempotent: rebuilds from scratch)
#   bin/dev-site.sh start   # http://127.0.0.1:8088
#   bin/dev-site.sh stop
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SITE="$ROOT/.dev/site"
SOCK="$ROOT/.dev/mysql/mysql.sock"
PID="$ROOT/.dev/php-server.pid"
PORT="${DMS_DEV_PORT:-8088}"
URL="http://127.0.0.1:$PORT"

setup() {
  "$ROOT/bin/test-db.sh" start >/dev/null
  mysql --no-defaults -uroot --socket="$SOCK" -e "DROP DATABASE IF EXISTS dms_dev; CREATE DATABASE dms_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  rm -rf "$SITE"
  mkdir -p "$SITE"
  cp -R "$ROOT/vendor/roots/wordpress-no-content/." "$SITE/"
  mkdir -p "$SITE/wp-content/plugins" "$SITE/wp-content/mu-plugins" "$SITE/wp-content/themes" "$SITE/wp-content/uploads"
  ln -s "$ROOT/plugin/data-management-system" "$SITE/wp-content/plugins/data-management-system"
  cp -R "$ROOT/bin/dev-site/theme" "$SITE/wp-content/themes/dms-dev"
  cp "$ROOT/bin/dev-site/mail-catcher.php" "$SITE/wp-content/mu-plugins/"
  local salt
  salt="$(openssl rand -hex 32)"
  cat > "$SITE/wp-config.php" <<CFG
<?php
define( 'DB_NAME', 'dms_dev' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost:$SOCK' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
\$table_prefix = 'wp_';
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as \$k ) { define( \$k, '$salt' . \$k ); }
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', '$ROOT/.dev/wp-debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_HOME', '$URL' );
define( 'WP_SITEURL', '$URL' );
define( 'DMS_MAIL_LOG', '$ROOT/.dev/mail.log' );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
CFG
  : > "$ROOT/.dev/mail.log"
  php "$ROOT/bin/dev-site/seed.php" "$SITE" "$ROOT/.dev/dev-site-credentials.txt"
  echo "Site ready. Credentials: .dev/dev-site-credentials.txt"
}

start() {
  if [[ -f "$PID" ]] && kill -0 "$(cat "$PID")" 2>/dev/null; then echo "Already running at $URL"; return; fi
  php -S "127.0.0.1:$PORT" -t "$SITE" "$ROOT/bin/dev-site/router.php" > "$ROOT/.dev/php-server.log" 2>&1 &
  echo $! > "$PID"
  sleep 1
  echo "Running at $URL"
}

stop() {
  if [[ -f "$PID" ]]; then kill "$(cat "$PID")" 2>/dev/null || true; rm -f "$PID"; echo "Stopped."; else echo "Not running."; fi
}

case "${1:-}" in
  setup) setup ;;
  start) start ;;
  stop) stop ;;
  *) echo "Usage: $0 {setup|start|stop}" >&2; exit 2 ;;
esac
