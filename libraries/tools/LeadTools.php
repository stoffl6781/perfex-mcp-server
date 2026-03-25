<?php
declare(strict_types=1);

namespace Perfexcrm\McpConnector\Tools;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Perfexcrm\McpConnector\McpAuth;

class LeadTools
{
    private ?\CI_Controller $ci = null;

    private function ci(): \CI_Controller
    {
        if ($this->ci === null) {
            $this->ci = &get_instance();
            $this->ci->load->model('leads_model');
        }
        return $this->ci;
    }

    /**
     * Search leads by name, email, or status.
     * @param string|null $query Search term (matches name or email)
     * @param int|null $status Filter by lead status ID
     * @param bool|null $lost Filter by lost status
     * @param int $limit Maximum results
     * @param int $offset Skip first N results
     */
    #[McpTool(name: 'search_leads', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function searchLeads(
        ?string $query = null,
        ?int $status = null,
        ?bool $lost = null,
        #[Schema(minimum: 1, maximum: 100)]
        int $limit = 20,
        #[Schema(minimum: 0)]
        int $offset = 0,
    ): array {
        McpAuth::authorizeAndLog('search_leads', ['query' => $query]);

        try {
            $db = $this->ci()->db;
            $table = db_prefix() . 'leads';

            $db->select("l.id, l.name, l.email, l.phonenumber, l.company, l.status, l.assigned, l.lost, l.junk, l.dateadded, ls.name as status_name")
                ->from("{$table} AS l")
                ->join(db_prefix() . "leads_status AS ls", "ls.id = l.status", "left");

            if ($query !== null && $query !== '') {
                $db->group_start()
                    ->like('l.name', $query)
                    ->or_like('l.email', $query)
                    ->or_like('l.company', $query)
                ->group_end();
            }
            if ($status !== null) {
                $db->where('l.status', $status);
            }
            if ($lost !== null) {
                $db->where('l.lost', $lost ? 1 : 0);
            }

            $totalCount = $db->count_all_results('', false);
            $leads = $db->order_by('l.dateadded', 'DESC')->limit($limit, $offset)->get()->result_array();

            $result = [
                'total_count' => $totalCount,
                'leads' => array_map(fn($l) => [
                    'id' => (int) $l['id'],
                    'name' => $l['name'],
                    'email' => $l['email'],
                    'phone' => $l['phonenumber'] ?? '',
                    'company' => $l['company'] ?? '',
                    'status' => $l['status_name'] ?? 'Unknown',
                    'status_id' => (int) $l['status'],
                    'lost' => (bool) $l['lost'],
                    'junk' => (bool) $l['junk'],
                    'dateadded' => $l['dateadded'],
                ], $leads),
            ];

            McpAuth::logToolResult('search_leads', ['query' => $query]);
            return $result;
        } catch (ToolCallException $e) { throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('Internal error: ' . $e->getMessage());
        }
    }

    /**
     * Get detailed lead information.
     * @param int $leadId The lead ID
     */
    #[McpTool(name: 'get_lead', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function getLead(
        #[Schema(minimum: 1)]
        int $leadId,
    ): array {
        McpAuth::authorizeAndLog('get_lead', ['lead_id' => $leadId]);

        try {
            $lead = $this->ci()->leads_model->get($leadId);
            if (!$lead) {
                throw new ToolCallException("Lead with ID {$leadId} not found.");
            }

            $result = [
                'lead' => [
                    'id' => (int) $lead->id,
                    'name' => $lead->name,
                    'email' => $lead->email ?? '',
                    'phone' => $lead->phonenumber ?? '',
                    'company' => $lead->company ?? '',
                    'address' => $lead->address ?? '',
                    'city' => $lead->city ?? '',
                    'state' => $lead->state ?? '',
                    'zip' => $lead->zip ?? '',
                    'country' => $lead->country ?? '',
                    'description' => $lead->description ?? '',
                    'status' => $lead->status_name ?? 'Unknown',
                    'status_id' => (int) $lead->status,
                    'source' => $lead->source_name ?? '',
                    'assigned_staff_id' => (int) ($lead->assigned ?? 0),
                    'lost' => (bool) ($lead->lost ?? false),
                    'junk' => (bool) ($lead->junk ?? false),
                    'dateadded' => $lead->dateadded ?? '',
                    'last_contact' => $lead->lastcontact ?? '',
                ],
            ];

            McpAuth::logToolResult('get_lead', ['lead_id' => $leadId]);
            return $result;
        } catch (ToolCallException $e) { throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('Internal error: ' . $e->getMessage());
        }
    }

    /**
     * Create a new lead.
     * @param string $name Lead name (person or company)
     * @param string|null $email Email address
     * @param string|null $phone Phone number
     * @param string|null $company Company name
     * @param int|null $status Lead status ID (get IDs from list_lead_statuses)
     * @param int|null $source Lead source ID
     * @param string|null $description Notes about the lead
     */
    #[McpTool(name: 'create_lead', annotations: new ToolAnnotations(destructiveHint: true))]
    public function createLead(
        string $name,
        ?string $email = null,
        ?string $phone = null,
        ?string $company = null,
        ?int $status = null,
        ?int $source = null,
        ?string $description = null,
    ): array {
        McpAuth::authorizeAndLog('create_lead', ['name' => $name]);

        try {
            $token = McpAuth::getCurrentToken();
            $staffId = $token ? (int) $token['staff_id'] : 1;

            $data = [
                'name' => $name,
                'assigned' => $staffId,
            ];
            if ($email !== null) $data['email'] = $email;
            if ($phone !== null) $data['phonenumber'] = $phone;
            if ($company !== null) $data['company'] = $company;
            if ($status !== null) $data['status'] = $status;
            if ($source !== null) $data['source'] = $source;
            if ($description !== null) $data['description'] = $description;

            $leadId = $this->ci()->leads_model->add($data);
            if (!$leadId) {
                throw new ToolCallException('Failed to create lead.');
            }

            $result = [
                'success' => true,
                'lead_id' => (int) $leadId,
                'message' => "Lead '{$name}' created.",
            ];

            McpAuth::logToolResult('create_lead', ['name' => $name]);
            return $result;
        } catch (ToolCallException $e) { throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('Internal error: ' . $e->getMessage());
        }
    }

    /**
     * List all lead statuses. Use the returned IDs when creating or filtering leads.
     */
    #[McpTool(name: 'list_lead_statuses', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function listLeadStatuses(): array
    {
        McpAuth::authorizeAndLog('list_lead_statuses', []);

        try {
            $statuses = $this->ci()->leads_model->get_status();

            $result = [
                'statuses' => array_map(fn($s) => [
                    'id' => (int) $s['id'],
                    'name' => $s['name'],
                    'color' => $s['color'] ?? '',
                ], $statuses),
            ];

            McpAuth::logToolResult('list_lead_statuses', []);
            return $result;
        } catch (\Throwable $e) {
            throw new ToolCallException('Internal error: ' . $e->getMessage());
        }
    }
}
