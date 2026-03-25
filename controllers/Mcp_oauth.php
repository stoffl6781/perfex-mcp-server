<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * OAuth 2.1 Controller for MCP Connector
 *
 * Implements OAuth 2.1 with PKCE (RFC 7636), Dynamic Client Registration (RFC 7591),
 * and Authorization Server Metadata (RFC 8414) so that Claude.ai, Claude Desktop,
 * and other MCP clients can connect without manual token setup.
 *
 * Extends CI_Controller (not AdminController) because these are public endpoints.
 */
class Mcp_oauth extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        // Ensure module vendor autoload is loaded (CI3 may load controller before module init)
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
        $this->load->model('mcp_connector/mcp_model');
    }

    /**
     * OAuth 2.0 Authorization Server Metadata (RFC 8414)
     * GET /mcp_connector/mcp_oauth/metadata
     */
    public function metadata(): void
    {
        $baseUrl = rtrim(site_url(), '/');

        $metadata = [
            'issuer'                                => $baseUrl,
            'authorization_endpoint'                => $baseUrl . '/mcp_connector/mcp_oauth/authorize',
            'token_endpoint'                        => $baseUrl . '/mcp_connector/mcp_oauth/token',
            'registration_endpoint'                 => $baseUrl . '/mcp_connector/mcp_oauth/register',
            'response_types_supported'              => ['code'],
            'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'none'],
            'code_challenge_methods_supported'       => ['S256'],
            'scopes_supported'                      => ['mcp:read', 'mcp:write', 'mcp:admin'],
        ];

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        echo json_encode($metadata);
        exit;
    }

    /**
     * Authorization endpoint — shows login form (GET) and processes it (POST)
     * GET|POST /mcp_connector/mcp_oauth/authorize
     */
    public function authorize(): void
    {
        // Read OAuth params from GET (initial load) or POST hidden fields (form submit)
        $clientId            = $this->input->get_post('client_id');
        $redirectUri         = $this->input->get_post('redirect_uri');
        $responseType        = $this->input->get_post('response_type');
        $scope               = $this->input->get_post('scope') ?? '';
        $state               = $this->input->get_post('state') ?? '';
        $codeChallenge       = $this->input->get_post('code_challenge');
        $codeChallengeMethod = $this->input->get_post('code_challenge_method') ?? 'S256';

        // Validate required params
        if (!$clientId || !$redirectUri || $responseType !== 'code') {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                'error'             => 'invalid_request',
                'error_description' => 'Missing required parameters (client_id, redirect_uri, response_type=code)',
            ]);
            exit;
        }

        // Validate client exists
        $client = $this->db->where('client_id', $clientId)
            ->get(db_prefix() . 'mcp_oauth_clients')
            ->row();

        if (!$client) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                'error'             => 'invalid_client',
                'error_description' => 'Unknown client_id',
            ]);
            exit;
        }

        // Validate redirect_uri matches one of the registered URIs or known Claude callback URLs
        $registeredUris = json_decode($client->redirect_uris, true) ?: [];

        // DECISION: Always allow known Claude callback URLs so OAuth works out-of-the-box
        // with Claude.ai, Claude.com and Claude Code without requiring manual URI registration
        $knownClaudeUris = [
            'https://claude.ai/api/mcp/auth_callback',
            'https://claude.com/api/mcp/auth_callback',
            'http://localhost:6274/oauth/callback',
            'http://localhost:6274/oauth/callback/debug',
        ];

        if (!in_array($redirectUri, $registeredUris, true) && !in_array($redirectUri, $knownClaudeUris, true)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                'error'             => 'invalid_request',
                'error_description' => 'redirect_uri not registered for this client',
            ]);
            exit;
        }

        $error = null;

        // Handle form submission (POST from login form)
        if ($this->input->method() === 'post') {
            $email    = $this->input->post('email');
            $password = $this->input->post('password');

            // Authenticate against Perfex staff table
            $staff = $this->db->select('staffid, email, password, active, admin')
                ->where('email', $email)
                ->where('active', 1)
                ->get(db_prefix() . 'staff')
                ->row();

            if ($staff && password_verify($password, $staff->password)) {
                // Generate authorization code (store hash, return plaintext)
                $code     = bin2hex(random_bytes(32));
                $codeHash = hash('sha256', $code);

                $this->db->insert(db_prefix() . 'mcp_oauth_codes', [
                    'code'                  => $codeHash,
                    'client_id'             => $clientId,
                    'staff_id'              => (int) $staff->staffid,
                    'redirect_uri'          => $redirectUri,
                    'code_challenge'        => $codeChallenge,
                    'code_challenge_method' => $codeChallengeMethod,
                    'scope'                 => $scope,
                    'expires_at'            => date('Y-m-d H:i:s', time() + 300), // 5 minutes
                    'created_at'            => date('Y-m-d H:i:s'),
                ]);

                // Redirect back to client with authorization code
                $callbackParams = array_filter([
                    'code'  => $code,
                    'state' => $state,
                ]);
                $callbackUrl = $redirectUri . '?' . http_build_query($callbackParams);
                redirect($callbackUrl);
                return;
            }

            $error = 'Invalid email or password';
        }

        // Show login / consent form
        $this->showLoginForm([
            'client_id'             => $clientId,
            'redirect_uri'          => $redirectUri,
            'response_type'         => $responseType,
            'scope'                 => $scope,
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'error'                 => $error,
            'client_name'           => $client->client_name,
        ]);
    }

    /**
     * Token endpoint — exchanges auth code or refresh token for access tokens
     * POST /mcp_connector/mcp_oauth/token
     */
    public function token(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');

        // Handle CORS preflight
        if ($this->input->method() === 'options') {
            http_response_code(200);
            exit;
        }

        // Accept both form-encoded and JSON body
        $grantType = $this->input->post('grant_type') ?: $this->getJsonParam('grant_type');

        if ($grantType === 'authorization_code') {
            $this->handleAuthorizationCodeGrant();
        } elseif ($grantType === 'refresh_token') {
            $this->handleRefreshTokenGrant();
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'unsupported_grant_type']);
            exit;
        }
    }

    /**
     * Dynamic Client Registration (RFC 7591)
     * POST /mcp_connector/mcp_oauth/register
     */
    public function register(): void
    {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');

        // Handle CORS preflight
        if ($this->input->method() === 'options') {
            http_response_code(200);
            exit;
        }

        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_request', 'error_description' => 'Invalid JSON body']);
            exit;
        }

        $redirectUris  = $body['redirect_uris'] ?? [];
        $clientName    = $body['client_name'] ?? 'Unknown Client';
        $grantTypes    = $body['grant_types'] ?? ['authorization_code'];
        $responseTypes = $body['response_types'] ?? ['code'];

        // Validate redirect URIs are present
        if (empty($redirectUris)) {
            http_response_code(400);
            echo json_encode([
                'error'             => 'invalid_request',
                'error_description' => 'redirect_uris required',
            ]);
            exit;
        }

        // DECISION: Always include known Claude callback URLs so OAuth works out-of-the-box
        $knownClaudeUris = [
            'https://claude.ai/api/mcp/auth_callback',
            'https://claude.com/api/mcp/auth_callback',
            'http://localhost:6274/oauth/callback',
            'http://localhost:6274/oauth/callback/debug',
        ];
        $redirectUris = array_values(array_unique(array_merge($redirectUris, $knownClaudeUris)));

        // Validate each redirect URI uses HTTPS (or localhost for development)
        foreach ($redirectUris as $uri) {
            $parsed      = parse_url($uri);
            $host        = $parsed['host'] ?? '';
            $scheme      = $parsed['scheme'] ?? '';
            $isLocalhost = in_array($host, ['localhost', '127.0.0.1'], true);
            $isHttps     = $scheme === 'https';

            if (!$isLocalhost && !$isHttps) {
                http_response_code(400);
                echo json_encode([
                    'error'             => 'invalid_request',
                    'error_description' => 'redirect_uris must use HTTPS (except localhost)',
                ]);
                exit;
            }
        }

        // Generate client credentials
        $clientId     = 'mcp_client_' . bin2hex(random_bytes(16));
        $clientSecret = bin2hex(random_bytes(32));

        $this->db->insert(db_prefix() . 'mcp_oauth_clients', [
            'client_id'      => $clientId,
            'client_secret'  => password_hash($clientSecret, PASSWORD_DEFAULT),
            'client_name'    => $clientName,
            'redirect_uris'  => json_encode($redirectUris),
            'grant_types'    => implode(' ', $grantTypes),
            'response_types' => implode(' ', $responseTypes),
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        http_response_code(201);
        echo json_encode([
            'client_id'          => $clientId,
            'client_secret'      => $clientSecret,
            'client_name'        => $clientName,
            'redirect_uris'      => $redirectUris,
            'grant_types'        => $grantTypes,
            'response_types'     => $responseTypes,
            'client_id_issued_at' => time(),
        ]);
        exit;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Exchange authorization code for access + refresh tokens.
     * Validates PKCE code_challenge against code_verifier (RFC 7636).
     */
    private function handleAuthorizationCodeGrant(): void
    {
        $code         = $this->input->post('code') ?: $this->getJsonParam('code');
        $clientId     = $this->input->post('client_id') ?: $this->getJsonParam('client_id');
        $redirectUri  = $this->input->post('redirect_uri') ?: $this->getJsonParam('redirect_uri');
        $codeVerifier = $this->input->post('code_verifier') ?: $this->getJsonParam('code_verifier');

        if (!$code || !$clientId || !$redirectUri) {
            http_response_code(400);
            echo json_encode([
                'error'             => 'invalid_request',
                'error_description' => 'Missing required parameters (code, client_id, redirect_uri)',
            ]);
            exit;
        }

        // Lookup authorization code by its hash
        $codeHash = hash('sha256', $code);
        $authCode = $this->db->where('code', $codeHash)
            ->where('client_id', $clientId)
            ->where('redirect_uri', $redirectUri)
            ->where('used', 0)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->get(db_prefix() . 'mcp_oauth_codes')
            ->row();

        if (!$authCode) {
            http_response_code(400);
            echo json_encode([
                'error'             => 'invalid_grant',
                'error_description' => 'Invalid, expired, or already used authorization code',
            ]);
            exit;
        }

        // PKCE verification (RFC 7636)
        if ($authCode->code_challenge) {
            if (!$codeVerifier) {
                http_response_code(400);
                echo json_encode([
                    'error'             => 'invalid_request',
                    'error_description' => 'code_verifier required for PKCE',
                ]);
                exit;
            }

            // S256: BASE64URL(SHA256(code_verifier)) must match stored code_challenge
            $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
            if (!hash_equals($authCode->code_challenge, $expectedChallenge)) {
                http_response_code(400);
                echo json_encode([
                    'error'             => 'invalid_grant',
                    'error_description' => 'PKCE code_verifier does not match code_challenge',
                ]);
                exit;
            }
        }

        // Mark authorization code as used (single-use per spec)
        $this->db->where('id', $authCode->id)
            ->update(db_prefix() . 'mcp_oauth_codes', ['used' => 1]);

        // Generate access and refresh tokens
        $accessToken  = 'mcp_' . bin2hex(random_bytes(32));
        $refreshToken = 'mcp_refresh_' . bin2hex(random_bytes(32));

        // Derive permissions from requested scopes
        $scope  = $authCode->scope ?: 'mcp:read mcp:write';
        $scopes = explode(' ', $scope);
        $access = [];
        if (in_array('mcp:read', $scopes, true) || in_array('mcp:admin', $scopes, true)) {
            $access[] = 'read';
        }
        if (in_array('mcp:write', $scopes, true) || in_array('mcp:admin', $scopes, true)) {
            $access[] = 'write';
        }

        $permissions = [
            'groups' => ['clients', 'invoices', 'estimates', 'mainwp'],
            'access' => $access,
        ];

        // Store as MCP token (reuses existing token infrastructure)
        $this->db->insert(db_prefix() . 'mcp_tokens', [
            'staff_id'    => (int) $authCode->staff_id,
            'token_hash'  => hash('sha256', $accessToken),
            'token_hint'  => substr($accessToken, -4),
            'label'       => 'OAuth: ' . $authCode->client_id,
            'permissions' => json_encode($permissions),
            'is_active'   => 1,
            'expires_at'  => date('Y-m-d H:i:s', time() + 86400), // 24 hours
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        // Store refresh token hash in the label for later lookup
        $tokenId = $this->db->insert_id();
        $this->db->where('id', $tokenId)
            ->update(db_prefix() . 'mcp_tokens', [
                'label' => 'OAuth: ' . $authCode->client_id . ' | refresh:' . hash('sha256', $refreshToken),
            ]);

        echo json_encode([
            'access_token'  => $accessToken,
            'token_type'    => 'Bearer',
            'expires_in'    => 86400,
            'refresh_token' => $refreshToken,
            'scope'         => $scope,
        ]);
        exit;
    }

    /**
     * Exchange a refresh token for a new access + refresh token pair.
     * Implements refresh token rotation (old token is deactivated).
     */
    private function handleRefreshTokenGrant(): void
    {
        $refreshToken = $this->input->post('refresh_token') ?: $this->getJsonParam('refresh_token');
        $clientId     = $this->input->post('client_id') ?: $this->getJsonParam('client_id');

        if (!$refreshToken) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_request', 'error_description' => 'refresh_token required']);
            exit;
        }

        $refreshHash = hash('sha256', $refreshToken);

        // Find the active token that holds this refresh hash
        $existingToken = $this->db->like('label', 'refresh:' . $refreshHash)
            ->where('is_active', 1)
            ->get(db_prefix() . 'mcp_tokens')
            ->row();

        if (!$existingToken) {
            http_response_code(400);
            echo json_encode([
                'error'             => 'invalid_grant',
                'error_description' => 'Invalid or expired refresh token',
            ]);
            exit;
        }

        // Deactivate old token (rotation)
        $this->db->where('id', $existingToken->id)
            ->update(db_prefix() . 'mcp_tokens', ['is_active' => 0]);

        // Issue fresh token pair
        $newAccessToken  = 'mcp_' . bin2hex(random_bytes(32));
        $newRefreshToken = 'mcp_refresh_' . bin2hex(random_bytes(32));

        $this->db->insert(db_prefix() . 'mcp_tokens', [
            'staff_id'    => (int) $existingToken->staff_id,
            'token_hash'  => hash('sha256', $newAccessToken),
            'token_hint'  => substr($newAccessToken, -4),
            'label'       => 'OAuth: ' . ($clientId ?: 'unknown') . ' | refresh:' . hash('sha256', $newRefreshToken),
            'permissions' => $existingToken->permissions,
            'is_active'   => 1,
            'expires_at'  => date('Y-m-d H:i:s', time() + 86400),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        // Preserve scope from original token permissions
        $permissions = json_decode($existingToken->permissions, true) ?: [];
        $accessList  = $permissions['access'] ?? [];
        $scopeParts  = [];
        if (in_array('read', $accessList, true)) {
            $scopeParts[] = 'mcp:read';
        }
        if (in_array('write', $accessList, true)) {
            $scopeParts[] = 'mcp:write';
        }
        $scope = implode(' ', $scopeParts) ?: 'mcp:read mcp:write';

        echo json_encode([
            'access_token'  => $newAccessToken,
            'token_type'    => 'Bearer',
            'expires_in'    => 86400,
            'refresh_token' => $newRefreshToken,
            'scope'         => $scope,
        ]);
        exit;
    }

    /**
     * Render a self-contained HTML login/consent page.
     * No Perfex layout needed since this is a public OAuth endpoint.
     */
    private function showLoginForm(array $params): void
    {
        $errorHtml  = '';
        if (!empty($params['error'])) {
            $errorHtml = '<div role="alert" style="background:#fef2f2;border:1px solid #ef4444;padding:12px;border-radius:6px;margin-bottom:16px;color:#b91c1c;font-size:14px">'
                . htmlspecialchars($params['error'])
                . '</div>';
        }
        $clientName = htmlspecialchars($params['client_name'] ?: 'MCP Client');

        $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authorize - Perfex CRM</title>
    <style>
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh; padding: 16px;
        }
        .card {
            background: #fff; border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.15);
            padding: 32px; max-width: 400px; width: 100%;
        }
        h1 { font-size: 20px; margin-bottom: 8px; color: #111827; }
        .subtitle { color: #6b7280; margin-bottom: 24px; font-size: 14px; line-height: 1.5; }
        label { display: block; font-size: 14px; font-weight: 500; margin-bottom: 4px; color: #374151; }
        input[type=email], input[type=password] {
            width: 100%; padding: 10px 12px;
            border: 1px solid #d1d5db; border-radius: 6px;
            font-size: 14px; margin-bottom: 16px;
            color: #111827; background: #fff;
        }
        input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.15); }
        button[type=submit] {
            width: 100%; padding: 10px;
            background: #2563eb; color: #fff; border: none;
            border-radius: 6px; font-size: 14px; font-weight: 600;
            cursor: pointer; transition: background 0.15s;
        }
        button[type=submit]:hover { background: #1d4ed8; }
        button[type=submit]:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
        .info { font-size: 12px; color: #9ca3af; margin-top: 16px; text-align: center; line-height: 1.5; }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>
<body>
    <main class="card" role="main">
        <h1>Authorize ' . $clientName . '</h1>
        <p class="subtitle">Sign in with your Perfex CRM account to grant this application access to your CRM data.</p>
        ' . $errorHtml . '
        <form method="post" action="">
            <input type="hidden" name="client_id" value="' . htmlspecialchars($params['client_id']) . '">
            <input type="hidden" name="redirect_uri" value="' . htmlspecialchars($params['redirect_uri']) . '">
            <input type="hidden" name="response_type" value="' . htmlspecialchars($params['response_type']) . '">
            <input type="hidden" name="scope" value="' . htmlspecialchars($params['scope']) . '">
            <input type="hidden" name="state" value="' . htmlspecialchars($params['state']) . '">
            <input type="hidden" name="code_challenge" value="' . htmlspecialchars($params['code_challenge'] ?? '') . '">
            <input type="hidden" name="code_challenge_method" value="' . htmlspecialchars($params['code_challenge_method'] ?? 'S256') . '">

            <label for="email">Email</label>
            <input type="email" id="email" name="email" required autofocus autocomplete="email">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">

            <button type="submit">Authorize</button>
        </form>
        <p class="info">This will allow the application to access your CRM data (clients, invoices, estimates).</p>
    </main>
</body>
</html>';
        echo $html;
        exit;
    }

    /**
     * Read a parameter from a JSON request body.
     * Caches the decoded body for repeated access within one request.
     */
    private function getJsonParam(string $key): ?string
    {
        static $body = null;
        if ($body === null) {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
        }
        $value = $body[$key] ?? null;

        return $value !== null ? (string) $value : null;
    }
}
