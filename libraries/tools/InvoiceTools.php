<?php
declare(strict_types=1);

namespace Perfexcrm\McpConnector\Tools;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Perfexcrm\McpConnector\McpAuth;

class InvoiceTools
{
    private \CI_Controller $ci;

    public function __construct()
    {
        $this->ci = &get_instance();
        $this->ci->load->model('invoices_model');
    }

    /**
     * Search invoices by client, status, or date range.
     *
     * @param int|null $clientId Filter by client ID
     * @param string|null $status Filter by status: unpaid, paid, partially, overdue, cancelled, draft
     * @param string|null $fromDate Filter from date (YYYY-MM-DD)
     * @param string|null $toDate Filter to date (YYYY-MM-DD)
     * @param int $limit Maximum results
     * @param int $offset Skip first N results
     */
    #[McpTool(name: 'search_invoices', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function searchInvoices(
        ?int $clientId = null,
        ?string $status = null,
        ?string $fromDate = null,
        ?string $toDate = null,
        #[Schema(minimum: 1, maximum: 100)]
        int $limit = 20,
        #[Schema(minimum: 0)]
        int $offset = 0,
    ): array {
        $inputSummary = ['client_id' => $clientId, 'status' => $status, 'from' => $fromDate, 'to' => $toDate, 'limit' => $limit];
        McpAuth::authorizeAndLog('search_invoices', $inputSummary);

        try {
            $db = $this->ci->db;
            $table = db_prefix() . 'invoices';

            $statusMap = [
                'unpaid'    => 1, 'paid'      => 2, 'partially' => 3,
                'overdue'   => 4, 'cancelled' => 5, 'draft'     => 6,
            ];

            $db->select("i.id, i.number, i.prefix, i.total, i.status, i.date, i.duedate, i.clientid, c.company as client_name")
                ->from("{$table} AS i")
                ->join(db_prefix() . "clients AS c", "c.userid = i.clientid", "left");

            if ($clientId !== null) {
                $db->where('i.clientid', $clientId);
            }
            if ($status !== null && isset($statusMap[$status])) {
                $db->where('i.status', $statusMap[$status]);
            }
            if ($fromDate !== null) {
                $db->where('i.date >=', $fromDate);
            }
            if ($toDate !== null) {
                $db->where('i.date <=', $toDate);
            }

            $totalCount = $db->count_all_results('', false);

            $invoices = $db->order_by('i.date', 'DESC')
                ->limit($limit, $offset)
                ->get()
                ->result_array();

            $statusLabels = array_flip($statusMap);

            $result = [
                'total_count' => $totalCount,
                'invoices'    => array_map(fn($inv) => [
                    'id'          => (int) $inv['id'],
                    'number'      => $inv['prefix'] . $inv['number'],
                    'client_id'   => (int) $inv['clientid'],
                    'client_name' => $inv['client_name'],
                    'total'       => (float) $inv['total'],
                    'status'      => $statusLabels[(int) $inv['status']] ?? 'unknown',
                    'date'        => $inv['date'],
                    'duedate'     => $inv['duedate'],
                ], $invoices),
            ];

            McpAuth::logToolResult('search_invoices', $inputSummary);

            return $result;
        } catch (ToolCallException $e) {
            McpAuth::logToolResult('search_invoices', $inputSummary, 'error', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get full invoice details including line items and payments.
     *
     * @param int $invoiceId The invoice ID
     */
    #[McpTool(name: 'get_invoice', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function getInvoice(
        #[Schema(minimum: 1)]
        int $invoiceId,
    ): array {
        $inputSummary = ['invoice_id' => $invoiceId];
        McpAuth::authorizeAndLog('get_invoice', $inputSummary);

        try {
            $invoice = $this->ci->invoices_model->get($invoiceId);

            if (!$invoice) {
                throw new ToolCallException("Invoice with ID {$invoiceId} not found.");
            }

            $statusLabels = [
                1 => 'unpaid', 2 => 'paid', 3 => 'partially',
                4 => 'overdue', 5 => 'cancelled', 6 => 'draft',
            ];

            $items = get_items_by_type('invoice', $invoiceId);
            $payments = $this->ci->invoices_model->get_invoice_payments($invoiceId);

            $result = [
                'invoice' => [
                    'id'          => (int) $invoice->id,
                    'number'      => $invoice->prefix . $invoice->number,
                    'client_id'   => (int) $invoice->clientid,
                    'status'      => $statusLabels[(int) $invoice->status] ?? 'unknown',
                    'date'        => $invoice->date,
                    'duedate'     => $invoice->duedate,
                    'subtotal'    => (float) $invoice->subtotal,
                    'total_tax'   => (float) $invoice->total_tax,
                    'total'       => (float) $invoice->total,
                    'currency'    => $invoice->currency_name ?? '',
                    'notes'       => $invoice->clientnote ?? '',
                    'admin_note'  => $invoice->adminnote ?? '',
                ],
                'items' => array_map(fn($item) => [
                    'description' => $item['description'],
                    'qty'         => (float) $item['qty'],
                    'rate'        => (float) $item['rate'],
                    'amount'      => (float) ($item['qty'] * $item['rate']),
                    'tax'         => $item['taxname'] ?? [],
                ], $items),
                'payments' => array_map(fn($p) => [
                    'id'     => (int) $p['id'],
                    'amount' => (float) $p['amount'],
                    'date'   => $p['date'],
                    'mode'   => $p['paymentmode'] ?? '',
                    'note'   => $p['note'] ?? '',
                ], $payments ?: []),
            ];

            McpAuth::logToolResult('get_invoice', $inputSummary);

            return $result;
        } catch (ToolCallException $e) {
            McpAuth::logToolResult('get_invoice', $inputSummary, 'error', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create a new invoice. Tax format for items: "TaxName|TaxRate" (e.g. "MwSt|20.00").
     *
     * @param int $clientId Client ID (must exist)
     * @param array $items Line items: each with description (string), qty (number), rate (number), taxname (string, optional, format "Name|Rate")
     * @param string|null $duedate Due date (YYYY-MM-DD)
     * @param int|null $currency Currency ID (defaults to client's currency)
     * @param string|null $notes Client-facing notes
     * @param bool $asDraft Save as draft (default true)
     */
    #[McpTool(name: 'create_invoice', annotations: new ToolAnnotations(destructiveHint: true))]
    public function createInvoice(
        #[Schema(minimum: 1)]
        int $clientId,
        array $items,
        ?string $duedate = null,
        ?int $currency = null,
        ?string $notes = null,
        bool $asDraft = true,
    ): array {
        $inputSummary = ['client_id' => $clientId, 'item_count' => count($items), 'as_draft' => $asDraft];
        McpAuth::authorizeAndLog('create_invoice', $inputSummary);

        try {
            $this->ci->load->model('clients_model');
            $client = $this->ci->clients_model->get($clientId);
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
                    throw new ToolCallException("Item {$i}: qty must be > 0 and rate must be >= 0.");
                }

                $taxname = [];
                if (!empty($item['taxname'])) {
                    $tax = is_array($item['taxname']) ? $item['taxname'] : [$item['taxname']];
                    foreach ($tax as $t) {
                        if (!preg_match('/^.+\|\d+(\.\d+)?$/', $t)) {
                            throw new ToolCallException("Item {$i}: invalid tax format '{$t}'. Expected 'TaxName|TaxRate' (e.g. 'MwSt|20.00').");
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
                'clientid'       => $clientId,
                'date'           => date('Y-m-d'),
                'duedate'        => $duedate,
                'currency'       => $currency ?? $client->default_currency ?: 0,
                'subtotal'       => $subtotal,
                'total'          => $subtotal,
                'newitems'       => $newitems,
                'clientnote'     => $notes ?? '',
                'save_as_draft'  => $asDraft,
            ];

            $invoiceId = $this->ci->invoices_model->add($data);

            if (!$invoiceId || !is_numeric($invoiceId)) {
                throw new ToolCallException('Failed to create invoice. Check the input data.');
            }

            $invoice = $this->ci->invoices_model->get($invoiceId);

            $result = [
                'success'    => true,
                'invoice_id' => (int) $invoiceId,
                'number'     => $invoice->prefix . $invoice->number,
                'total'      => (float) $invoice->total,
                'status'     => $asDraft ? 'draft' : 'unpaid',
                'message'    => "Invoice {$invoice->prefix}{$invoice->number} created for client '{$client->company}'.",
            ];

            McpAuth::logToolResult('create_invoice', $inputSummary);

            return $result;
        } catch (ToolCallException $e) {
            McpAuth::logToolResult('create_invoice', $inputSummary, 'error', $e->getMessage());
            throw $e;
        }
    }
}
