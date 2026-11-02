"""End-to-end smoke test: one MCP STDIO session against the local site, every tool exercised.

Requires the local site (bash bin/setup.sh). Run: python tests/smoke.py
"""
import json
import pathlib
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
proc = subprocess.Popen(
    ["docker", "compose", "run", "--rm", "-T", "cli", "wp", "mcp-adapter", "serve", "--user=admin", "--server=wp-mcp"],
    cwd=ROOT, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True, encoding="utf-8",
)
next_id = 0
failures = 0


def rpc(method, params=None):
    global next_id
    next_id += 1
    proc.stdin.write(json.dumps({"jsonrpc": "2.0", "id": next_id, "method": method, "params": params or {}}) + "\n")
    proc.stdin.flush()
    while True:
        line = proc.stdout.readline()
        if not line:
            sys.exit("server exited")
        if line.startswith("{"):
            msg = json.loads(line)
            if msg.get("id") == next_id:
                return msg


def call(tool, args=None):
    """Returns (ok, data) for a tool call."""
    msg = rpc("tools/call", {"name": "wp-mcp-" + tool, "arguments": args or {}})
    if "error" in msg:
        return False, msg["error"]
    result = msg["result"]
    data = result.get("structuredContent")
    if data is None:
        text = result["content"][0]["text"]
        try:
            data = json.loads(text)
        except ValueError:
            data = text
    return not result.get("isError", False), data


def check(name, condition, detail=""):
    global failures
    print(("PASS " if condition else "FAIL ") + name + ("" if condition else f"  -> {detail}"))
    failures += not condition


rpc("initialize", {"protocolVersion": "2025-11-25", "capabilities": {}, "clientInfo": {"name": "smoke", "version": "1"}})
proc.stdin.write(json.dumps({"jsonrpc": "2.0", "method": "notifications/initialized"}) + "\n")

# tools/list + annotations
tools = {t["name"].removeprefix("wp-mcp-"): t for t in rpc("tools/list")["result"]["tools"]}
read_only = {"site-info", "posts-list", "post-get", "rest-get", "option-get", "db-query", "file-list", "file-read"}
check("17 tools listed", len(tools) == 17, sorted(tools))
check("read tools are readOnly", all(tools[t]["annotations"]["readOnlyHint"] for t in read_only))
check("write tools are destructive", all(t["annotations"]["destructiveHint"] for n, t in tools.items() if n not in read_only))

ok, d = call("site-info")
check("site-info", ok and d["wp_version"], d)

# posts
ok, d = call("post-save", {"title": "MCP smoke", "content": "<p>hi</p>", "status": "publish",
                           "meta": {"_mcp_smoke": {"a": 1}}, "terms": {"category": ["MCP Smoke"]}})
check("post-save create", ok and d["id"], d)
pid = d["id"] if ok else 0
ok, d = call("post-get", {"id": pid})
check("post-get meta+terms", ok and d["meta"]["_mcp_smoke"] == {"a": 1} and "MCP Smoke" in d["terms"]["category"], d)
ok, d = call("post-save", {"id": pid, "title": "MCP smoke 2", "meta": {"_mcp_smoke": None}})
ok2, d2 = call("post-get", {"id": pid})
check("post-save update", ok and ok2 and d2["post"]["post_title"] == "MCP smoke 2" and "_mcp_smoke" not in d2["meta"], d2)
ok, d = call("posts-list", {"search": "MCP smoke 2", "post_type": "post"})
check("posts-list", ok and any(p["id"] == pid for p in d["items"]), d)
ok, d = call("post-save", {"id": 99999999, "title": "x"})
check("post-save unknown id errors", not ok, d)
ok, d = call("post-delete", {"id": pid})
ok2, d2 = call("posts-list", {"status": "trash"})
check("post-delete trash", ok and any(p["id"] == pid for p in d2["items"]), d2)
ok, d = call("post-delete", {"id": pid, "force": True})
ok2, d2 = call("post-get", {"id": pid})
check("post-delete force", ok and not ok2, d2)

