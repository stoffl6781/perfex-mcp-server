<?php
declare(strict_types=1);

namespace Perfexcrm\McpConnector\Tools;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Perfexcrm\McpConnector\McpAuth;

class MainwpTools
{
    private \CI_Controller $ci;

    public function __construct()
    {
        $this->ci = &get_instance();

        if (!module_exists('mainwp_connect')) {
            throw new ToolCallException('MainWP Connect module is not installed or active.');
        }

        $this->ci->load->model('mainwp_connect/mainwp_model');
    }

    /**
     * List all MainWP sites linked to a specific client.
     *
     * @param int $clientId The client ID
     */
    #[McpTool(name: 'list_client_sites')]
    public function listClientSites(
        #[Schema(minimum: 1)]
        int $clientId,
    ): array {
        $inputSummary = ['client_id' => $clientId];
        McpAuth::authorizeAndLog('list_client_sites', $inputSummary);

        try {
            $sites = $this->ci->mainwp_model->get_by_client($clientId);

            if (!$sites) {
                $result = ['sites' => [], 'message' => "No MainWP sites found for client {$clientId}."];
                McpAuth::logToolResult('list_client_sites', $inputSummary);
                return $result;
            }

            $result = [
                'sites' => array_map(fn($s) => [
                    'id'          => (int) $s['id'],
                    'name'        => $s['site_name'],
                    'url'         => $s['site_url'],
                    'status'      => $s['status'] ?? 'unknown',
                    'php_version' => $s['php_version'] ?? '',
                    'type'        => $s['type'] ?? 'api',
                    'last_synced' => $s['last_synced'] ?? null,
                ], $sites),
            ];

            McpAuth::logToolResult('list_client_sites', $inputSummary);

            return $result;
        } catch (ToolCallException $e) {
            McpAuth::logToolResult('list_client_sites', $inputSummary, 'error', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get detailed information about a specific MainWP site including plugins, theme, and maintenance schedule.
     *
     * @param int $siteId The MainWP site ID (from Perfex, not MainWP dashboard)
     */
    #[McpTool(name: 'get_site_details')]
    public function getSiteDetails(
        #[Schema(minimum: 1)]
        int $siteId,
    ): array {
        $inputSummary = ['site_id' => $siteId];
        McpAuth::authorizeAndLog('get_site_details', $inputSummary);

        try {
            $site = $this->ci->mainwp_model->get_site($siteId);

            if (!$site) {
                throw new ToolCallException("Site with ID {$siteId} not found.");
            }

            $plugins = [];
            if (!empty($site['plugins_json'])) {
                $decoded = json_decode($site['plugins_json'], true);
                $plugins = is_array($decoded) ? $decoded : [];
            }

            $result = [
                'site' => [
                    'id'                   => (int) $site['id'],
                    'name'                 => $site['site_name'],
                    'url'                  => $site['site_url'],
                    'status'               => $site['status'] ?? 'unknown',
                    'client_id'            => (int) ($site['client_id'] ?? 0),
                    'type'                 => $site['type'] ?? 'api',
                    'php_version'          => $site['php_version'] ?? '',
                    'wp_version'           => $site['wp_version'] ?? '',
                    'theme'                => $site['active_theme'] ?? '',
                    'last_synced'          => $site['last_synced'] ?? null,
                    'maintenance_schedule' => $site['maintenance_schedule'] ?? null,
                    'next_maintenance'     => $site['next_maintenance'] ?? null,
                ],
                'plugins' => array_map(fn($p) => [
                    'name'             => $p['name'] ?? '',
                    'version'          => $p['version'] ?? '',
                    'update_available' => !empty($p['update']),
                ], $plugins),
            ];

            McpAuth::logToolResult('get_site_details', $inputSummary);

            return $result;
        } catch (ToolCallException $e) {
            McpAuth::logToolResult('get_site_details', $inputSummary, 'error', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create a new manual MainWP site entry and link it to a client. This creates a database record only (no MainWP API call).
     *
     * @param int $clientId Client ID to link the site to
     * @param string $url Site URL (e.g. https://example.com)
     * @param string|null $name Display name (defaults to URL)
     */
    #[McpTool(name: 'create_site')]
    public function createSite(
        #[Schema(minimum: 1)]
        int $clientId,
        string $url,
        ?string $name = null,
    ): array {
        $inputSummary = ['client_id' => $clientId, 'url' => $url];
        McpAuth::authorizeAndLog('create_site', $inputSummary);

        try {
            $this->ci->load->model('clients_model');
            $client = $this->ci->clients_model->get($clientId);
            if (!$client) {
                throw new ToolCallException("Client with ID {$clientId} not found.");
            }

            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new ToolCallException("Invalid URL: {$url}");
            }

            $data = [
                'type'      => 'manual',
                'site_name' => $name ?? parse_url($url, PHP_URL_HOST) ?? $url,
                'site_url'  => rtrim($url, '/'),
                'client_id' => $clientId,
            ];

            $siteResult = $this->ci->mainwp_model->add_site($data);

            if (!$siteResult || empty($siteResult['success'])) {
                $msg = $siteResult['message'] ?? 'Unknown error creating site.';
                throw new ToolCallException("Failed to create site: {$msg}");
            }

            $result = [
                'success' => true,
                'site_id' => (int) $siteResult['id'],
                'message' => "Site '{$data['site_name']}' linked to client '{$client->company}'.",
            ];

            McpAuth::logToolResult('create_site', $inputSummary);

            return $result;
        } catch (ToolCallException $e) {
            McpAuth::logToolResult('create_site', $inputSummary, 'error', $e->getMessage());
            throw $e;
        }
    }
}
