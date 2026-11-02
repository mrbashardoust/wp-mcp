# WP MCP

A WordPress plugin that gives AI agents (Claude Code, Claude Desktop, any MCP client) full access to a WordPress site over MCP. It registers its tools through the WordPress Abilities API and serves them with the official [MCP Adapter](https://github.com/WordPress/mcp-adapter).

- Endpoint: `/wp-json/wp-mcp/mcp` (Streamable HTTP)
- Auth: WordPress Application Passwords, admins only (`manage_options`)
- Read tools are marked `readOnlyHint`, write tools `destructiveHint`; pre-approve only the read tools in Claude Code (`permissions.allow`, e.g. `mcp__wordpress__wp-mcp-site-info`) so it asks before anything that changes the site.

## Requirements

WordPress 6.9+, PHP 7.4+, the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin.

## Local development

Needs Docker.

```bash
bash bin/setup.sh      # site at http://localhost:8888, admin / admin
bash tests/smoke.sh    # MCP handshake + tool calls over STDIO
```

## Connect Claude Code

1. In wp-admin go to Users → Profile → Application Passwords and create one.
2. Run:

```bash
claude mcp add --scope local --transport http wordpress https://your-site.com/wp-json/wp-mcp/mcp --header "Authorization: Basic $(printf 'USER:APP_PASSWORD' | base64)"
```

Use a dedicated admin user so you can revoke the password in one click.

## Tools

| Tool | Type |
|---|---|
| `wp-mcp-site-info` | read |
