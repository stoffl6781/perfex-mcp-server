<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Version_110 extends App_module_migration
{
    public function up(): void
    {
        $prefix = db_prefix();

        // OAuth clients table (Dynamic Client Registration)
        if (!$this->ci->db->table_exists($prefix . 'mcp_oauth_clients')) {
            $this->ci->db->query("
                CREATE TABLE {$prefix}mcp_oauth_clients (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    client_id VARCHAR(64) NOT NULL,
                    client_secret VARCHAR(255) NULL,
                    client_name VARCHAR(200) NOT NULL DEFAULT '',
                    redirect_uris TEXT NOT NULL,
                    grant_types VARCHAR(200) NOT NULL DEFAULT 'authorization_code',
                    response_types VARCHAR(100) NOT NULL DEFAULT 'code',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY idx_client_id (client_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }

        // OAuth authorization codes table
        if (!$this->ci->db->table_exists($prefix . 'mcp_oauth_codes')) {
            $this->ci->db->query("
                CREATE TABLE {$prefix}mcp_oauth_codes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    code VARCHAR(64) NOT NULL,
                    client_id VARCHAR(64) NOT NULL,
                    staff_id INT NOT NULL,
                    redirect_uri VARCHAR(500) NOT NULL,
                    code_challenge VARCHAR(128) NULL,
                    code_challenge_method VARCHAR(10) NULL DEFAULT 'S256',
                    scope VARCHAR(500) NULL,
                    expires_at DATETIME NOT NULL,
                    used TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY idx_code (code)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }
    }
}
