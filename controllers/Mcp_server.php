<?php
defined('BASEPATH') or exit('No direct script access allowed');

use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Perfexcrm\McpConnector\McpAuth;
use Perfexcrm\McpConnector\McpToolRegistry;

class Mcp_server extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        // Ensure module vendor autoload is loaded (CI3 may load controller before module init)
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
    }

    public function index(): void
    {
        // Enforce HTTPS to protect bearer tokens in transit
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (function_exists('is_https') && is_https());
        if (!$isHttps) {
            http_response_code(421);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'HTTPS required']);
            exit;
        }

        try {
            // Explicitly load tool classes so MCP SDK discovery can reflect them
            $toolsDir = dirname(__DIR__) . '/libraries/tools/';
            foreach (glob($toolsDir . '*.php') as $toolFile) {
                require_once $toolFile;
            }

            // Build PSR-7 request from PHP globals
            $psr17 = new Psr17Factory();
            $creator = new \Nyholm\Psr7Server\ServerRequestCreator(
                $psr17, $psr17, $psr17, $psr17
            );
            $request = $creator->fromGlobals();

            // Auth middleware
            $auth = new McpAuth();

            // Build MCP server
            $server = McpToolRegistry::buildServer();

            // Create transport with auth middleware
            $transport = new StreamableHttpTransport(
                request: $request,
                responseFactory: $psr17,
                streamFactory: $psr17,
                middleware: [$auth],
            );

            // Run MCP server
            $response = $server->run($transport);

            // Emit PSR-7 response directly, bypassing CI output
            (new SapiEmitter())->emit($response);
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'jsonrpc' => '2.0',
                'error'   => ['code' => -32603, 'message' => 'Internal server error'],
            ]);
        }
        exit;
    }
}
