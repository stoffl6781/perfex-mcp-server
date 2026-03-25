<?php
declare(strict_types=1);

namespace Perfexcrm\McpConnector\Tools;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Perfexcrm\McpConnector\McpAuth;

class EstimateTools
{
    private ?\CI_Controller $ci = null;

    private function ci(): \CI_Controller
    {
        if ($this->ci === null) {
            $this->ci = &get_instance();
            $this->ci->load->model('estimates_model');
        }
        return $this->ci;
    }

    /**
     * Search estimates by client, status, or date range.
     *
     * @param int|null $clientId Filter by client ID
     * @param string|null $status Filter by status: draft, sent, accepted, declined, expired
     * @param int $limit Maximum results
     * @param int $offset Skip first N results
     */
    #[McpTool(name: 'search_estimates', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function searchEstimates(
        ?int $clientId = null,
        ?string $status = null,
        #[Schema(minimum: 1, maximum: 100)]
        int $limit = 20,
        #[Schema(minimum: 0)]
        int $offset = 0,
    ): array {
        $inputSummary = ['client_id' => $clientId, 'status' => $status, 'limit' => $limit];
        McpAuth::authorizeAndLog('search_estimates', $inputSummary);

        try {
            $db = $this->ci()->db;
            $table = db_prefix() . 'estimates';

            $statusMap = [
                'draft' => 1, 'sent' => 2, 'declined' => 3,
                'accepted' => 4, 'expired' => 5,
            ];

            $db->select("e.id, e.number, e.prefix, e.total, e.status, e.date, e.expirydate, e.clientid, c.company as client_name")
                ->from("{$table} AS e")
                ->join(db_prefix() . "clients AS c", "c.userid = e.clientid", "left");

            if ($clientId !== null) {
                $db->where('e.clientid', $clientId);
            }
            if ($status !== null && isset($statusMap[$status])) {
                $db->where('e.status', $statusMap[$status]);
            }

            $totalCount = $db->count_all_results('', false);

            $estimates = $db->order_by('e.date', 'DESC')
                ->limit($limit, $offset)
                ->get()
                ->result_array();

            $statusLabels = array_flip($statusMap);

            $result = [
                'total_count' => $totalCount,
                'estimates'   => array_map(fn($est) => [
                    'id'          => (int) $est['id'],
                    'number'      => $est['prefix'] . $est['number'],
                    'client_id'   => (int) $est['clientid'],
                    'client_name' => $est['client_name'],
                    'total'       => (float) $est['total'],
                    'status'      => $statusLabels[(int) $est['status']] ?? 'unknown',
                    'date'        => $est['date'],
                    'expirydate'  => $est['expirydate'],
                ], $estimates),
            ];

            McpAuth::logToolResult('search_estimates', $inputSummary);

            return $result;
        } catch (ToolCallException $e) {
            McpAuth::logToolResult('search_estimates', $inputSummary, 'error', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get full estimate details including line items.
     *
     * @param int $estimateId The estimate ID
     */
    #[McpTool(name: 'get_estimate', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function getEstimate(
        #[Schema(minimum: 1)]
        int $estimateId,
    ): array {
        $inputSummary = ['estimate_id' => $estimateId];
        McpAuth::authorizeAndLog('get_estimate', $inputSummary);

        try {
            $estimate = $this->ci()->estimates_model->get($estimateId);

            if (!$estimate) {
                throw new ToolCallException("Estimate with ID {$estimateId} not found.");
            }

            $statusLabels = [
                1 => 'draft', 2 => 'sent', 3 => 'declined',
                4 => 'accepted', 5 => 'expired',
            ];

            $items = get_items_by_type('estimate', $estimateId);

            $result = [
                'estimate' => [
                    'id'         => (int) $estimate->id,
                    'number'     => $estimate->prefix . $estimate->number,
                    'client_id'  => (int) $estimate->clientid,
                    'status'     => $statusLabels[(int) $estimate->status] ?? 'unknown',
                    'date'       => $estimate->date,
                    'expirydate' => $estimate->expirydate,
                    'subtotal'   => (float) $estimate->subtotal,
                    'total_tax'  => (float) $estimate->total_tax,
                    'total'      => (float) $estimate->total,
                    'notes'      => $estimate->clientnote ?? '',
                ],
                'items' => array_map(fn($item) => [
                    'description' => $item['description'],
                    'qty'         => (float) $item['qty'],
                    'rate'        => (float) $item['rate'],
                    'amount'      => (float) ($item['qty'] * $item['rate']),
                    'tax'         => $item['taxname'] ?? [],
                ], $items),
            ];

            McpAuth::logToolResult('get_estimate', $inputSummary);

            return $result;
        } catch (ToolCallException $e) {
            McpAuth::logToolResult('get_estimate', $inputSummary, 'error', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create a new estimate. Tax format: "TaxName|TaxRate" (e.g. "MwSt|20.00").
     *
     * @param int $clientId Client ID (must exist)
     * @param array $items Line items: each with description, qty, rate, taxname (optional)
     * @param string|null $expirydate Expiry date (YYYY-MM-DD)
     * @param int|null $currency Currency ID
     * @param string|null $notes Client-facing notes
     */
    #[McpTool(name: 'create_estimate', annotations: new ToolAnnotations(destructiveHint: true))]
    public function createEstimate(
        #[Schema(minimum: 1)]
        int $clientId,
        array $items,
        ?string $expirydate = null,
        ?int $currency = null,
        ?string $notes = null,
    ): array {
        $inputSummary = ['client_id' => $clientId, 'item_count' => count($items)];
        McpAuth::authorizeAndLog('create_estimate', $inputSummary);

        try {
            $this->ci()->load->model('clients_model');
            $client = $this->ci()->clients_model->get($clientId);
            if (!$client) {
                throw new ToolCallException("Client with ID {$clientId} not found.");
            }

            if (empty($items)) {
                throw new ToolCallException('At least one item is required.');
            }

            $newitems = [];
            $subtotal = 0;
            foreach ($items as $i => $item) {
                if (empty($item['description'])) {
                    throw new ToolCallException("Item {$i}: description is required.");
                }
                $qty  = (float) ($item['qty'] ?? 1);
                $rate = (float) ($item['rate'] ?? 0);

                if ($qty <= 0 || $rate < 0) {
                    throw new ToolCallException("Item {$i}: qty must be > 0 and rate >= 0.");
                }

                $taxname = [];
                if (!empty($item['taxname'])) {
                    $tax = is_array($item['taxname']) ? $item['taxname'] : [$item['taxname']];
                    foreach ($tax as $t) {
                        if (!preg_match('/^.+\|\d+(\.\d+)?$/', $t)) {
                            throw new ToolCallException("Item {$i}: invalid tax format '{$t}'.");
                        }
                        $taxname[] = $t;
                    }
                }

                $amount = $qty * $rate;
                $subtotal += $amount;

                $newitems[] = [
                    'description' => $item['description'],
                    'qty'         => $qty,
                    'unit'        => $item['unit'] ?? '',
                    'rate'        => $rate,
                    'amount'      => $amount,
                    'taxname'     => $taxname,
                ];
            }

            $data = [
                'clientid'   => $clientId,
                'date'       => date('Y-m-d'),
                'expirydate' => $expirydate,
                'currency'   => $currency ?? $client->default_currency ?: 0,
                'subtotal'   => $subtotal,
                'total'      => $subtotal,
                'newitems'   => $newitems,
                'clientnote' => $notes ?? '',
                'status'     => 1,
            ];

            $estimateId = $this->ci()->estimates_model->add($data);

            if (!$estimateId || !is_numeric($estimateId)) {
                throw new ToolCallException('Failed to create estimate.');
            }

            $estimate = $this->ci()->estimates_model->get($estimateId);

            $result = [
                'success'     => true,
                'estimate_id' => (int) $estimateId,
                'number'      => $estimate->prefix . $estimate->number,
                'total'       => (float) $estimate->total,
                'status'      => 'draft',
                'message'     => "Estimate {$estimate->prefix}{$estimate->number} created for client '{$client->company}'.",
            ];

            McpAuth::logToolResult('create_estimate', $inputSummary);

            return $result;
        } catch (ToolCallException $e) {
            McpAuth::logToolResult('create_estimate', $inputSummary, 'error', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update an existing estimate's metadata (expiry date, notes, status).
     *
     * @param int $estimateId Estimate ID to update
     * @param string|null $expirydate New expiry date (YYYY-MM-DD)
     * @param string|null $notes Client-facing notes
     * @param string|null $status New status: draft, sent, accepted, declined, expired
     */
    #[McpTool(name: 'update_estimate', annotations: new ToolAnnotations(destructiveHint: true))]
    public function updateEstimate(
        #[Schema(minimum: 1)]
        int $estimateId,
        ?string $expirydate = null,
        ?string $notes = null,
        ?string $status = null,
    ): array {
        $inputSummary = ['estimate_id' => $estimateId];
        McpAuth::authorizeAndLog('update_estimate', $inputSummary);

        try {
            $estimate = $this->ci()->estimates_model->get($estimateId);
            if (!$estimate) {
                throw new ToolCallException("Estimate with ID {$estimateId} not found.");
            }

            $statusMap = [
                'draft' => 1, 'sent' => 2, 'declined' => 3,
                'accepted' => 4, 'expired' => 5,
            ];

            $data = [];
            if ($expirydate !== null) $data['expirydate'] = $expirydate;
            if ($notes !== null) $data['clientnote'] = $notes;
            if ($status !== null) {
                if (!isset($statusMap[$status])) {
                    throw new ToolCallException("Invalid status '{$status}'. Use: draft, sent, accepted, declined, expired");
                }
                $data['status'] = $statusMap[$status];
            }

            if (empty($data)) {
                throw new ToolCallException('No fields provided to update.');
            }

            $this->ci()->estimates_model->update($data, $estimateId);

            $result = [
                'success'     => true,
                'estimate_id' => $estimateId,
                'number'      => $estimate->prefix . $estimate->number,
                'updated_fields' => array_keys($data),
                'message'     => "Estimate {$estimate->prefix}{$estimate->number} updated. Fields: " . implode(', ', array_keys($data)),
            ];

            McpAuth::logToolResult('update_estimate', $inputSummary);
            return $result;
        } catch (ToolCallException $e) {
            McpAuth::logToolResult('update_estimate', $inputSummary, 'error', $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $msg = get_class($e) . ': ' . $e->getMessage();
            McpAuth::logToolResult('update_estimate', $inputSummary, 'error', $msg);
            throw new ToolCallException('Internal error: ' . $msg);
        }
    }

    /**
     * Convert an estimate to an invoice. Copies all items, billing info, and amounts. The estimate is marked as accepted.
     *
     * @param int $estimateId Estimate ID to convert
     * @param bool $asDraft Create invoice as draft (default true)
     */
    #[McpTool(name: 'convert_estimate_to_invoice', annotations: new ToolAnnotations(destructiveHint: true))]
    public function convertEstimateToInvoice(
        #[Schema(minimum: 1)]
        int $estimateId,
        bool $asDraft = true,
    ): array {
        $inputSummary = ['estimate_id' => $estimateId];
        McpAuth::authorizeAndLog('convert_estimate_to_invoice', $inputSummary);

        try {
            $estimate = $this->ci()->estimates_model->get($estimateId);
            if (!$estimate) {
                throw new ToolCallException("Estimate with ID {$estimateId} not found.");
            }

            $invoiceId = $this->ci()->estimates_model->convert_to_invoice($estimateId, false, $asDraft);

            if (!$invoiceId) {
                throw new ToolCallException('Failed to convert estimate to invoice.');
            }

            $this->ci()->load->model('invoices_model');
            $invoice = $this->ci()->invoices_model->get($invoiceId);

            $result = [
                'success'     => true,
                'invoice_id'  => (int) $invoiceId,
                'invoice_number' => $invoice->prefix . $invoice->number,
                'estimate_id' => $estimateId,
                'total'       => (float) $invoice->total,
                'status'      => $asDraft ? 'draft' : 'unpaid',
                'message'     => "Estimate {$estimate->prefix}{$estimate->number} converted to invoice {$invoice->prefix}{$invoice->number}.",
            ];

            McpAuth::logToolResult('convert_estimate_to_invoice', $inputSummary);
            return $result;
        } catch (ToolCallException $e) {
            McpAuth::logToolResult('convert_estimate_to_invoice', $inputSummary, 'error', $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $msg = get_class($e) . ': ' . $e->getMessage();
            McpAuth::logToolResult('convert_estimate_to_invoice', $inputSummary, 'error', $msg);
            throw new ToolCallException('Internal error: ' . $msg);
        }
    }
}
