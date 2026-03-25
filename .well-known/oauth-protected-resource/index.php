<?php
// OAuth 2.0 Protected Resource Metadata (RFC 9728)
// MCP clients discover the authorization server from this endpoint

$dir = dirname(__DIR__, 2);
define('BASEPATH', $dir . '/system/');
define('APPPATH', $dir . '/application/');
require_once APPPATH . 'config/app-config.php';

$baseUrl = rtrim(APP_BASE_URL, '/');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=3600');

echo json_encode([
    'resource' => $baseUrl . '/mcp_connector/mcp_server',
    'authorization_servers' => [$baseUrl],
    'scopes_supported' => ['mcp:read', 'mcp:write', 'mcp:admin'],
    'bearer_methods_supported' => ['header'],
]);