# media
ok, d = call("media-upload", {"url": "https://s.w.org/style/images/about/WordPress-logotype-wmark.png", "alt": "WP"})
check("media-upload", ok and d.get("id"), d)
if ok:
    call("post-delete", {"id": d["id"], "force": True})

# rest
ok, d = call("rest-get", {"route": "/wp/v2/users", "query": {"context": "edit"}})
check("rest-get users", ok and d["status"] == 200 and d["data"][0]["username"], d)
ok, d = call("rest-write", {"method": "POST", "route": "/wp/v2/tags", "body": {"name": "mcp-smoke-tag"}})
check("rest-write create tag", ok and d["status"] == 201, d)
if ok:
    ok, d = call("rest-write", {"method": "DELETE", "route": f"/wp/v2/tags/{d['data']['id']}", "query": {"force": True}})
    check("rest-write delete tag", ok and d["status"] == 200, d)

# options
ok, d = call("option-update", {"name": "mcp_smoke", "value": {"a": [1, 2]}})
ok2, d2 = call("option-get", {"name": "mcp_smoke"})
check("option-update/get", ok and ok2 and d2["value"] == {"a": [1, 2]}, d2)
ok, d = call("option-get", {"search": "mcp\\_smoke%"})
check("option-get search", ok and "mcp_smoke" in d["names"], d)
call("option-update", {"name": "mcp_smoke", "value": None})
ok, d = call("option-get", {"name": "mcp_smoke"})
check("option delete", not ok, d)

# database
call("db-execute", {"sql": "DROP TABLE IF EXISTS {prefix}mcp_smoke"})  # leftover from an aborted run
ok, d = call("db-execute", {"sql": "CREATE TABLE {prefix}mcp_smoke (id INT)"})
ok2, d2 = call("db-execute", {"sql": "INSERT INTO {prefix}mcp_smoke VALUES (1), (2)"})
check("db-execute", ok and ok2 and d2["rows_affected"] == 2, (d, d2))
ok, d = call("db-query", {"sql": "SELECT COUNT(*) AS n FROM {prefix}mcp_smoke"})
check("db-query", ok and str(d["rows"][0]["n"]) == "2", d)
for bad in ["DELETE FROM {prefix}mcp_smoke", "DROP TABLE {prefix}mcp_smoke", "SELECT 1 INTO OUTFILE '/tmp/x'"]:
    ok, d = call("db-query", {"sql": bad})
    check(f"db-query rejects: {bad[:20]}", not ok, d)
ok, d = call("db-query", {"sql": "SELECT * FROM {prefix}nope"})
check("db-query reports SQL errors", not ok, d)
call("db-execute", {"sql": "DROP TABLE {prefix}mcp_smoke"})

# files
ok, d = call("file-write", {"path": "wp-content/mcp-smoke/a.txt", "content": "hello"})
ok2, d2 = call("file-read", {"path": "wp-content/mcp-smoke/a.txt"})
check("file-write/read", ok and ok2 and d2["content"] == "hello", d2)
ok, d = call("file-list", {"path": "wp-content/mcp-smoke"})
check("file-list", ok and d["items"][0]["name"] == "a.txt", d)
for bad in ["../../etc/passwd", "wp-content/../../etc/passwd", "/../etc/hosts"]:
    ok, d = call("file-read", {"path": bad})
    check(f"file-read blocks {bad}", not ok, d)
ok, d = call("file-write", {"path": "../evil.txt", "content": "x"})
check("file-write blocks ../", not ok, d)
ok, d = call("file-delete", {"path": "wp-content/mcp-smoke/a.txt"})
check("file-delete", ok, d)
call("run-php", {"code": "rmdir( WP_CONTENT_DIR . '/mcp-smoke' );"})

# php
ok, d = call("run-php", {"code": "echo 'hi'; return 1 + 1;"})
check("run-php", ok and d["output"] == "hi" and d["return"] == 2, d)
ok, d = call("run-php", {"code": "return (;"})
check("run-php parse error", not ok, d)

proc.stdin.close()
proc.wait()
print(f"\n{failures} failure(s)")
sys.exit(1 if failures else 0)
