<?php
declare(strict_types=1);

namespace Perfexcrm\McpConnector;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class McpAuth implements MiddlewareInterface
{
    private \Mcp_model $model;
    private ?array $authenticatedToken = null;
    private static ?array $currentToken = null;

    public static function getCurrentToken(): ?array
    {
        return self::$currentToken;
    }

    public function __construct()
    {
        $CI = &get_instance();
        $CI->load->model('mcp_connector/mcp_model');
        $this->model = $CI->mcp_model;
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $factory = new Psr17Factory();

        // Allow OPTIONS for CORS preflight
        if ($request->getMethod() === 'OPTIONS') {
            return $handler->handle($request);
        }

        // Validate Content-Type for POST requests
        if ($request->getMethod() === 'POST') {
            $contentType = $request->getHeaderLine('Content-Type');
            if (!str_contains($contentType, 'application/json')) {
                return $factory->createResponse(415)
                    ->withHeader('Content-Type', 'application/json');
            }
        }

        // DECISION: Origin header check removed — Claude.ai and other browser-based
        // MCP clients send Origin headers as part of the OAuth 2.1 flow. CSRF
        // protection is handled by PKCE code_challenge verification instead.

        // Build resource_metadata URL for WWW-Authenticate header (RFC 9728)
        $baseUrl = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : site_url(), '/');
        $resourceMetadataUrl = $baseUrl . '/.well-known/oauth-protected-resource';
        $wwwAuth = 'Bearer resource_metadata="' . $resourceMetadataUrl . '"';

        // Extract Bearer token
        $authorization = $request->getHeaderLine('Authorization');
        if (!str_starts_with($authorization, 'Bearer ')) {
            return $factory->createResponse(401)
                ->withHeader('WWW-Authenticate', $wwwAuth)
                ->withHeader('Content-Type', 'application/json');
        }

        $plainToken = substr($authorization, 7);

        // Validate token
        $token = $this->model->validate_token($plainToken);
        if ($token === null) {
            return $factory->createResponse(401)
                ->withHeader('WWW-Authenticate', $wwwAuth)
                ->withHeader('Content-Type', 'application/json');
        }

        // Check rate limit
        if (!$this->model->check_rate_limit((int) $token['id'])) {
            return $factory->createResponse(429)
                ->withHeader('Retry-After', '60')
                ->withHeader('Content-Type', 'application/json');
        }

        $this->authenticatedToken = $token;
        self::$currentToken = $token;

        return $handler->handle($request);
    }

    public function getAuthenticatedToken(): ?array
    {
        return $this->authenticatedToken;
    }

    /**
     * Check permissions for a tool call and throw if denied.
     * Call this at the start of every tool method.
     */
    public static function authorizeAndLog(string $toolName, array $inputSummary): void
    {
        $token = self::getCurrentToken();
        if (!$token) {
            return; // No auth context (shouldn't happen with middleware)
        }

        $CI = &get_instance();
        $CI->load->model('mcp_connector/mcp_model');

        if (!$CI->mcp_model->is_tool_allowed($token, $toolName)) {
            $CI->mcp_model->log_action([
                'token_id'  => (int) $token['id'],
                'staff_id'  => (int) $token['staff_id'],
                'tool_name' => $toolName,
                'input'     => $inputSummary,
                'status'    => 'error',
                'error'     => 'Permission denied',
                'ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            throw new \Mcp\Exception\ToolCallException("Permission denied: tool '{$toolName}' is not allowed for this token.");
        }
    }

    /**
     * Log the result of a tool call (success or error).
     * Call this after tool execution completes.
     */
    public static function logToolResult(string $toolName, array $input, string $status = 'success', ?string $error = null): void
    {
        $token = self::getCurrentToken();
        if (!$token) {
            return;
        }

        $CI = &get_instance();
        $CI->load->model('mcp_connector/mcp_model');

        $CI->mcp_model->log_action([
            'token_id'  => (int) $token['id'],
            'staff_id'  => (int) $token['staff_id'],
            'tool_name' => $toolName,
            'input'     => $input,
            'status'    => $status,
            'error'     => $error,
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    }
}
