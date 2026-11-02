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
python tests/smoke.py  # one MCP session over STDIO, every tool exercised
```

## Connect Claude Code

1. In wp-admin go to Users → Profile → Application Passwords and create one.
2. Run:

```bash
claude mcp add --scope local --transport http wordpress https://your-site.com/wp-json/wp-mcp/mcp --header "Authorization: Basic $(printf 'USER:APP_PASSWORD' | base64)"
```

Use a dedicated admin user so you can revoke the password in one click.

## Tools

| Tool | What it does | Type |
|---|---|---|
| `wp-mcp-site-info` | Versions, theme, plugins, post types, taxonomies, REST namespaces | read |
| `wp-mcp-posts-list` | List posts of any type (posts, pages, products, CPTs) | read |
| `wp-mcp-post-get` | One post with raw content, all meta (incl. SEO fields) and terms | read |
| `wp-mcp-post-save` | Create/update any post type, its meta, terms, featured image | write |
| `wp-mcp-post-delete` | Trash, or delete permanently with `force` | write |
| `wp-mcp-media-upload` | Sideload a file from a URL into the media library | write |
| `wp-mcp-rest-get` | GET any REST route as admin: users, comments, menus, settings, plugins, WooCommerce `wc/v3`... | read |
| `wp-mcp-rest-write` | POST/PUT/PATCH/DELETE any REST route, e.g. install/activate plugins, Woo orders | write |
| `wp-mcp-option-get` | Read an option, or search option names with LIKE | read |
| `wp-mcp-option-update` | Set an option; `null` deletes it | write |
| `wp-mcp-db-query` | Read-only SQL (`{prefix}` = table prefix), max 1000 rows | read |
| `wp-mcp-db-execute` | Any single SQL statement | write |
| `wp-mcp-file-list` / `file-read` | Browse and read files inside the WordPress root | read |
| `wp-mcp-file-write` / `file-delete` | Write or delete files inside the WordPress root | write |
| `wp-mcp-run-php` | Run PHP inside WordPress, last resort | write |

Pre-approve the read tools in Claude Code (replace `wordpress` with your server name):

```json
{
  "permissions": {
    "allow": [
      "mcp__wordpress__wp-mcp-site-info", "mcp__wordpress__wp-mcp-posts-list", "mcp__wordpress__wp-mcp-post-get",
      "mcp__wordpress__wp-mcp-rest-get", "mcp__wordpress__wp-mcp-option-get", "mcp__wordpress__wp-mcp-db-query",
      "mcp__wordpress__wp-mcp-file-list", "mcp__wordpress__wp-mcp-file-read"
    ]
  }
}
```
