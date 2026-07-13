<?php

declare(strict_types=1);

namespace Expanse\TaskMcp\Tests\Unit;

use Expanse\TaskMcp\Tests\Fakes\FakeTaskRunner;
use Expanse\TaskMcp\Tools\TaskTools;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TaskToolsTest extends TestCase
{
    private FakeTaskRunner $runner;

    private TaskTools $tools;

    protected function setUp(): void
    {
        $this->runner = new FakeTaskRunner();
        $this->tools = new TaskTools($this->runner);
    }

    public function testAddTaskWithAllOptionsBuildsExpectedArgs(): void
    {
        $created = ['uuid' => 'abc-123', 'description' => 'Buy milk', 'status' => 'pending'];
        $this->runner->queueExport([$created]);

        $result = $this->tools->addTask(
            description: 'Buy milk',
            project: 'Home',
            tags: ['errand'],
            due: 'tomorrow',
            priority: 'H',
        );

        self::assertSame(
            [['add', 'project:Home', '+errand', 'due:tomorrow', 'priority:H', '--', 'Buy milk']],
            $this->runner->runCalls,
        );
        self::assertSame([['+LATEST']], $this->runner->exportCalls);
        self::assertSame($created, $result);
    }

    public function testAddTaskWithOnlyDescriptionBuildsMinimalArgs(): void
    {
        $this->runner->queueExport([['uuid' => 'abc-123', 'description' => 'Buy milk']]);

        $this->tools->addTask('Buy milk');

        self::assertSame([['add', '--', 'Buy milk']], $this->runner->runCalls);
    }

    public function testAddTaskThrowsWhenLatestExportIsEmpty(): void
    {
        $this->runner->queueExport([]);

        $this->expectException(RuntimeException::class);

        $this->tools->addTask('Buy milk');
    }

    public function testListTasksDefaultsToPendingStatus(): void
    {
        $this->runner->queueExport([]);

        $this->tools->listTasks();

        self::assertSame([['status:pending']], $this->runner->exportCalls);
    }

    public function testListTasksWithAllFiltersBuildsExpectedFilterList(): void
    {
        $this->runner->queueExport([]);

        $this->tools->listTasks(status: 'completed', project: 'Home', tags: ['errand', 'urgent']);

        self::assertSame(
            [['status:completed', 'project:Home', '+errand', '+urgent']],
            $this->runner->exportCalls,
        );
    }

    public function testListTasksWithStatusAllOmitsStatusFilter(): void
    {
        $this->runner->queueExport([]);

        $this->tools->listTasks(status: 'all');

        self::assertSame([[]], $this->runner->exportCalls);
    }

    public function testListTasksAppliesLimitAfterExport(): void
    {
        $this->runner->queueExport([
            ['uuid' => '1'],
            ['uuid' => '2'],
            ['uuid' => '3'],
        ]);

        $result = $this->tools->listTasks(limit: 2);

        self::assertSame([['uuid' => '1'], ['uuid' => '2']], $result);
    }

    public function testGetTaskDetailsReturnsMatchingTask(): void
    {
        $task = ['uuid' => 'abc-123', 'description' => 'Buy milk'];
        $this->runner->queueExport([$task]);

        $result = $this->tools->getTaskDetails('abc-123');

        self::assertSame([['abc-123']], $this->runner->exportCalls);
        self::assertSame($task, $result);
    }

    public function testGetTaskDetailsThrowsWhenTaskNotFound(): void
    {
        $this->runner->queueExport([]);

        $this->expectException(RuntimeException::class);

        $this->tools->getTaskDetails('missing-uuid');
    }

    public function testMarkTaskDoneRunsDoneThenFetchesTask(): void
    {
        $task = ['uuid' => 'abc-123', 'status' => 'completed'];
        $this->runner->queueExport([$task]);

        $result = $this->tools->markTaskDone('abc-123');

        self::assertSame([['abc-123', 'done']], $this->runner->runCalls);
        self::assertSame([['abc-123']], $this->runner->exportCalls);
        self::assertSame($task, $result);
    }

    public function testModifyTaskBuildsExpectedArgsForAttributeChanges(): void
    {
        $this->runner->queueExport([['uuid' => 'abc-123']]);

        $this->tools->modifyTask(
            uuid: 'abc-123',
            project: 'Work',
            due: 'friday',
            priority: 'M',
            addTags: ['x'],
            removeTags: ['y'],
        );

        self::assertSame(
            [['abc-123', 'modify', 'project:Work', 'due:friday', 'priority:M', '+x', '-y']],
            $this->runner->runCalls,
        );
    }

    public function testModifyTaskWithDescriptionAppendsDoubleDashSeparator(): void
    {
        $this->runner->queueExport([['uuid' => 'abc-123']]);

        $this->tools->modifyTask(uuid: 'abc-123', description: 'New description');

        self::assertSame([['abc-123', 'modify', '--', 'New description']], $this->runner->runCalls);
    }

    public function testModifyTaskThrowsWhenNoFieldsProvided(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->tools->modifyTask(uuid: 'abc-123');
    }

    public function testModifyTaskBuildsExpectedArgsForDependencyChanges(): void
    {
        $this->runner->queueExport([['uuid' => 'abc-123']]);

        $this->tools->modifyTask(
            uuid: 'abc-123',
            addDependencies: ['dep-1', 'dep-2'],
            removeDependencies: ['dep-3'],
        );

        self::assertSame(
            [['abc-123', 'modify', 'depends:dep-1', 'depends:dep-2', 'depends:-dep-3']],
            $this->runner->runCalls,
        );
    }

    public function testAddAnnotationRunsAnnotateThenFetchesTask(): void
    {
        $task = ['uuid' => 'abc-123', 'annotations' => [['description' => 'a note']]];
        $this->runner->queueExport([$task]);

        $result = $this->tools->addAnnotation('abc-123', 'a note');

        self::assertSame([['abc-123', 'annotate', '--', 'a note']], $this->runner->runCalls);
        self::assertSame([['abc-123']], $this->runner->exportCalls);
        self::assertSame($task, $result);
    }

    public function testSyncTasksRunsSyncAndReturnsTrimmedOutput(): void
    {
        $this->runner->queueRunResult("Syncing with ...\n2 changes pulled, 1 pushed.\n");

        $result = $this->tools->syncTasks();

        self::assertSame([['sync']], $this->runner->runCalls);
        self::assertSame(['output' => "Syncing with ...\n2 changes pulled, 1 pushed."], $result);
    }

    public function testSyncTasksPropagatesFailure(): void
    {
        $this->runner->failNextRun(new RuntimeException('No sync.* settings are configured.'));

        $this->expectException(RuntimeException::class);

        $this->tools->syncTasks();
    }
}
