<?php

declare(strict_types=1);

namespace Expanse\TaskMcp\Tools;

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
     * @param list<string>|null $tags Only return tasks with all of these tags
     * @param int|null $limit Maximum number of tasks to return
     * @return list<array<string, mixed>>
     */
    #[McpTool(name: 'list_tasks')]
    public function listTasks(
        string $status = 'pending',
        ?string $project = null,
        ?array $tags = null,
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
}
