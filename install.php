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
