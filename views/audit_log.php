<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-10 col-md-offset-1">
                <h4 class="tw-font-semibold tw-mt-0"><?= _l('mcp_audit_log'); ?></h4>

                <!-- Filters -->
                <div class="panel_s">
                    <div class="panel-body">
                        <form method="get" action="<?= admin_url('mcp_connector/audit_log'); ?>" class="form-inline">
                            <select name="tool" class="form-control mright10" aria-label="<?= _l('mcp_audit_tool'); ?>">
                                <option value="">— <?= _l('mcp_audit_tool'); ?> —</option>
                                <?php foreach (['search_clients','get_client','create_client','search_invoices','get_invoice','create_invoice','search_estimates','get_estimate','create_estimate','list_client_sites','get_site_details','create_site'] as $tool): ?>
                                <option value="<?= $tool; ?>" <?= ($filters['tool_name'] ?? '') === $tool ? 'selected' : ''; ?>><?= $tool; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="status" class="form-control mright10" aria-label="<?= _l('mcp_audit_status'); ?>">
                                <option value="">— <?= _l('mcp_audit_status'); ?> —</option>
                                <option value="success" <?= ($filters['status'] ?? '') === 'success' ? 'selected' : ''; ?>>Success</option>
                                <option value="error" <?= ($filters['status'] ?? '') === 'error' ? 'selected' : ''; ?>>Error</option>
                            </select>
                            <input type="date" name="from" class="form-control mright10" value="<?= htmlspecialchars($filters['from'] ?? ''); ?>" aria-label="From date">
                            <input type="date" name="to" class="form-control mright10" value="<?= htmlspecialchars($filters['to'] ?? ''); ?>" aria-label="To date">
                            <button type="submit" class="btn btn-primary">Filter</button>
                        </form>
                    </div>
                </div>

                <!-- Log Table -->
                <div class="panel_s">
                    <div class="panel-body">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th><?= _l('mcp_audit_time'); ?></th>
                                    <th><?= _l('mcp_token_staff'); ?></th>
                                    <th>Token</th>
                                    <th><?= _l('mcp_audit_tool'); ?></th>
                                    <th><?= _l('mcp_audit_input'); ?></th>
                                    <th><?= _l('mcp_audit_status'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($entries as $e): ?>
                                <tr>
                                    <td><?= htmlspecialchars($e['created_at']); ?></td>
                                    <td><?= htmlspecialchars(($e['firstname'] ?? '') . ' ' . ($e['lastname'] ?? '')); ?></td>
                                    <td><?= htmlspecialchars($e['token_label'] ?? '—'); ?></td>
                                    <td><code><?= htmlspecialchars($e['tool_name']); ?></code></td>
                                    <td><small><?= htmlspecialchars(mb_substr($e['input_summary'] ?? '', 0, 100)); ?></small></td>
                                    <td>
                                        <?php if ($e['result_status'] === 'success'): ?>
                                            <span class="label label-success">OK</span>
                                        <?php else: ?>
                                            <span class="label label-danger" title="<?= htmlspecialchars($e['error_message'] ?? ''); ?>">Error</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($entries)): ?>
                                <tr><td colspan="6" class="text-center text-muted">No entries found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
