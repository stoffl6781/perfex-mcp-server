<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Mcp_model extends App_Model
{
    private string $tokensTable;
    private string $auditTable;

    public function __construct()
    {
        parent::__construct();
        $this->tokensTable = db_prefix() . 'mcp_tokens';
        $this->auditTable  = db_prefix() . 'mcp_audit_log';
    }

    // --- Token Management ---

    /**
     * Create a new MCP token.
     * Returns the plaintext token (only shown once).
     */
    public function create_token(array $data): ?string
    {
        $plainToken = 'mcp_' . bin2hex(random_bytes(32));
        $tokenHash  = hash('sha256', $plainToken);
        $tokenHint  = substr($plainToken, -4);

        $insert = [
            'staff_id'    => (int) $data['staff_id'],
            'token_hash'  => $tokenHash,
            'token_hint'  => $tokenHint,
            'label'       => trim($data['label']),
            'permissions' => json_encode($data['permissions'] ?? []),
            'is_active'   => 1,
            'expires_at'  => $data['expires_at'] ?? null,
            'created_at'  => date('Y-m-d H:i:s'),
        ];

        $this->db->insert($this->tokensTable, $insert);
        return $this->db->insert_id() ? $plainToken : null;
    }

    /**
     * Validate a token and return its data with staff info.
     * Returns null if invalid, expired, or inactive.
     */
    public function validate_token(string $plainToken): ?array
    {
        $tokenHash = hash('sha256', $plainToken);

        $token = $this->db->select('t.*, s.firstname, s.lastname, s.email, s.active as staff_active')
            ->from($this->tokensTable . ' AS t')
            ->join(db_prefix() . 'staff AS s', 's.staffid = t.staff_id', 'left')
            ->where('t.token_hash', $tokenHash)
            ->where('t.is_active', 1)
            ->get()
            ->row_array();

        if (!$token) {
            return null;
        }

        if ($token['expires_at'] !== null && strtotime($token['expires_at']) < time()) {
            return null;
        }

        if ((int) $token['staff_active'] !== 1) {
            return null;
        }

        return $token;
    }

    /**
     * Check rate limit: max 60 requests per minute per token.
     * Uses atomic SQL to avoid race conditions.
     */
    public function check_rate_limit(int $tokenId): bool
    {
        $currentMinute = date('Y-m-d H:i');

        // Atomic update: reset if new minute, increment if same minute and under limit
        $this->db->query(
            "UPDATE " . $this->tokensTable . "
             SET rate_limit_count = IF(rate_limit_minute = ?, rate_limit_count + 1, 1),
                 rate_limit_minute = ?,
                 last_used_at = NOW()
             WHERE id = ? AND (rate_limit_minute != ? OR rate_limit_count < 60)",
            [$currentMinute, $currentMinute, $tokenId, $currentMinute]
        );

        return $this->db->affected_rows() > 0;
    }

    /**
     * Get all tokens for admin listing.
     */
    public function get_tokens(): array
    {
        return $this->db->select('t.*, s.firstname, s.lastname')
            ->from($this->tokensTable . ' AS t')
            ->join(db_prefix() . 'staff AS s', 's.staffid = t.staff_id', 'left')
            ->order_by('t.created_at', 'DESC')
            ->get()
            ->result_array();
    }

    /**
     * Deactivate a token.
     */
    public function revoke_token(int $tokenId): bool
    {
        $this->db->where('id', $tokenId)->update($this->tokensTable, ['is_active' => 0]);
        return $this->db->affected_rows() > 0;
    }

    /**
     * Check if a tool is allowed by the token's permissions.
     */
    public function is_tool_allowed(array $token, string $toolName): bool
    {
        $permissions = json_decode($token['permissions'], true) ?: [];

        $toolGroups = [
            'search_clients'    => ['clients', 'read'],
            'get_client'        => ['clients', 'read'],
            'create_client'     => ['clients', 'write'],
            'update_client'     => ['clients', 'write'],
            'search_invoices'   => ['invoices', 'read'],
            'get_invoice'       => ['invoices', 'read'],
            'create_invoice'    => ['invoices', 'write'],
            'update_invoice'    => ['invoices', 'write'],
            'mark_invoice_paid'  => ['invoices', 'write'],
            'list_payment_modes' => ['invoices', 'read'],
            'search_estimates'  => ['estimates', 'read'],
            'get_estimate'      => ['estimates', 'read'],
            'create_estimate'   => ['estimates', 'write'],
            'update_estimate'   => ['estimates', 'write'],
            'list_client_sites' => ['mainwp', 'read'],
            'get_site_details'  => ['mainwp', 'read'],
            'create_site'       => ['mainwp', 'write'],
            'search_projects'    => ['projects', 'read'],
            'get_project'        => ['projects', 'read'],
            'create_project'     => ['projects', 'write'],
            'search_tasks'       => ['projects', 'read'],
            'create_task'        => ['projects', 'write'],
            'log_time'           => ['projects', 'write'],
            'search_leads'       => ['leads', 'read'],
            'get_lead'           => ['leads', 'read'],
            'create_lead'        => ['leads', 'write'],
            'list_lead_statuses' => ['leads', 'read'],
        ];

        if (!isset($toolGroups[$toolName])) {
            return false;
        }

        [$group, $access] = $toolGroups[$toolName];

        return in_array($group, $permissions['groups'] ?? [], true)
            && in_array($access, $permissions['access'] ?? [], true);
    }

    // --- Audit Log ---

    /**
     * Write an audit log entry.
     */
    public function log_action(array $data): void
    {
        $inputSummary = isset($data['input']) ? $this->sanitize_input($data['input']) : '';

        $this->db->insert($this->auditTable, [
            'token_id'      => (int) $data['token_id'],
            'staff_id'      => (int) $data['staff_id'],
            'tool_name'     => $data['tool_name'],
            'input_summary' => $inputSummary,
            'result_status' => $data['status'] ?? 'success',
            'error_message' => $data['error'] ?? null,
            'ip_address'    => $this->anonymize_ip($data['ip'] ?? ''),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Get audit log entries for admin view.
     */
    public function get_audit_log(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $this->db->select('a.*, s.firstname, s.lastname, t.label as token_label')
            ->from($this->auditTable . ' AS a')
            ->join(db_prefix() . 'staff AS s', 's.staffid = a.staff_id', 'left')
            ->join($this->tokensTable . ' AS t', 't.id = a.token_id', 'left');

        if (!empty($filters['staff_id'])) {
            $this->db->where('a.staff_id', (int) $filters['staff_id']);
        }
        if (!empty($filters['tool_name'])) {
            $this->db->where('a.tool_name', $filters['tool_name']);
        }
        if (!empty($filters['status'])) {
            $this->db->where('a.result_status', $filters['status']);
        }
        if (!empty($filters['from'])) {
            $this->db->where('a.created_at >=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $this->db->where('a.created_at <=', $filters['to']);
        }

        return $this->db->order_by('a.created_at', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->result_array();
    }

    /**
     * Sanitize input for audit log. Max 1000 chars, strip sensitive fields.
     */
    private function sanitize_input(array $input): string
    {
        $sensitive = ['password', 'iban', 'bic', 'api_key', 'token', 'secret', 'admin_password'];
        foreach ($sensitive as $key) {
            unset($input[$key]);
        }

        $json = json_encode($input, JSON_UNESCAPED_UNICODE);
        return mb_substr($json, 0, 1000);
    }

    /**
     * Anonymize IP address for DSGVO compliance.
     */
    private function anonymize_ip(string $ip): string
    {
        if (empty($ip)) {
            return '';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.x', $ip);
        }

        $parts = explode(':', $ip);
        return implode(':', array_slice($parts, 0, 3)) . '::x';
    }
}
