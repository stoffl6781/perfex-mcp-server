<?php
declare(strict_types=1);

namespace Perfexcrm\McpConnector\Tools;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Perfexcrm\McpConnector\McpAuth;

class ClientTools
{
    private ?\CI_Controller $ci = null;

    private function ci(): \CI_Controller
    {
        if ($this->ci === null) {
            $this->ci = &get_instance();
            $this->ci->load->model('clients_model');
        }
        return $this->ci;
    }

    /**
     * Search clients by name, company, or email.
     *
     * @param string|null $query Search term (matches company, firstname, lastname, email)
     * @param int $limit Maximum results to return
     * @param int $offset Skip first N results for pagination
     */
    #[McpTool(name: 'search_clients')]
    public function searchClients(
        ?string $query = null,
        #[Schema(minimum: 1, maximum: 100)]
        int $limit = 20,
        #[Schema(minimum: 0)]
        int $offset = 0,
    ): array {
        try {
            $inputSummary = ['query' => $query, 'limit' => $limit, 'offset' => $offset];
            McpAuth::authorizeAndLog('search_clients', $inputSummary);
        } catch (\Throwable $e) {
            throw new ToolCallException('Auth error: ' . $e->getMessage());
        }

        try {
            $db = $this->ci()->db;
            $clients_table = db_prefix() . 'clients';
            $contacts_table = db_prefix() . 'contacts';

            // Join with primary contact for name/email search
            $db->select("c.userid, c.company, c.phonenumber, c.active, co.firstname, co.lastname, co.email")
                ->from("{$clients_table} AS c")
                ->join("{$contacts_table} AS co", "co.userid = c.userid AND co.is_primary = 1", "left");

            if ($query !== null && $query !== '') {
                $db->group_start()
                    ->like('c.company', $query)
                    ->or_like('co.firstname', $query)
                    ->or_like('co.lastname', $query)
                    ->or_like('co.email', $query)
                    ->or_like('c.phonenumber', $query)
                ->group_end();
            }

            $totalCount = $db->count_all_results('', false);

            $clients = $db->order_by('c.company', 'ASC')
                ->limit($limit, $offset)
                ->get()
                ->result_array();

            $result = [
                'total_count' => $totalCount,
                'clients'     => $clients,
            ];

            McpAuth::logToolResult('search_clients', $inputSummary);

            return $result;
        } catch (ToolCallException $e) {
            McpAuth::logToolResult('search_clients', $inputSummary, 'error', $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $msg = get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
            McpAuth::logToolResult('search_clients', $inputSummary, 'error', $msg);
            throw new ToolCallException('Internal error: ' . $msg);
        }
    }

    /**
     * Get detailed client information including contacts and linked MainWP sites.
     *
     * @param int $clientId The client ID to retrieve
     */
    #[McpTool(name: 'get_client')]
    public function getClient(
        #[Schema(minimum: 1)]
        int $clientId,
    ): array {
        $inputSummary = ['client_id' => $clientId];
        McpAuth::authorizeAndLog('get_client', $inputSummary);

        try {
            $client = $this->ci()->clients_model->get($clientId);

            if (!$client) {
                throw new ToolCallException("Client with ID {$clientId} not found.");
            }

            $contacts = $this->ci()->clients_model->get_contacts($clientId);

            $sites = [];
            if (module_exists('mainwp_connect')) {
                $this->ci()->load->model('mainwp_connect/mainwp_model');
                $sites = $this->ci()->mainwp_model->get_by_client($clientId) ?: [];
            }

            $result = [
                'client' => [
                    'id'          => $client->userid,
                    'company'     => $client->company,
                    'vat'         => $client->vat,
                    'phonenumber' => $client->phonenumber,
                    'country'     => $client->country,
                    'city'        => $client->city,
                    'zip'         => $client->zip,
                    'state'       => $client->state,
                    'address'     => $client->address,
                    'email'       => $client->email,
                    'active'      => (int) $client->active,
                    'datecreated' => $client->datecreated,
                ],
                'contacts' => array_map(fn($c) => [
                    'id'        => $c['id'],
                    'firstname' => $c['firstname'],
                    'lastname'  => $c['lastname'],
                    'email'     => $c['email'],
                    'phone'     => $c['phonenumber'],
                    'is_primary' => (int) $c['is_primary'],
                ], $contacts),
                'mainwp_sites' => array_map(fn($s) => [
                    'id'   => $s['id'],
                    'name' => $s['site_name'],
                    'url'  => $s['site_url'],
                ], $sites),
            ];

            McpAuth::logToolResult('get_client', $inputSummary);

            return $result;
        } catch (ToolCallException $e) {
            McpAuth::logToolResult('get_client', $inputSummary, 'error', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create a new client in Perfex CRM.
     *
     * @param string $company Company name (required if no firstname/lastname)
     * @param string|null $firstname Contact first name
     * @param string|null $lastname Contact last name
     * @param string|null $email Email address
     * @param string|null $phonenumber Phone number
     * @param string|null $address Street address
     * @param string|null $city City
     * @param string|null $zip ZIP / postal code
     * @param int|null $country Country ID (see Perfex countries table)
     * @param string|null $vat VAT number (e.g. ATU12345678)
     */
    #[McpTool(name: 'create_client')]
    public function createClient(
        string $company = '',
        ?string $firstname = null,
        ?string $lastname = null,
        ?string $email = null,
        ?string $phonenumber = null,
        ?string $address = null,
        ?string $city = null,
        ?string $zip = null,
        ?int $country = null,
        ?string $vat = null,
    ): array {
        $inputSummary = ['company' => $company, 'email' => $email];
        McpAuth::authorizeAndLog('create_client', $inputSummary);

        try {
            if (empty($company) && (empty($firstname) || empty($lastname))) {
                throw new ToolCallException('Either company or firstname + lastname is required.');
            }

            if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new ToolCallException("Invalid email format: {$email}");
            }

            $data = array_filter([
                'company'     => $company,
                'phonenumber' => $phonenumber,
                'address'     => $address,
                'city'        => $city,
                'zip'         => $zip,
                'country'     => $country,
                'vat'         => $vat,
            ], fn($v) => $v !== null && $v !== '');

            $withContact = false;
            if ($firstname !== null || $lastname !== null || $email !== null) {
                $data['firstname'] = $firstname ?? '';
                $data['lastname']  = $lastname ?? '';
                $data['email']     = $email ?? '';
                $data['donotsendwelcomeemail'] = true;
                $withContact = true;
            }

            $clientId = $this->ci()->clients_model->add($data, $withContact);

            if (!$clientId || !is_numeric($clientId)) {
                throw new ToolCallException('Failed to create client. Check required fields.');
            }

            $result = [
                'success'   => true,
                'client_id' => (int) $clientId,
                'message'   => "Client '{$company}' created successfully with ID {$clientId}.",
            ];

            McpAuth::logToolResult('create_client', $inputSummary);

            return $result;
        } catch (ToolCallException $e) {
            McpAuth::logToolResult('create_client', $inputSummary, 'error', $e->getMessage());
            throw $e;
        }
    }
}
