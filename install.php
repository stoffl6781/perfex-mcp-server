<?php
defined('BASEPATH') or exit('No direct script access allowed');

$CI = &get_instance();

if (!$CI->db->table_exists(db_prefix() . 'mcp_tokens')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . 'mcp_tokens` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `staff_id` int(11) NOT NULL,
        `token_hash` varchar(64) NOT NULL,
        `token_hint` varchar(8) NOT NULL,
        `label` varchar(100) NOT NULL,
        `permissions` text NOT NULL,
        `is_active` tinyint(1) NOT NULL DEFAULT 1,
        `expires_at` datetime NULL DEFAULT NULL,
        `rate_limit_count` int(11) NOT NULL DEFAULT 0,
        `rate_limit_minute` varchar(16) NOT NULL DEFAULT \'\',
        `last_used_at` datetime NULL DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `token_hash` (`token_hash`),
        KEY `staff_id` (`staff_id`),
        KEY `is_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=' . $CI->db->char_set . ';');
}

if (!$CI->db->table_exists(db_prefix() . 'mcp_audit_log')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . 'mcp_audit_log` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `token_id` int(11) NOT NULL,
        `staff_id` int(11) NOT NULL,
        `tool_name` varchar(100) NOT NULL,
        `input_summary` text NULL,
        `result_status` enum(\'success\',\'error\') NOT NULL DEFAULT \'success\',
        `error_message` varchar(500) NULL DEFAULT NULL,
        `ip_address` varchar(39) NOT NULL DEFAULT \'\',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `staff_id` (`staff_id`),
        KEY `tool_name` (`tool_name`),
        KEY `created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=' . $CI->db->char_set . ';');
}

// OAuth 2.1 — Dynamic Client Registration (RFC 7591)
if (!$CI->db->table_exists(db_prefix() . 'mcp_oauth_clients')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . 'mcp_oauth_clients` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `client_id` varchar(64) NOT NULL,
        `client_secret` varchar(255) NULL,
        `client_name` varchar(200) NOT NULL DEFAULT \'\',
        `redirect_uris` text NOT NULL,
        `grant_types` varchar(200) NOT NULL DEFAULT \'authorization_code\',
        `response_types` varchar(100) NOT NULL DEFAULT \'code\',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_client_id` (`client_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=' . $CI->db->char_set . ';');
}

// OAuth 2.1 — Authorization codes with PKCE (RFC 7636)
if (!$CI->db->table_exists(db_prefix() . 'mcp_oauth_codes')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . 'mcp_oauth_codes` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `code` varchar(64) NOT NULL,
        `client_id` varchar(64) NOT NULL,
        `staff_id` int(11) NOT NULL,
        `redirect_uri` varchar(500) NOT NULL,
        `code_challenge` varchar(128) NULL,
        `code_challenge_method` varchar(10) NULL DEFAULT \'S256\',
        `scope` varchar(500) NULL,
        `expires_at` datetime NOT NULL,
        `used` tinyint(1) NOT NULL DEFAULT 0,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_code` (`code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=' . $CI->db->char_set . ';');
}
