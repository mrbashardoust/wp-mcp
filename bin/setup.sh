#!/usr/bin/env bash
# Starts the local site and installs WordPress + MCP Adapter + this plugin. Safe to re-run.
set -euo pipefail
cd "$(dirname "$0")/.."

wp() { docker compose run --rm -T cli wp "$@"; }

docker compose up -d

# wp-config.php is written by the wordpress container on first boot; wait for it and the DB.
until wp db check >/dev/null 2>&1; do sleep 2; done

wp core is-installed 2>/dev/null || wp core install \
	--url=http://localhost:8888 --title="WP MCP Dev" \
	--admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email

wp plugin install mcp-adapter --activate
wp plugin activate wp-mcp
wp rewrite structure '/%postname%/'

wp core version
wp plugin list --fields=name,status,version
