<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Mcp_connector extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('mcp_connector/mcp_model');
    }

    public function settings(): void
    {
        if (!is_admin()) {
            access_denied('MCP Connector');
        }

        if ($this->input->post('create_token')) {
            $token = $this->mcp_model->create_token([
                'staff_id'    => $this->input->post('staff_id'),
                'label'       => $this->input->post('label'),
                'permissions' => [
                    'groups' => $this->input->post('groups') ?: [],
                    'access' => $this->input->post('access') ?: [],
                ],
                'expires_at'  => $this->input->post('expires_at') ?: null,
            ]);

            if ($token) {
                set_alert('success', _l('mcp_token_created_msg'));
                $data['new_token'] = $token;
            }
        }

        if ($this->input->post('revoke_token_id')) {
            $this->mcp_model->revoke_token((int) $this->input->post('revoke_token_id'));
            set_alert('success', _l('mcp_token_revoked'));
            redirect(admin_url('mcp_connector/settings'));
        }

        $data['title']  = _l('mcp_settings');
        $data['tokens'] = $this->mcp_model->get_tokens();
        $data['staff']  = $this->db->select('staffid, firstname, lastname')
            ->where('active', 1)
            ->get(db_prefix() . 'staff')
            ->result_array();

        $this->load->view('settings', $data);
    }

    public function audit_log(): void
    {
        if (!is_admin()) {
            access_denied('MCP Connector');
        }

        $filters = [
            'staff_id'  => $this->input->get('staff_id'),
            'tool_name' => $this->input->get('tool'),
            'status'    => $this->input->get('status'),
            'from'      => $this->input->get('from'),
            'to'        => $this->input->get('to'),
        ];

        $data['title']   = _l('mcp_audit_log');
        $data['entries'] = $this->mcp_model->get_audit_log(array_filter($filters));
        $data['filters'] = $filters;

        $this->load->view('audit_log', $data);
    }
}
