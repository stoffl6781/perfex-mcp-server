<?php
declare(strict_types=1);

namespace Perfexcrm\McpConnector\Tools;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Perfexcrm\McpConnector\McpAuth;

class ProjectTools
{
    private ?\CI_Controller $ci = null;

    private function ci(): \CI_Controller
    {
        if ($this->ci === null) {
            $this->ci = &get_instance();
            $this->ci->load->model('projects_model');
            $this->ci->load->model('tasks_model');
        }
        return $this->ci;
    }

    /**
     * Search projects by name, client, or status.
     *
     * @param string|null $query Search term (matches project name)
     * @param int|null $clientId Filter by client ID
     * @param string|null $status Filter: not_started, in_progress, pending, complete, on_hold
     * @param int $limit Maximum results
     * @param int $offset Skip first N results
     */
    #[McpTool(name: 'search_projects', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function searchProjects(
        ?string $query = null,
        ?int $clientId = null,
        ?string $status = null,
        #[Schema(minimum: 1, maximum: 100)]
        int $limit = 20,
        #[Schema(minimum: 0)]
        int $offset = 0,
    ): array {
        McpAuth::authorizeAndLog('search_projects', ['query' => $query]);

        try {
            $db = $this->ci()->db;
            $table = db_prefix() . 'projects';
            $statusMap = ['not_started' => 1, 'in_progress' => 2, 'pending' => 3, 'complete' => 4, 'on_hold' => 5];

            $db->select("p.id, p.name, p.status, p.start_date, p.deadline, p.clientid, c.company as client_name")
                ->from("{$table} AS p")
                ->join(db_prefix() . "clients AS c", "c.userid = p.clientid", "left");

            if ($query !== null && $query !== '') {
                $db->like('p.name', $query);
            }
            if ($clientId !== null) {
                $db->where('p.clientid', $clientId);
            }
            if ($status !== null && isset($statusMap[$status])) {
                $db->where('p.status', $statusMap[$status]);
            }

            $totalCount = $db->count_all_results('', false);
            $projects = $db->order_by('p.start_date', 'DESC')->limit($limit, $offset)->get()->result_array();

            $statusLabels = array_flip($statusMap);
            $result = [
                'total_count' => $totalCount,
                'projects' => array_map(fn($p) => [
                    'id' => (int) $p['id'],
                    'name' => $p['name'],
                    'status' => $statusLabels[(int) $p['status']] ?? 'unknown',
                    'client_id' => (int) $p['clientid'],
                    'client_name' => $p['client_name'],
                    'start_date' => $p['start_date'],
                    'deadline' => $p['deadline'],
                ], $projects),
            ];

            McpAuth::logToolResult('search_projects', ['query' => $query]);
            return $result;
        } catch (ToolCallException $e) { throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('Internal error: ' . $e->getMessage());
        }
    }

    /**
     * Get detailed project information including tasks summary.
     * @param int $projectId The project ID
     */
    #[McpTool(name: 'get_project', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function getProject(
        #[Schema(minimum: 1)]
        int $projectId,
    ): array {
        McpAuth::authorizeAndLog('get_project', ['project_id' => $projectId]);

        try {
            $project = $this->ci()->projects_model->get($projectId);
            if (!$project) {
                throw new ToolCallException("Project with ID {$projectId} not found.");
            }

            $statusLabels = [1 => 'not_started', 2 => 'in_progress', 3 => 'pending', 4 => 'complete', 5 => 'on_hold'];

            // Get task counts grouped by status
            $db = $this->ci()->db;
            $tasks = $db->select('status, COUNT(*) as count')
                ->where('rel_type', 'project')
                ->where('rel_id', $projectId)
                ->group_by('status')
                ->get(db_prefix() . 'tasks')
                ->result_array();

            $taskStatusLabels = [1 => 'not_started', 2 => 'awaiting_feedback', 3 => 'testing', 4 => 'in_progress', 5 => 'complete'];
            $taskSummary = [];
            foreach ($tasks as $t) {
                $label = $taskStatusLabels[(int) $t['status']] ?? 'unknown';
                $taskSummary[$label] = (int) $t['count'];
            }

            $result = [
                'project' => [
                    'id' => (int) $project->id,
                    'name' => $project->name,
                    'description' => $project->description ?? '',
                    'status' => $statusLabels[(int) $project->status] ?? 'unknown',
                    'client_id' => (int) $project->clientid,
                    'start_date' => $project->start_date,
                    'deadline' => $project->deadline,
                    'billing_type' => (int) $project->billing_type,
                    'cost' => (float) ($project->project_cost ?? 0),
                    'rate_per_hour' => (float) ($project->project_rate_per_hour ?? 0),
                ],
                'task_summary' => $taskSummary,
            ];

            McpAuth::logToolResult('get_project', ['project_id' => $projectId]);
            return $result;
        } catch (ToolCallException $e) { throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('Internal error: ' . $e->getMessage());
        }
    }

    /**
     * Create a new project.
     * @param string $name Project name
     * @param int $clientId Client ID
     * @param string $startDate Start date (YYYY-MM-DD)
     * @param int $billingType 1=Fixed Rate, 2=Project Hours, 3=Task Hours
     * @param string|null $deadline Deadline (YYYY-MM-DD)
     * @param string|null $description Project description
     * @param float|null $cost Project cost (for fixed rate)
     * @param float|null $ratePerHour Rate per hour
     */
    #[McpTool(name: 'create_project', annotations: new ToolAnnotations(destructiveHint: true))]
    public function createProject(
        string $name,
        #[Schema(minimum: 1)]
        int $clientId,
        string $startDate,
        #[Schema(minimum: 1, maximum: 3)]
        int $billingType = 1,
        ?string $deadline = null,
        ?string $description = null,
        ?float $cost = null,
        ?float $ratePerHour = null,
    ): array {
        McpAuth::authorizeAndLog('create_project', ['name' => $name, 'client_id' => $clientId]);

        try {
            $this->ci()->load->model('clients_model');
            $client = $this->ci()->clients_model->get($clientId);
            if (!$client) {
                throw new ToolCallException("Client with ID {$clientId} not found.");
            }

            $data = [
                'name' => $name,
                'clientid' => $clientId,
                'start_date' => $startDate,
                'billing_type' => $billingType,
                'status' => 2, // In Progress
            ];
            if ($deadline !== null) $data['deadline'] = $deadline;
            if ($description !== null) $data['description'] = $description;
            if ($cost !== null) $data['project_cost'] = $cost;
            if ($ratePerHour !== null) $data['project_rate_per_hour'] = $ratePerHour;

            $projectId = $this->ci()->projects_model->add($data);
            if (!$projectId) {
                throw new ToolCallException('Failed to create project.');
            }

            $result = [
                'success' => true,
                'project_id' => (int) $projectId,
                'message' => "Project '{$name}' created for client '{$client->company}'.",
            ];

            McpAuth::logToolResult('create_project', ['name' => $name]);
            return $result;
        } catch (ToolCallException $e) { throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('Internal error: ' . $e->getMessage());
        }
    }

    /**
     * Search tasks, optionally filtered by project or status.
     * @param int|null $projectId Filter by project ID
     * @param string|null $status Filter: not_started, awaiting_feedback, testing, in_progress, complete
     * @param int $limit Maximum results
     * @param int $offset Skip first N results
     */
    #[McpTool(name: 'search_tasks', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function searchTasks(
        ?int $projectId = null,
        ?string $status = null,
        #[Schema(minimum: 1, maximum: 100)]
        int $limit = 20,
        #[Schema(minimum: 0)]
        int $offset = 0,
    ): array {
        McpAuth::authorizeAndLog('search_tasks', ['project_id' => $projectId]);

        try {
            $db = $this->ci()->db;
            $statusMap = ['not_started' => 1, 'awaiting_feedback' => 2, 'testing' => 3, 'in_progress' => 4, 'complete' => 5];

            $db->select("t.id, t.name, t.status, t.startdate, t.duedate, t.rel_type, t.rel_id, t.billable, t.dateadded")
                ->from(db_prefix() . "tasks AS t");

            if ($projectId !== null) {
                $db->where('t.rel_type', 'project');
                $db->where('t.rel_id', $projectId);
            }
            if ($status !== null && isset($statusMap[$status])) {
                $db->where('t.status', $statusMap[$status]);
            }

            $totalCount = $db->count_all_results('', false);
            $tasks = $db->order_by('t.duedate', 'ASC')->limit($limit, $offset)->get()->result_array();

            $statusLabels = array_flip($statusMap);
            $result = [
                'total_count' => $totalCount,
                'tasks' => array_map(fn($t) => [
                    'id' => (int) $t['id'],
                    'name' => $t['name'],
                    'status' => $statusLabels[(int) $t['status']] ?? 'unknown',
                    'start_date' => $t['startdate'],
                    'due_date' => $t['duedate'],
                    'rel_type' => $t['rel_type'],
                    'rel_id' => (int) $t['rel_id'],
                    'billable' => (int) $t['billable'],
                ], $tasks),
            ];

            McpAuth::logToolResult('search_tasks', ['project_id' => $projectId]);
            return $result;
        } catch (ToolCallException $e) { throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('Internal error: ' . $e->getMessage());
        }
    }

    /**
     * Create a new task, optionally linked to a project.
     * @param string $name Task name
     * @param string $startDate Start date (YYYY-MM-DD)
     * @param string $dueDate Due date (YYYY-MM-DD)
     * @param int|null $projectId Link to project (sets rel_type=project)
     * @param string|null $description Task description
     * @param bool $billable Is this task billable?
     */
    #[McpTool(name: 'create_task', annotations: new ToolAnnotations(destructiveHint: true))]
    public function createTask(
        string $name,
        string $startDate,
        string $dueDate,
        ?int $projectId = null,
        ?string $description = null,
        bool $billable = false,
    ): array {
        McpAuth::authorizeAndLog('create_task', ['name' => $name]);

        try {
            $data = [
                'name' => $name,
                'startdate' => $startDate,
                'duedate' => $dueDate,
                'billable' => $billable ? 1 : 0,
                'description' => $description ?? '',
            ];

            if ($projectId !== null) {
                $data['rel_type'] = 'project';
                $data['rel_id'] = $projectId;
            }

            $taskId = $this->ci()->tasks_model->add($data);
            if (!$taskId) {
                throw new ToolCallException('Failed to create task.');
            }

            $result = [
                'success' => true,
                'task_id' => (int) $taskId,
                'message' => "Task '{$name}' created" . ($projectId ? " in project {$projectId}" : "") . ".",
            ];

            McpAuth::logToolResult('create_task', ['name' => $name]);
            return $result;
        } catch (ToolCallException $e) { throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('Internal error: ' . $e->getMessage());
        }
    }

    /**
     * Log time on a task (timesheet entry).
     * @param int $taskId Task ID
     * @param float $hours Number of hours to log
     * @param string|null $date Date of work (YYYY-MM-DD), defaults to today
     * @param string|null $note Note for the timesheet entry
     */
    #[McpTool(name: 'log_time', annotations: new ToolAnnotations(destructiveHint: true))]
    public function logTime(
        #[Schema(minimum: 1)]
        int $taskId,
        #[Schema(minimum: 0.01)]
        float $hours,
        ?string $date = null,
        ?string $note = null,
    ): array {
        McpAuth::authorizeAndLog('log_time', ['task_id' => $taskId, 'hours' => $hours]);

        try {
            $task = $this->ci()->tasks_model->get($taskId);
            if (!$task) {
                throw new ToolCallException("Task with ID {$taskId} not found.");
            }

            // Convert hours to start/end timestamps
            $dateStr = $date ?? date('Y-m-d');
            $startTime = strtotime($dateStr . ' 09:00:00');
            $seconds = (int) ($hours * 3600);
            $endTime = $startTime + $seconds;

            $CI = $this->ci();
            $staffId = 1; // Default to admin, will be overridden if staff context available
            $token = McpAuth::getCurrentToken();
            if ($token) {
                $staffId = (int) $token['staff_id'];
            }

            $data = [
                'timesheet_task_id' => $taskId,
                'timesheet_staff_id' => $staffId,
                'timesheet_start_time' => (string) $startTime,
                'timesheet_end_time' => (string) $endTime,
            ];
            if ($note !== null) {
                $data['note'] = $note;
            }

            $CI->tasks_model->save_timesheet($data);

            $result = [
                'success' => true,
                'task_id' => $taskId,
                'hours' => $hours,
                'date' => $dateStr,
                'staff_id' => $staffId,
                'message' => "{$hours}h logged on task '{$task->name}'.",
            ];

            McpAuth::logToolResult('log_time', ['task_id' => $taskId, 'hours' => $hours]);
            return $result;
        } catch (ToolCallException $e) { throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('Internal error: ' . $e->getMessage());
        }
    }
}
