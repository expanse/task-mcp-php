<?php

declare(strict_types=1);

namespace Expanse\TaskMcp\Tools;

use DateTimeImmutable;
use DateTimeZone;
use Expanse\TaskMcp\TaskRunnerInterface;
use InvalidArgumentException;
use PhpMcp\Server\Attributes\McpTool;
use RuntimeException;

final class TaskTools
{
    public function __construct(
        private readonly TaskRunnerInterface $tasks,
    ) {
    }

    /**
     * TaskWarrior doesn't reject an unrecognized attribute name on modify -
     * it silently reinterprets the whole "name:value" token as literal
     * description text instead, which can overwrite a task's description
     * with garbage. Filtering by an unknown name is safer (just an empty
     * result) but still a silent footgun. Check against the real UDA list
     * ourselves so a typo becomes a clear error instead of either of those.
     *
     * @param list<string> $names
     */
    private function assertKnownUdaNames(array $names): void
    {
        $known = array_column($this->tasks->udas(), 'name');
        $unknown = array_diff($names, $known);

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown UDA(s): %s. Call list_udas to see what is defined.',
                implode(', ', $unknown),
            ));
        }
    }

    /**
     * Create a new task.
     *
     * @param string $description The task description
     * @param string|null $project Project to file the task under, e.g. "Home.Renovation"
     * @param list<string>|null $tags Tags to attach, without the leading "+"
     * @param string|null $due Due date, any format TaskWarrior accepts (e.g. "tomorrow", "2026-08-01")
     * @param string|null $priority Priority: H, M, or L
     * @return array<string, mixed> The created task
     */
    #[McpTool(name: 'add_task')]
    public function addTask(
        string $description,
        ?string $project = null,
        ?array $tags = null,
        ?string $due = null,
        ?string $priority = null,
    ): array {
        $args = ['add'];

        if ($project !== null) {
            $args[] = "project:{$project}";
        }

        foreach ($tags ?? [] as $tag) {
            $args[] = "+{$tag}";
        }

        if ($due !== null) {
            $args[] = "due:{$due}";
        }

        if ($priority !== null) {
            $args[] = "priority:{$priority}";
        }

        $args[] = '--';
        $args[] = $description;

        $this->tasks->run($args);

        $created = $this->tasks->export(['+LATEST']);

        if ($created === []) {
            throw new RuntimeException('Task was created but could not be retrieved via +LATEST');
        }

        return $created[0];
    }

    /**
     * List tasks matching simple filters.
     *
     * @param string $status One of: pending, completed, deleted, all (default: pending)
     * @param string|null $project Filter by exact project name
     * @param list<string>|null $tags Only return tasks with all of these tags. Also
     *     accepts TaskWarrior's virtual tags (BLOCKED, READY, WAITING, OVERDUE, DUE, ...)
     * @param array<string, string>|null $udaFilters Only return tasks where each named
     *     User Defined Attribute equals the given value. See list_udas for what's available.
     * @param int|null $limit Maximum number of tasks to return
     * @return list<array<string, mixed>>
     */
    #[McpTool(name: 'list_tasks')]
    public function listTasks(
        string $status = 'pending',
        ?string $project = null,
        ?array $tags = null,
        ?array $udaFilters = null,
        ?int $limit = null,
    ): array {
        $filters = [];

        if ($status !== 'all') {
            $filters[] = "status:{$status}";
        }

        if ($project !== null) {
            $filters[] = "project:{$project}";
        }

        foreach ($tags ?? [] as $tag) {
            $filters[] = "+{$tag}";
        }

        if ($udaFilters !== null) {
            $this->assertKnownUdaNames(array_keys($udaFilters));
        }

        foreach ($udaFilters ?? [] as $name => $value) {
            $filters[] = "{$name}:{$value}";
        }

        $results = $this->tasks->export($filters);

        return $limit !== null ? array_slice($results, 0, $limit) : $results;
    }

    /**
     * Get the full record for a single task.
     *
     * @param string $uuid The task's UUID
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_task_details')]
    public function getTaskDetails(string $uuid): array
    {
        $results = $this->tasks->export([$uuid]);

        if ($results === []) {
            throw new RuntimeException("No task found with UUID {$uuid}");
        }

        return $results[0];
    }

    /**
     * Mark a task as complete.
     *
     * @param string $uuid The task's UUID
     * @return array<string, mixed> The completed task
     */
    #[McpTool(name: 'mark_task_done')]
    public function markTaskDone(string $uuid): array
    {
        $this->tasks->run([$uuid, 'done']);

        return $this->getTaskDetails($uuid);
    }

    /**
     * Modify an existing task's attributes.
     *
     * @param string $uuid The task's UUID
     * @param string|null $description Replace the task description
     * @param string|null $project Reassign the task's project
     * @param string|null $due Change the due date, any format TaskWarrior accepts, or "" to clear it
     * @param string|null $priority Change priority: H, M, L, or "" to clear it
     * @param list<string>|null $addTags Tags to add, without the leading "+"
     * @param list<string>|null $removeTags Tags to remove, without the leading "-"
     * @param list<string>|null $addDependencies UUIDs of tasks this task should depend on
     * @param list<string>|null $removeDependencies UUIDs of dependencies to remove
     * @param array<string, string>|null $udas User Defined Attributes to set, keyed by
     *     name, e.g. {"staleness": "fresh"}. Use "" as the value to clear one. See
     *     list_udas for what's available and any constrained values.
     * @return array<string, mixed> The modified task
     */
    #[McpTool(name: 'modify_task')]
    public function modifyTask(
        string $uuid,
        ?string $description = null,
        ?string $project = null,
        ?string $due = null,
        ?string $priority = null,
        ?array $addTags = null,
        ?array $removeTags = null,
        ?array $addDependencies = null,
        ?array $removeDependencies = null,
        ?array $udas = null,
    ): array {
        $args = [$uuid, 'modify'];

        if ($project !== null) {
            $args[] = "project:{$project}";
        }

        if ($due !== null) {
            $args[] = "due:{$due}";
        }

        if ($priority !== null) {
            $args[] = "priority:{$priority}";
        }

        foreach ($addTags ?? [] as $tag) {
            $args[] = "+{$tag}";
        }

        foreach ($removeTags ?? [] as $tag) {
            $args[] = "-{$tag}";
        }

        foreach ($addDependencies ?? [] as $dependencyUuid) {
            $args[] = "depends:{$dependencyUuid}";
        }

        foreach ($removeDependencies ?? [] as $dependencyUuid) {
            $args[] = "depends:-{$dependencyUuid}";
        }

        if ($udas !== null) {
            $this->assertKnownUdaNames(array_keys($udas));
        }

        foreach ($udas ?? [] as $name => $value) {
            $args[] = "{$name}:{$value}";
        }

        if ($description !== null) {
            $args[] = '--';
            $args[] = $description;
        }

        if (count($args) === 2) {
            throw new InvalidArgumentException('modify_task requires at least one field to change');
        }

        $this->tasks->run($args);

        return $this->getTaskDetails($uuid);
    }

    /**
     * Attach a note to a task.
     *
     * @param string $uuid The task's UUID
     * @param string $note The annotation text
     * @return array<string, mixed> The annotated task
     */
    #[McpTool(name: 'add_annotation')]
    public function addAnnotation(string $uuid, string $note): array
    {
        $this->tasks->run([$uuid, 'annotate', '--', $note]);

        return $this->getTaskDetails($uuid);
    }

    /**
     * Remove a note from a task. The note text must match an existing
     * annotation (see TaskWarrior's denotate matching rules).
     *
     * @param string $uuid The task's UUID
     * @param string $note The annotation text to remove
     * @return array<string, mixed> The task
     */
    #[McpTool(name: 'remove_annotation')]
    public function removeAnnotation(string $uuid, string $note): array
    {
        $this->tasks->run([$uuid, 'denotate', '--', $note]);

        return $this->getTaskDetails($uuid);
    }

    /**
     * Permanently delete a task. TaskWarrior keeps it as a "deleted" record
     * rather than erasing it outright.
     *
     * @param string $uuid The task's UUID
     * @return array<string, mixed> The deleted task
     */
    #[McpTool(name: 'delete_task')]
    public function deleteTask(string $uuid): array
    {
        $this->tasks->run(['rc.confirmation=off', $uuid, 'delete']);

        return $this->getTaskDetails($uuid);
    }

    /**
     * Start the timer on a task.
     *
     * @param string $uuid The task's UUID
     * @return array<string, mixed> The task
     */
    #[McpTool(name: 'start_task')]
    public function startTask(string $uuid): array
    {
        $this->tasks->run([$uuid, 'start']);

        return $this->getTaskDetails($uuid);
    }

    /**
     * Stop the timer on a task.
     *
     * @param string $uuid The task's UUID
     * @return array<string, mixed> The task
     */
    #[McpTool(name: 'stop_task')]
    public function stopTask(string $uuid): array
    {
        $this->tasks->run([$uuid, 'stop']);

        return $this->getTaskDetails($uuid);
    }

    /**
     * Apply the same modification to every task matching a filter, in one
     * TaskWarrior call. Requires a project or tags filter (in addition to
     * status) so it can't accidentally match every task.
     *
     * @param string|null $project Only modify tasks in this exact project
     * @param list<string>|null $tags Only modify tasks with all of these tags
     * @param string $status One of: pending, completed, deleted, all (default: pending)
     * @param string|null $due Change the due date, any format TaskWarrior accepts, or "" to clear it
     * @param string|null $priority Change priority: H, M, L, or "" to clear it
     * @param list<string>|null $addTags Tags to add, without the leading "+"
     * @param list<string>|null $removeTags Tags to remove, without the leading "-"
     * @param list<string>|null $addDependencies UUIDs of tasks the matched tasks should depend on
     * @param list<string>|null $removeDependencies UUIDs of dependencies to remove
     * @param array<string, string>|null $udaFilters Only match tasks where each named
     *     User Defined Attribute equals the given value
     * @param array<string, string>|null $udas User Defined Attributes to set on every
     *     matched task, keyed by name. Use "" as the value to clear one.
     * @return list<array<string, mixed>> The modified tasks
     */
    #[McpTool(name: 'batch_modify_tasks')]
    public function batchModifyTasks(
        ?string $project = null,
        ?array $tags = null,
        string $status = 'pending',
        ?string $due = null,
        ?string $priority = null,
        ?array $addTags = null,
        ?array $removeTags = null,
        ?array $addDependencies = null,
        ?array $removeDependencies = null,
        ?array $udaFilters = null,
        ?array $udas = null,
    ): array {
        if ($project === null && ($tags === null || $tags === [])) {
            throw new InvalidArgumentException(
                'batch_modify_tasks requires a project or tags filter, to avoid accidentally matching every task',
            );
        }

        $filters = [];

        if ($status !== 'all') {
            $filters[] = "status:{$status}";
        }

        if ($project !== null) {
            $filters[] = "project:{$project}";
        }

        foreach ($tags ?? [] as $tag) {
            $filters[] = "+{$tag}";
        }

        if ($udaFilters !== null) {
            $this->assertKnownUdaNames(array_keys($udaFilters));
        }

        foreach ($udaFilters ?? [] as $name => $value) {
            $filters[] = "{$name}:{$value}";
        }

        $matched = $this->tasks->export($filters);

        if ($matched === []) {
            return [];
        }

        $args = [...$filters, 'rc.confirmation=off', 'modify'];

        if ($due !== null) {
            $args[] = "due:{$due}";
        }

        if ($priority !== null) {
            $args[] = "priority:{$priority}";
        }

        foreach ($addTags ?? [] as $tag) {
            $args[] = "+{$tag}";
        }

        foreach ($removeTags ?? [] as $tag) {
            $args[] = "-{$tag}";
        }

        foreach ($addDependencies ?? [] as $dependencyUuid) {
            $args[] = "depends:{$dependencyUuid}";
        }

        foreach ($removeDependencies ?? [] as $dependencyUuid) {
            $args[] = "depends:-{$dependencyUuid}";
        }

        if ($udas !== null) {
            $this->assertKnownUdaNames(array_keys($udas));
        }

        foreach ($udas ?? [] as $name => $value) {
            $args[] = "{$name}:{$value}";
        }

        if (count($args) === count($filters) + 2) {
            throw new InvalidArgumentException('batch_modify_tasks requires at least one field to change');
        }

        $this->tasks->run($args);

        $uuids = array_column($matched, 'uuid');

        return $this->tasks->export($uuids);
    }

    /**
     * Sync with the configured TaskWarrior sync server. Call this explicitly
     * before reading tasks if you need the latest state from other devices,
     * and after writing if you want changes pushed out promptly - nothing
     * triggers sync automatically.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'sync_tasks')]
    public function syncTasks(): array
    {
        $output = $this->tasks->sync();

        return ['output' => trim($output)];
    }

    /**
     * List the User Defined Attributes (UDAs) configured for this
     * TaskWarrior installation - custom fields beyond the built-in
     * project/due/priority/tags/depends, available for use in modify_task's,
     * batch_modify_tasks', and list_tasks' uda parameters.
     *
     * @return list<array{name: string, label: ?string, type: ?string, values: ?list<string>}>
     */
    #[McpTool(name: 'list_udas')]
    public function listUdas(): array
    {
        return $this->tasks->udas();
    }

    /**
     * List the distinct project names in use, with a task count for each.
     * Matches TaskWarrior's own `task projects` report, which only
     * considers pending tasks by default.
     *
     * @param string $status One of: pending, completed, deleted, all (default: pending)
     * @return list<array{project: string, count: int}>
     */
    #[McpTool(name: 'list_projects')]
    public function listProjects(string $status = 'pending'): array
    {
        $counts = [];

        foreach ($this->tasks->export($status === 'all' ? [] : ["status:{$status}"]) as $task) {
            $project = $task['project'] ?? null;

            if ($project === null) {
                continue;
            }

            $counts[$project] = ($counts[$project] ?? 0) + 1;
        }

        ksort($counts);

        $result = [];

        foreach ($counts as $project => $count) {
            $result[] = ['project' => $project, 'count' => $count];
        }

        return $result;
    }

    /**
     * List the distinct tags in use, with a task count for each. Matches
     * TaskWarrior's own `task tags` report, which only considers pending
     * tasks by default.
     *
     * @param string $status One of: pending, completed, deleted, all (default: pending)
     * @return list<array{tag: string, count: int}>
     */
    #[McpTool(name: 'list_tags')]
    public function listTags(string $status = 'pending'): array
    {
        $counts = [];

        foreach ($this->tasks->export($status === 'all' ? [] : ["status:{$status}"]) as $task) {
            foreach ($task['tags'] ?? [] as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }

        ksort($counts);

        $result = [];

        foreach ($counts as $tag => $count) {
            $result[] = ['tag' => $tag, 'count' => $count];
        }

        return $result;
    }

    /**
     * Per-project completion stats, replicating TaskWarrior's `summary`
     * report (which has no structured/export output of its own - this is
     * computed from raw exported tasks, not shelled out to `summary`
     * directly). Excludes deleted tasks entirely, same as the real report.
     * Projectless tasks are grouped under "(none)".
     *
     * @return list<array{project: string, remaining: int, completed: int, completePercentage: float, averageAgeDays: float}>
     */
    #[McpTool(name: 'project_status')]
    public function projectStatus(): array
    {
        $projects = [];

        foreach ($this->tasks->export(['status:pending']) as $task) {
            $name = $task['project'] ?? '(none)';
            $projects[$name]['remaining'] = ($projects[$name]['remaining'] ?? 0) + 1;
            $projects[$name]['completed'] ??= 0;
            $projects[$name]['ages'][] = $this->ageInDays($task['entry']);
        }

        foreach ($this->tasks->export(['status:completed']) as $task) {
            $name = $task['project'] ?? '(none)';
            $projects[$name]['completed'] = ($projects[$name]['completed'] ?? 0) + 1;
            $projects[$name]['remaining'] ??= 0;
            $projects[$name]['ages'][] = $this->ageInDays($task['entry']);
        }

        ksort($projects);

        $result = [];

        foreach ($projects as $name => $data) {
            $total = $data['remaining'] + $data['completed'];

            $result[] = [
                'project' => $name,
                'remaining' => $data['remaining'],
                'completed' => $data['completed'],
                'completePercentage' => $total > 0 ? round($data['completed'] / $total * 100, 1) : 0.0,
                'averageAgeDays' => round(array_sum($data['ages']) / count($data['ages']), 1),
            ];
        }

        return $result;
    }

    private function ageInDays(string $entry): float
    {
        $entryDate = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $entry, new DateTimeZone('UTC'));

        if ($entryDate === false) {
            throw new RuntimeException("Could not parse TaskWarrior entry date: {$entry}");
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return ($now->getTimestamp() - $entryDate->getTimestamp()) / 86400;
    }
}
