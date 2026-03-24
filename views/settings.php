<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                <h4 class="tw-font-semibold tw-mt-0"><?= _l('mcp_settings'); ?></h4>

                <?php if (!empty($new_token)): ?>
                <div class="alert alert-success">
                    <strong><?= _l('mcp_token_created_msg'); ?></strong>
                    <pre class="tw-mt-2 tw-bg-gray-100 tw-p-3 tw-rounded tw-select-all tw-text-sm"><?= htmlspecialchars($new_token); ?></pre>
                </div>
                <?php endif; ?>

                <!-- Create Token -->
                <div class="panel_s">
                    <div class="panel-heading"><?= _l('mcp_token_create'); ?></div>
                    <div class="panel-body">
                        <?= form_open(admin_url('mcp_connector/settings')); ?>
                            <div class="form-group">
                                <label for="label"><?= _l('mcp_token_label'); ?></label>
                                <input type="text" name="label" id="label" class="form-control" required placeholder="z.B. Claude Desktop">
                            </div>
                            <div class="form-group">
                                <label for="staff_id"><?= _l('mcp_token_staff'); ?></label>
                                <select name="staff_id" id="staff_id" class="form-control selectpicker" required>
                                    <?php foreach ($staff as $s): ?>
                                    <option value="<?= $s['staffid']; ?>"><?= htmlspecialchars($s['firstname'] . ' ' . $s['lastname']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><?= _l('mcp_token_permissions'); ?></label>
                                <p class="text-muted tw-mb-2"><?= _l('mcp_perm_read'); ?> / <?= _l('mcp_perm_write'); ?></p>

                                <?php
                                $groups = [
                                    'clients'   => _l('mcp_perm_clients'),
                                    'invoices'  => _l('mcp_perm_invoices'),
                                    'estimates' => _l('mcp_perm_estimates'),
                                    'mainwp'    => _l('mcp_perm_mainwp'),
                                ];
                                foreach ($groups as $key => $label): ?>
                                <div class="onoffswitch mbot10">
                                    <input type="checkbox" name="groups[]" value="<?= $key; ?>"
                                        id="group_<?= $key; ?>" class="onoffswitch-checkbox" checked>
                                    <label class="onoffswitch-label" for="group_<?= $key; ?>"></label>
                                    <span class="tw-ml-2"><?= $label; ?></span>
                                </div>
                                <?php endforeach; ?>

                                <hr>

                                <?php
                                $access = [
                                    'read'  => _l('mcp_perm_read'),
                                    'write' => _l('mcp_perm_write'),
                                ];
                                foreach ($access as $key => $label): ?>
                                <div class="onoffswitch mbot10">
                                    <input type="checkbox" name="access[]" value="<?= $key; ?>"
                                        id="access_<?= $key; ?>" class="onoffswitch-checkbox" checked>
                                    <label class="onoffswitch-label" for="access_<?= $key; ?>"></label>
                                    <span class="tw-ml-2"><?= $label; ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-group">
                                <label for="expires_at"><?= _l('mcp_token_expires'); ?></label>
                                <input type="date" name="expires_at" id="expires_at" class="form-control" placeholder="<?= _l('mcp_token_never'); ?>">
                            </div>
                            <button type="submit" name="create_token" value="1" class="btn btn-primary"><?= _l('mcp_token_create'); ?></button>
                        <?= form_close(); ?>
                    </div>
                </div>

                <!-- Token List -->
                <div class="panel_s">
                    <div class="panel-heading"><?= _l('mcp_tokens'); ?></div>
                    <div class="panel-body">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th><?= _l('mcp_token_label'); ?></th>
                                    <th><?= _l('mcp_token_staff'); ?></th>
                                    <th>Token</th>
                                    <th><?= _l('mcp_token_last_used'); ?></th>
                                    <th><?= _l('mcp_token_expires'); ?></th>
                                    <th><?= _l('mcp_token_status'); ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tokens as $t): ?>
                                <tr class="<?= (int) $t['is_active'] ? '' : 'text-muted'; ?>">
                                    <td><?= htmlspecialchars($t['label']); ?></td>
                                    <td><?= htmlspecialchars($t['firstname'] . ' ' . $t['lastname']); ?></td>
                                    <td><code>mcp_...<?= htmlspecialchars($t['token_hint']); ?></code></td>
                                    <td><?= $t['last_used_at'] ?: '—'; ?></td>
                                    <td><?= $t['expires_at'] ?: _l('mcp_token_never'); ?></td>
                                    <td>
                                        <?php if ((int) $t['is_active']): ?>
                                            <span class="label label-success"><?= _l('mcp_token_active'); ?></span>
                                        <?php else: ?>
                                            <span class="label label-default"><?= _l('mcp_token_inactive'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int) $t['is_active']): ?>
                                        <?= form_open(admin_url('mcp_connector/settings'), ['style' => 'display:inline']); ?>
                                            <input type="hidden" name="revoke_token_id" value="<?= $t['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-xs" onclick="return confirm('<?= _l('mcp_token_revoke'); ?>?')">
                                                <?= _l('mcp_token_revoke'); ?>
                                            </button>
                                        <?= form_close(); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Claude Config Hint -->
                <div class="panel_s">
                    <div class="panel-heading">Claude Desktop Configuration</div>
                    <div class="panel-body">
                        <p>Add this to your Claude Desktop <code>claude_desktop_config.json</code>:</p>
                        <pre class="tw-bg-gray-100 tw-p-3 tw-rounded tw-text-sm">{
  "mcpServers": {
    "perfex-crm": {
      "type": "streamable-http",
      "url": "<?= site_url('mcp_connector/mcp_server'); ?>",
      "headers": {
        "Authorization": "Bearer YOUR_TOKEN_HERE"
      }
    }
  }
}</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
