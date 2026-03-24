# Perfex CRM MCP Server

A [Model Context Protocol (MCP)](https://modelcontextprotocol.io) server for [Perfex CRM](https://www.perfexcrm.com/). Connect Claude Desktop, Claude Code, Cursor, or any MCP-compatible AI client directly to your CRM data.

> **Ask Claude:** "Show me all unpaid invoices" or "Create an estimate for client Acme Corp" — and it queries your Perfex CRM in real-time.

## Features

- **12 MCP Tools** for Clients, Invoices, Estimates, and MainWP Sites
- **Read & Write** — search, view details, and create new records
- **Secure** — Bearer token auth with SHA-256 hashing, per-tool permissions, rate limiting
- **Audit Log** — every tool call is logged with staff context and IP anonymization (GDPR)
- **Streamable HTTP** — works remotely over HTTPS, no local setup needed
- **Admin UI** — manage tokens and view audit logs in Perfex admin panel

### Available Tools

| Tool | Type | Description |
|------|------|-------------|
| `search_clients` | Read | Search clients by name, company, or email |
| `get_client` | Read | Get client details with contacts and MainWP sites |
| `create_client` | Write | Create a new client |
| `search_invoices` | Read | Search invoices by client, status, date range |
| `get_invoice` | Read | Get invoice with line items and payments |
| `create_invoice` | Write | Create invoice with items and tax |
| `search_estimates` | Read | Search estimates by client and status |
| `get_estimate` | Read | Get estimate with line items |
| `create_estimate` | Write | Create estimate with items and tax |
| `list_client_sites` | Read | List MainWP sites for a client |
| `get_site_details` | Read | Get site details (plugins, versions, maintenance) |
| `create_site` | Write | Link a new site to a client |

## Requirements

- Perfex CRM 3.0+
- PHP 8.1+
- HTTPS (required for secure token transmission)
- Composer

## Installation

### 1. Download & Install

```bash
# Copy the module to your Perfex modules directory
cp -R perfex-mcp-server /path/to/perfex/modules/mcp_connector

# Install PHP dependencies
cd /path/to/perfex/modules/mcp_connector
composer install --no-dev
```

### 2. Activate the Module

Go to **Setup > Modules** in your Perfex admin panel and activate **MCP Connector**.

### 3. Create an API Token

Go to **Setup > MCP Connector > API Tokens**:
1. Enter a label (e.g., "Claude Desktop")
2. Select the staff member (for audit logging)
3. Choose permissions (which tools and read/write access)
4. Click **Create Token**
5. **Copy the token immediately** — it's only shown once

### 4. Configure Your MCP Client

#### Claude Desktop

Add to your `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "perfex-crm": {
      "type": "streamable-http",
      "url": "https://your-crm.com/mcp_connector/mcp_server",
      "headers": {
        "Authorization": "Bearer mcp_your_token_here"
      }
    }
  }
}
```

#### Claude Code

Add to your MCP settings:

```json
{
  "mcpServers": {
    "perfex-crm": {
      "type": "streamable-http",
      "url": "https://your-crm.com/mcp_connector/mcp_server",
      "headers": {
        "Authorization": "Bearer mcp_your_token_here"
      }
    }
  }
}
```

### 5. Test It

Restart your MCP client and try:

> "Show me all clients in my CRM"

> "Search for unpaid invoices from last month"

> "Create an invoice for client ID 42 with: Web Development, 10 hours at 120 EUR, tax MwSt|20.00"

## Security

- **Token Hashing:** Tokens are stored as SHA-256 hashes. The plaintext is only shown once at creation.
- **HTTPS Required:** The endpoint rejects non-HTTPS requests.
- **Rate Limiting:** 60 requests per minute per token (atomic SQL, no race conditions).
- **Granular Permissions:** Each token can be restricted to specific tool groups (clients, invoices, estimates, mainwp) and access levels (read, write).
- **No Delete Operations:** By design, no CRM data can be deleted through MCP.
- **CSRF Protection:** Browser `Origin` headers are rejected.
- **IP Anonymization:** Audit logs mask the last octet (IPv4) or use /48 prefix (IPv6) for GDPR compliance.
- **No Sensitive Data in Responses:** IBAN, passwords, and API keys are never exposed.

## Architecture

```
MCP Client (Claude)          Perfex CRM Server
┌──────────────┐             ┌─────────────────────┐
│              │   HTTPS     │  Mcp_server.php      │
│  Claude      │◄──────────►│  (Streamable HTTP)   │
│  Desktop     │  Bearer     │         │             │
│              │  Token      │  ┌──────▼──────────┐ │
└──────────────┘             │  │ McpAuth          │ │
                             │  │ (PSR-15)         │ │
                             │  └──────┬──────────┘ │
                             │  ┌──────▼──────────┐ │
                             │  │ MCP SDK          │ │
                             │  │ Tool Registry    │ │
                             │  └──────┬──────────┘ │
                             │  ┌──────▼──────────┐ │
                             │  │ Perfex Models    │ │
                             │  │ (Direct DB)      │ │
                             │  └─────────────────┘ │
                             └─────────────────────┘
```

The module uses Perfex CRM's internal models directly — no REST API module required, no additional license needed.

## Admin Panel

### Token Management

Create, view, and revoke API tokens. Each token is tied to a staff member for audit purposes.

### Audit Log

View all MCP tool calls with filters for tool name, status, staff member, and date range. Useful for monitoring AI usage and debugging.

## Tax Format

Perfex CRM uses a pipe-separated tax format: `"TaxName|TaxRate"` (e.g., `"VAT|20.00"` or `"MwSt|20.00"`).

When creating invoices or estimates, pass taxes in this format:

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

## Roadmap

### Phase 1 (Current)
- Clients, Invoices, Estimates, MainWP Sites
- Read + Write (no update/delete)
- Token auth with audit logging

### Phase 2 (Planned)
- Update operations for existing records
- Projects & Tasks
- Time tracking
- Leads
- MainWP maintenance (trigger updates, scans)
- Invoice PDF as MCP Resource

### Phase 3 (Future)
- Tickets / Support
- Expenses
- Reports & Dashboard data
- MCP Prompts (pre-built workflows)
- Bulk operations

## Tech Stack

- **PHP 8.1+** with `declare(strict_types=1)`
- **[mcp/sdk](https://github.com/modelcontextprotocol/php-sdk)** — Official MCP PHP SDK
- **Nyholm PSR-7** — HTTP message bridge for CodeIgniter 3
- **Laminas HTTP Handler Runner** — PSR-7 response emitter
- **CodeIgniter 3** — Perfex CRM's underlying framework

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

Built with Claude Code.
