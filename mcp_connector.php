<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: MCP Connector
Description: Model Context Protocol Server — exposes CRM data to Claude AI via Streamable HTTP
Author: Christoph Purin
Version: 1.0.0
Requires at least: 3.0.*
*/

define('MCP_CONNECTOR_MODULE', 'mcp_connector');
define('MCP_CONNECTOR_PATH', __DIR__);

// Load module composer autoload
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Activation hook
register_activation_hook(MCP_CONNECTOR_MODULE, 'mcp_connector_activation_hook');
function mcp_connector_activation_hook()
{
    require_once __DIR__ . '/install.php';
}

// Register language files
register_language_files(MCP_CONNECTOR_MODULE, [MCP_CONNECTOR_MODULE]);

// Admin hooks
hooks()->add_action('admin_init', 'mcp_connector_permissions');
hooks()->add_action('admin_init', 'mcp_connector_init_menu_items');

function mcp_connector_permissions()
{
    $capabilities = [];
    $capabilities['capabilities'] = [
        'view'   => _l('permission_view') . '(' . _l('permission_global') . ')',
        'create' => _l('permission_create'),
        'edit'   => _l('permission_edit'),
        'delete' => _l('permission_delete'),
    ];
    register_staff_capabilities(MCP_CONNECTOR_MODULE, $capabilities, _l('mcp_connector'));
}

function mcp_connector_init_menu_items()
{
    $CI = &get_instance();

    if (is_admin()) {
        $CI->app_menu->add_setup_menu_item('mcp-connector', [
            'name'     => _l('mcp_connector'),
            'position' => 70,
            'icon'     => 'fa-solid fa-plug',
        ]);

        $CI->app_menu->add_setup_children_item('mcp-connector', [
            'slug'     => 'mcp-tokens',
            'name'     => _l('mcp_tokens'),
            'href'     => admin_url('mcp_connector/settings'),
            'position' => 5,
        ]);

        $CI->app_menu->add_setup_children_item('mcp-connector', [
            'slug'     => 'mcp-audit',
            'name'     => _l('mcp_audit_log'),
            'href'     => admin_url('mcp_connector/audit_log'),
            'position' => 10,
        ]);
    }
}
