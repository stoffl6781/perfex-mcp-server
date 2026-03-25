# Perfex CRM MCP Server

A [Model Context Protocol (MCP)](https://modelcontextprotocol.io) server for [Perfex CRM](https://www.perfexcrm.com/). Connect Claude, Cursor, or any MCP-compatible AI client directly to your CRM data.

> **Ask Claude:** "Show me all unpaid invoices" or "Create an estimate for client Acme Corp" — and it queries your Perfex CRM in real-time.

## Quick Start

1. Download `mcp_connector.zip` from [Releases](https://github.com/stoffl6781/perfex-mcp-server/releases/latest)
2. Upload in Perfex CRM: **Setup > Modules > Upload Module**
3. Activate the module
4. Copy `.well-known/` folder to your Perfex webroot (required for OAuth discovery)
5. Connect Claude: add your server URL as a custom connector

## Features

- **12 MCP Tools** for Clients, Invoices, Estimates, and MainWP Sites
- **Read & Write** — search, view details, and create new records
- **OAuth 2.1** — native authentication with Claude.ai, Claude Desktop, and Claude Code (PKCE, DCR, token refresh)
- **Bearer Token Auth** — manual token creation for API access
- **Safety Annotations** — all tools annotated with `readOnlyHint` / `destructiveHint`
- **Audit Log** — every tool call logged with staff context and IP anonymization (GDPR)
- **Streamable HTTP** — works remotely over HTTPS
- **Admin UI** — manage tokens and view audit logs in Perfex
- **No extra license needed** — uses Perfex internal models, not the REST API module

### Available Tools

| Tool | Type | Description |
|------|------|-------------|
| `search_clients` | Read | Search clients by name, company, or email |
| `get_client` | Read | Get client details with contacts and linked sites |
| `create_client` | Write | Create a new client |
| `search_invoices` | Read | Search invoices by client, status, date range |
| `get_invoice` | Read | Get invoice with line items and payments |
| `create_invoice` | Write | Create invoice with items and tax |
| `search_estimates` | Read | Search estimates by client and status |
| `get_estimate` | Read | Get estimate with line items |
| `create_estimate` | Write | Create estimate with items and tax |
| `list_client_sites` | Read | List MainWP sites for a client * |
| `get_site_details` | Read | Get site details (plugins, versions, maintenance) * |
| `create_site` | Write | Link a new site to a client * |

\* MainWP tools require the MainWP Connect module. If not installed, the other 9 tools work normally.

## Requirements

- Perfex CRM 3.0+
- PHP 8.1+
- HTTPS with a valid SSL certificate

## Installation

### Option A: Upload ZIP (Recommended)

1. Download `mcp_connector.zip` from the [latest release](https://github.com/stoffl6781/perfex-mcp-server/releases/latest)
2. In Perfex CRM, go to **Setup > Modules**
3. Click **Upload Module** and select the ZIP file
4. Activate the module

### Option B: Manual / Developer Setup

```bash
cd /path/to/perfex/modules
git clone https://github.com/stoffl6781/perfex-mcp-server.git mcp_connector
cd mcp_connector
composer install --no-dev
```

Activate in **Setup > Modules**.

### OAuth Discovery (Required)

Copy the `.well-known/` folder from the module to your **Perfex webroot**:

```bash
cp -R modules/mcp_connector/.well-known /path/to/perfex/.well-known
```

This serves the OAuth Authorization Server Metadata at `/.well-known/oauth-authorization-server`, which MCP clients need to discover your auth endpoints.

## Authentication

### Option 1: OAuth 2.1 (Claude.ai, Claude Desktop, Cursor)

The module implements full OAuth 2.1 with:
- **Dynamic Client Registration** (RFC 7591) — clients register automatically
- **PKCE** (S256) — secure authorization code flow
- **Token refresh** — automatic token renewal

**Connect via Claude.ai:**
1. Go to **Settings > Connectors > Add Custom Connector**
2. Enter your server URL: `https://your-crm.com/mcp_connector/mcp_server`
3. Click **Add** — Claude handles the OAuth flow automatically
4. Sign in with your Perfex staff email and password when prompted

**Connect via Claude Desktop:**
1. Go to **Settings > Connectors**
2. Add remote connector with URL: `https://your-crm.com/mcp_connector/mcp_server`

### Option 2: Bearer Token (API / Claude Code CLI)

For programmatic access or Claude Code:

1. Go to **Setup > MCP Connector** in Perfex admin
2. Create a token with desired permissions
3. Use the token:

```bash
claude mcp add perfex-crm \
  --transport streamable-http \
  --url https://your-crm.com/mcp_connector/mcp_server \
  --header "Authorization: Bearer mcp_your_token_here"
```

## Example Usage

> "Show me all clients in my CRM"

> "Which invoices are overdue?"

> "Create an invoice for client 42: Web Development, 10 hours at 120 EUR, tax MwSt|20.00"

> "List all MainWP sites for client Acme Corp"

## Security

| Feature | Implementation |
|---------|---------------|
| OAuth 2.1 | PKCE (S256), DCR, token refresh, 24h expiry |
| Token Storage | SHA-256 hash (plaintext shown once) |
| Transport | HTTPS required (HTTP rejected with 421) |
| Rate Limiting | 60 req/min per token (atomic SQL) |
| Permissions | Per-token: tool groups + read/write level |
| Safety Annotations | All tools annotated (readOnlyHint / destructiveHint) |
| Delete Protection | No delete operations by design |
| Audit Log | Staff context + anonymized IP (GDPR) |
| Data Exposure | No IBAN, passwords, or API keys in responses |

## Tax Format

Perfex CRM uses pipe-separated tax format: `"TaxName|TaxRate"`

Examples: `"MwSt|20.00"` (AT), `"VAT|19.00"` (DE), `"USt|7.00"` (reduced)

## Architecture

```
MCP Client                    Perfex CRM Server
┌──────────────┐              ┌──────────────────────┐
│  Claude.ai / │  OAuth 2.1   │  /.well-known/       │
│  Desktop /   │◄───────────►│  oauth-authorization  │
│  Code /      │              │  -server              │
│  Cursor      │  HTTPS       │                       │
│              │◄───────────►│  /mcp_connector/       │
└──────────────┘  Bearer      │  mcp_server           │
                  Token       │       │                │
                              │  ┌────▼─────────────┐ │
                              │  │ McpAuth (PSR-15)  │ │
                              │  └────┬─────────────┘ │
                              │  ┌────▼─────────────┐ │
                              │  │ MCP SDK (12 Tools)│ │
                              │  └────┬─────────────┘ │
                              │  ┌────▼─────────────┐ │
                              │  │ Perfex Models     │ │
                              │  │ (Direct DB)       │ │
                              │  └──────────────────┘ │
                              └──────────────────────┘
```

## Roadmap

### v1.1 (Current)
- Clients, Invoices, Estimates, MainWP Sites (search, get, create)
- OAuth 2.1 + Bearer Token auth
- Safety annotations, audit logging

### Planned
- Update operations for existing records
- Projects, Tasks, Time tracking, Leads
- Invoice PDF as MCP Resource
- MCP Prompts (pre-built workflows)

## Contributing

Contributions welcome! Please open an issue first to discuss changes.

## License

MIT — see [LICENSE](LICENSE)

## Author

**Christoph Purin** — [purin.at](https://purin.at)

Built with [Claude Code](https://claude.ai/claude-code).
