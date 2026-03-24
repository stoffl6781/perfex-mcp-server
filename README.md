# Perfex CRM MCP Server

A [Model Context Protocol (MCP)](https://modelcontextprotocol.io) server for [Perfex CRM](https://www.perfexcrm.com/). Connect Claude Desktop, Claude Code, Cursor, or any MCP-compatible AI client directly to your CRM data.

> **Ask Claude:** "Show me all unpaid invoices" or "Create an estimate for client Acme Corp" — and it queries your Perfex CRM in real-time.

## Quick Start

1. Download `mcp_connector.zip` from [Releases](https://github.com/stoffl6781/perfex-mcp-server/releases/latest)
2. Upload in Perfex CRM: **Setup > Modules > Upload Module**
3. Activate the module
4. Create a token: **Setup > MCP Connector > Create Token**
5. Add the server to your Claude Desktop config (see below)
6. Done. Ask Claude about your CRM data.

## Features

- **12 MCP Tools** for Clients, Invoices, Estimates, and MainWP Sites
- **Read & Write** — search, view details, and create new records
- **Secure** — Bearer token auth with SHA-256 hashing, per-tool permissions, rate limiting
- **Audit Log** — every tool call is logged with staff context and IP anonymization (GDPR)
- **Streamable HTTP** — works remotely over HTTPS, no local setup needed
- **Admin UI** — manage tokens and view audit logs directly in Perfex
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

\* MainWP tools require the [MainWP Connect](https://github.com/stoffl6781) module. If not installed, the other 9 tools work normally.

## Requirements

- Perfex CRM 3.0+
- PHP 8.1+
- HTTPS with a valid SSL certificate

## Installation

### Option A: Upload ZIP (Recommended)

1. Download `mcp_connector.zip` from the [latest release](https://github.com/stoffl6781/perfex-mcp-server/releases/latest)
2. In Perfex CRM, go to **Setup > Modules**
3. Click **Upload Module** and select the ZIP file
4. Click **Activate**

That's it — all dependencies are included in the ZIP. No command line needed.

### Option B: Manual / Developer Setup

```bash
# Clone into your Perfex modules directory
cd /path/to/perfex/modules
git clone https://github.com/stoffl6781/perfex-mcp-server.git mcp_connector

# Install PHP dependencies
cd mcp_connector
composer install --no-dev
```

Then activate in **Setup > Modules**.

## Configuration

### 1. Create an API Token

Go to **Setup > MCP Connector > API Tokens**:

1. Enter a label (e.g., "Claude Desktop")
2. Select the staff member (determines audit log identity)
3. Choose permissions:
   - **Tool groups:** Clients, Invoices, Estimates, MainWP
   - **Access level:** Read, Write, or both
4. Optionally set an expiry date
5. Click **Create Token**
6. **Copy the token immediately** — it will not be shown again

### 2. Connect Your AI Client

#### Claude Desktop

Add to your `claude_desktop_config.json` (Settings > Developer > Edit Config):

```json
{
  "mcpServers": {
    "perfex-crm": {
      "type": "streamable-http",
      "url": "https://your-crm-domain.com/mcp_connector/mcp_server",
      "headers": {
        "Authorization": "Bearer mcp_your_token_here"
      }
    }
  }
}
```

#### Claude Code (CLI)

```bash
claude mcp add perfex-crm \
  --transport streamable-http \
  --url https://your-crm-domain.com/mcp_connector/mcp_server \
  --header "Authorization: Bearer mcp_your_token_here"
```

#### Cursor / Other MCP Clients

Use these settings:
- **Type:** Streamable HTTP
- **URL:** `https://your-crm-domain.com/mcp_connector/mcp_server`
- **Header:** `Authorization: Bearer mcp_your_token_here`

### 3. Test It

Restart your AI client and try:

> "Show me all clients in my CRM"

> "Search for unpaid invoices from last month"

> "Create an invoice for client ID 42 with: Web Development, 10 hours at 120 EUR, tax MwSt|20.00"

## Example Usage

**Search for a client:**
> "Find client Acme Corp"

Claude calls `search_clients` with `query: "Acme Corp"` and returns matching clients with IDs, contacts, and status.

**Check overdue invoices:**
> "Which invoices are overdue?"

Claude calls `search_invoices` with `status: "overdue"` and returns a list with amounts, dates, and client names.

**Create an estimate:**
> "Create an estimate for client 15: Website Redesign, 40 hours at 95 EUR plus 20% MwSt"

Claude calls `create_estimate` with the client ID, line items, and tax format — and confirms the estimate number.

## Security

| Feature | Implementation |
|---------|---------------|
| Token Storage | SHA-256 hash (plaintext shown once at creation) |
| Transport | HTTPS required (HTTP requests rejected with 421) |
| Rate Limiting | 60 req/min per token (atomic SQL) |
| Permissions | Per-token: tool groups + read/write level |
| Delete Protection | No delete operations by design |
| CSRF Protection | Browser `Origin` headers rejected |
| Audit Log | Staff context + anonymized IP (GDPR) |
| Data Exposure | No IBAN, passwords, or API keys in responses |

## Admin Panel

### Token Management

Create, view, and revoke API tokens. Each token shows:
- Label and linked staff member
- Last 4 characters for identification
- Last used timestamp
- Active/inactive status
- Expiry date

### Audit Log

Every MCP tool call is logged and viewable with filters:
- Tool name
- Success / Error status
- Staff member
- Date range

## Tax Format

Perfex CRM uses a pipe-separated tax format: `"TaxName|TaxRate"`.

Examples:
- `"MwSt|20.00"` — Austrian VAT 20%
- `"VAT|19.00"` — German VAT 19%
- `"USt|7.00"` — Reduced rate 7%

When creating invoices or estimates via MCP, pass taxes like this:

```json
{
  "items": [
    {
      "description": "Web Development",
      "qty": 10,
      "rate": 120,
      "taxname": "MwSt|20.00"
    }
  ]
}
```

## Architecture

```
MCP Client (Claude)           Perfex CRM Server
┌──────────────┐              ┌──────────────────────┐
│              │    HTTPS     │  Mcp_server.php       │
│  Claude      │◄───────────►│  (Streamable HTTP)    │
│  Desktop /   │  Bearer      │         │              │
│  Code /      │  Token       │  ┌──────▼───────────┐ │
│  Cursor      │              │  │ McpAuth (PSR-15)  │ │
└──────────────┘              │  │ Token + Rate Limit│ │
                              │  └──────┬───────────┘ │
                              │  ┌──────▼───────────┐ │
                              │  │ MCP SDK           │ │
                              │  │ 12 Tools          │ │
                              │  └──────┬───────────┘ │
                              │  ┌──────▼───────────┐ │
                              │  │ Perfex Models     │ │
                              │  │ (Direct DB access)│ │
                              │  └──────────────────┘ │
                              └──────────────────────┘
```

The module calls Perfex CRM's internal models directly. No REST API module needed, no additional license required.

## Roadmap

### Phase 1 — v1.0 (Current)
- Clients, Invoices, Estimates (search, get, create)
- MainWP Sites (list, details, create)
- Bearer token auth with permissions and audit log

### Phase 2 — Planned
- Update operations for existing records
- Projects & Tasks
- Time tracking
- Leads
- MainWP maintenance (trigger updates, scans)
- Invoice PDF as MCP Resource

### Phase 3 — Future
- Tickets / Support
- Expenses
- Reports & Dashboard data
- MCP Prompts (pre-built workflows)
- Bulk operations

## Tech Stack

- **PHP 8.1+** with strict types
- **[mcp/sdk](https://github.com/modelcontextprotocol/php-sdk)** — Official MCP PHP SDK (by PHP Foundation + Symfony)
- **Nyholm PSR-7** — HTTP message bridge for CodeIgniter 3
- **Laminas HTTP Handler Runner** — PSR-7 response emitter

## Contributing

Contributions are welcome! Please open an issue first to discuss what you'd like to change.

1. Fork the repo
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'feat: add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

MIT License — see [LICENSE](LICENSE) for details.

## Author

**Christoph Purin** — [purin.at](https://purin.at)

---

Built with [Claude Code](https://claude.ai/claude-code).
