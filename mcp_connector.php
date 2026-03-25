<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: MCP Connector
Description: Model Context Protocol Server — exposes CRM data to Claude AI via Streamable HTTP
Author: Christoph Purin
Version: 1.1.0
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

// Handle /.well-known/oauth-authorization-server before CI routing kicks in
hooks()->add_action('app_init', 'mcp_connector_handle_wellknown');
function mcp_connector_handle_wellknown()
{
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($requestUri, PHP_URL_PATH);

    if ($path === '/.well-known/oauth-authorization-server') {
        $baseUrl = rtrim(site_url(), '/');
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        echo json_encode([
            'issuer'                                => $baseUrl,
            'authorization_endpoint'                => $baseUrl . '/mcp_connector/mcp_oauth/authorize',
            'token_endpoint'                        => $baseUrl . '/mcp_connector/mcp_oauth/token',
            'registration_endpoint'                 => $baseUrl . '/mcp_connector/mcp_oauth/register',
            'response_types_supported'              => ['code'],
            'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'none'],
            'code_challenge_methods_supported'       => ['S256'],
            'scopes_supported'                      => ['mcp:read', 'mcp:write', 'mcp:admin'],
        ]);
        exit;
    }
}

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
