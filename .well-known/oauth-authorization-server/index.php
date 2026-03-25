<?php
// OAuth 2.0 Authorization Server Metadata (RFC 8414)
// Serves /.well-known/oauth-authorization-server for MCP clients

// Bootstrap minimal Perfex to get site_url()
$dir = dirname(__DIR__, 2);
define('BASEPATH', $dir . '/system/');
define('APPPATH', $dir . '/application/');
require_once APPPATH . 'config/app-config.php';

$baseUrl = rtrim(APP_BASE_URL, '/');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=3600');

echo json_encode([
    'issuer' => $baseUrl,
    'authorization_endpoint' => $baseUrl . '/mcp_connector/mcp_oauth/authorize',
    'token_endpoint' => $baseUrl . '/mcp_connector/mcp_oauth/token',
    'registration_endpoint' => $baseUrl . '/mcp_connector/mcp_oauth/register',
    'response_types_supported' => ['code'],
    'grant_types_supported' => ['authorization_code', 'refresh_token'],
    'token_endpoint_auth_methods_supported' => ['client_secret_post', 'none'],
    'code_challenge_methods_supported' => ['S256'],
    'scopes_supported' => ['mcp:read', 'mcp:write', 'mcp:admin'],
]);
