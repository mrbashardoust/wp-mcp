#!/usr/bin/env bash
# Smoke test: MCP handshake over STDIO, list tools, call site-info. Requires: bash bin/setup.sh
set -euo pipefail
cd "$(dirname "$0")/.."

out=$(printf '%s\n' \
	'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"smoke","version":"1"}}}' \
	'{"jsonrpc":"2.0","method":"notifications/initialized"}' \
	'{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
	'{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"wp-mcp-site-info","arguments":{}}}' \
	| docker compose run --rm -T cli wp mcp-adapter serve --user=admin --server=wp-mcp 2>/dev/null)

echo "$out"
fail() { echo "FAIL: $1" >&2; exit 1; }
grep -q '"id":2' <<<"$out" && grep -q '"wp-mcp-site-info"' <<<"$out" || fail "site-info not in tools/list"
grep -q '"readOnlyHint":true' <<<"$out" || fail "site-info missing readOnlyHint"
grep -q '"id":3' <<<"$out" && grep -q 'wp_version' <<<"$out" || fail "site-info call returned no wp_version"
grep -q '"isError":true' <<<"$out" && fail "a tool call returned isError"
echo "PASS"
