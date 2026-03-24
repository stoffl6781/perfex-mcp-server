<?php
declare(strict_types=1);

namespace Perfexcrm\McpConnector;

use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Psr\Log\AbstractLogger;

class McpToolRegistry
{
    public static function buildServer(): Server
    {
        $modulePath = defined('MCP_CONNECTOR_PATH')
            ? MCP_CONNECTOR_PATH
            : dirname(__DIR__);

        $sessionPath = $modulePath . '/storage/sessions';

        // Log only warnings and errors to file for debugging
        $logger = new class($modulePath . '/storage/mcp.log') extends AbstractLogger {
            public function __construct(private string $logFile) {}
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (!in_array($level, ['warning', 'error', 'critical', 'emergency'], true)) {
                    return;
                }
                $ctx = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : '';
                file_put_contents($this->logFile, date('Y-m-d H:i:s') . " [{$level}] {$message}{$ctx}\n", FILE_APPEND);
            }
        };

        return Server::builder()
            ->setServerInfo('Perfex CRM MCP Server', '1.0.0')
            ->setInstructions(
                'Perfex CRM server. Use tools to search, view, and create clients, '
                . 'invoices, estimates, and MainWP sites. '
                . 'Tax format for items is "TaxName|TaxRate" (e.g. "MwSt|20.00"). '
                . 'All monetary amounts are in the client\'s default currency.'
            )
            ->setLogger($logger)
            ->setSession(new FileSessionStore($sessionPath, ttl: 7200))
            ->setDiscovery($modulePath . '/libraries/tools', ['.'])
            ->build();
    }
}
