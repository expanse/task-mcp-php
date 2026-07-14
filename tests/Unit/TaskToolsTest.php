<?php

declare(strict_types=1);

namespace Expanse\TaskMcp\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
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

    public function testListTasksWithUdaFiltersBuildsExpectedFilterList(): void
    {
        $this->runner->queueUdas($this->sampleUdas());
        $this->runner->queueExport([]);

        $this->tools->listTasks(udaFilters: ['staleness:stale', 'link:https://example.com']);

        self::assertSame(
            [['status:pending', 'staleness:stale', 'link:https://example.com']],
            $this->runner->exportCalls,
        );
    }

    public function testListTasksThrowsForUnknownUdaFilterName(): void
    {
        $this->runner->queueUdas($this->sampleUdas());

        $this->expectException(InvalidArgumentException::class);

        $this->tools->listTasks(udaFilters: ['not_a_real_uda:x']);
    }

    public function testListTasksThrowsForMalformedUdaFilterEntry(): void
    {
        $this->runner->queueUdas($this->sampleUdas());

        $this->expectException(InvalidArgumentException::class);

        $this->tools->listTasks(udaFilters: ['staleness-stale']);
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

    public function testModifyTaskBuildsExpectedArgsForUdaChanges(): void
    {
        $this->runner->queueUdas($this->sampleUdas());
        $this->runner->queueExport([['uuid' => 'abc-123']]);

        $this->tools->modifyTask(uuid: 'abc-123', udas: ['staleness:fresh', 'link:']);

        self::assertSame(
            [['abc-123', 'modify', 'staleness:fresh', 'link:']],
            $this->runner->runCalls,
        );
    }

    public function testModifyTaskThrowsForUnknownUdaName(): void
    {
        $this->runner->queueUdas($this->sampleUdas());

        $this->expectException(InvalidArgumentException::class);

        $this->tools->modifyTask(uuid: 'abc-123', udas: ['not_a_real_uda:x']);
    }

    public function testModifyTaskThrowsForMalformedUdaEntry(): void
    {
        $this->runner->queueUdas($this->sampleUdas());

        $this->expectException(InvalidArgumentException::class);

        $this->tools->modifyTask(uuid: 'abc-123', udas: ['staleness-fresh']);
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

    public function testRemoveAnnotationRunsDenotateThenFetchesTask(): void
    {
        $task = ['uuid' => 'abc-123', 'annotations' => []];
        $this->runner->queueExport([$task]);

        $result = $this->tools->removeAnnotation('abc-123', 'a note');

        self::assertSame([['abc-123', 'denotate', '--', 'a note']], $this->runner->runCalls);
        self::assertSame([['abc-123']], $this->runner->exportCalls);
        self::assertSame($task, $result);
    }

    public function testDeleteTaskRunsDeleteWithConfirmationOffThenFetchesTask(): void
    {
        $task = ['uuid' => 'abc-123', 'status' => 'deleted'];
        $this->runner->queueExport([$task]);

        $result = $this->tools->deleteTask('abc-123');

        self::assertSame([['rc.confirmation=off', 'abc-123', 'delete']], $this->runner->runCalls);
        self::assertSame($task, $result);
    }

    public function testStartTaskRunsStartThenFetchesTask(): void
    {
        $task = ['uuid' => 'abc-123', 'start' => '20260101T000000Z'];
        $this->runner->queueExport([$task]);

        $result = $this->tools->startTask('abc-123');

        self::assertSame([['abc-123', 'start']], $this->runner->runCalls);
        self::assertSame($task, $result);
    }

    public function testStopTaskRunsStopThenFetchesTask(): void
    {
        $task = ['uuid' => 'abc-123'];
        $this->runner->queueExport([$task]);

        $result = $this->tools->stopTask('abc-123');

        self::assertSame([['abc-123', 'stop']], $this->runner->runCalls);
        self::assertSame($task, $result);
    }

    public function testBatchModifyTasksThrowsWithoutProjectOrTagsFilter(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->tools->batchModifyTasks(priority: 'H');
    }

    public function testBatchModifyTasksThrowsWhenNoFieldsProvided(): void
    {
        $this->runner->queueExport([['uuid' => 'abc-123']]);

        $this->expectException(InvalidArgumentException::class);

        $this->tools->batchModifyTasks(project: 'Home');
    }

    public function testBatchModifyTasksReturnsEmptyWhenNothingMatches(): void
    {
        $this->runner->queueExport([]);

        $result = $this->tools->batchModifyTasks(project: 'Home', priority: 'H');

        self::assertSame([], $result);
        self::assertSame([['status:pending', 'project:Home']], $this->runner->exportCalls);
        self::assertSame([], $this->runner->runCalls);
    }

    public function testBatchModifyTasksBuildsExpectedArgsAndRefetchesByUuid(): void
    {
        $matched = [['uuid' => 'uuid-1'], ['uuid' => 'uuid-2']];
        $updated = [['uuid' => 'uuid-1', 'priority' => 'H'], ['uuid' => 'uuid-2', 'priority' => 'H']];
        $this->runner->queueExport($matched);
        $this->runner->queueExport($updated);

        $result = $this->tools->batchModifyTasks(
            project: 'Home',
            tags: ['errand'],
            priority: 'H',
            addTags: ['urgent'],
        );

        self::assertSame(
            [
                ['status:pending', 'project:Home', '+errand'],
                ['uuid-1', 'uuid-2'],
            ],
            $this->runner->exportCalls,
        );
        self::assertSame(
            [['status:pending', 'project:Home', '+errand', 'rc.confirmation=off', 'modify', 'priority:H', '+urgent']],
            $this->runner->runCalls,
        );
        self::assertSame($updated, $result);
    }

    public function testBatchModifyTasksBuildsExpectedArgsForUdaFiltersAndUdas(): void
    {
        $matched = [['uuid' => 'uuid-1']];
        $updated = [['uuid' => 'uuid-1', 'staleness' => 'fresh']];
        $this->runner->queueUdas($this->sampleUdas());
        $this->runner->queueExport($matched);
        $this->runner->queueUdas($this->sampleUdas());
        $this->runner->queueExport($updated);

        $result = $this->tools->batchModifyTasks(
            project: 'Home',
            udaFilters: ['staleness:stale'],
            udas: ['staleness:fresh'],
        );

        self::assertSame(
            [
                ['status:pending', 'project:Home', 'staleness:stale'],
                ['uuid-1'],
            ],
            $this->runner->exportCalls,
        );
        self::assertSame(
            [['status:pending', 'project:Home', 'staleness:stale', 'rc.confirmation=off', 'modify', 'staleness:fresh']],
            $this->runner->runCalls,
        );
        self::assertSame($updated, $result);
    }

    public function testBatchModifyTasksThrowsForUnknownUdaName(): void
    {
        $this->runner->queueExport([['uuid' => 'uuid-1']]);
        $this->runner->queueUdas($this->sampleUdas());

        $this->expectException(InvalidArgumentException::class);

        $this->tools->batchModifyTasks(project: 'Home', udas: ['not_a_real_uda:x']);
    }

    public function testBatchModifyTasksThrowsForMalformedUdaEntry(): void
    {
        $this->runner->queueExport([['uuid' => 'uuid-1']]);
        $this->runner->queueUdas($this->sampleUdas());

        $this->expectException(InvalidArgumentException::class);

        $this->tools->batchModifyTasks(project: 'Home', udas: ['not_a_real_uda']);
    }

    public function testListUdasReturnsWhatTheRunnerReports(): void
    {
        $this->runner->queueUdas($this->sampleUdas());

        $result = $this->tools->listUdas();

        self::assertSame($this->sampleUdas(), $result);
    }

    public function testListProjectsCountsByProjectExcludingProjectless(): void
    {
        $this->runner->queueExport([
            ['uuid' => '1', 'project' => 'Home'],
            ['uuid' => '2', 'project' => 'Home'],
            ['uuid' => '3', 'project' => 'Work'],
            ['uuid' => '4'],
        ]);

        $result = $this->tools->listProjects();

        self::assertSame([['status:pending']], $this->runner->exportCalls);
        self::assertSame(
            [['project' => 'Home', 'count' => 2], ['project' => 'Work', 'count' => 1]],
            $result,
        );
    }

    public function testListProjectsWithStatusAllOmitsStatusFilter(): void
    {
        $this->runner->queueExport([]);

        $this->tools->listProjects(status: 'all');

        self::assertSame([[]], $this->runner->exportCalls);
    }

    public function testListTagsCountsByTag(): void
    {
        $this->runner->queueExport([
            ['uuid' => '1', 'tags' => ['errand', 'urgent']],
            ['uuid' => '2', 'tags' => ['errand']],
            ['uuid' => '3'],
        ]);

        $result = $this->tools->listTags();

        self::assertSame([['status:pending']], $this->runner->exportCalls);
        self::assertSame(
            [['tag' => 'errand', 'count' => 2], ['tag' => 'urgent', 'count' => 1]],
            $result,
        );
    }

    public function testProjectStatusReplicatesTheSummaryReportsMath(): void
    {
        $tenDaysAgo = (new DateTimeImmutable('-10 days', new DateTimeZone('UTC')))->format('Ymd\THis\Z');
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd\THis\Z');

        // Home: 1 pending (10 days old) + 1 completed (fresh) -> 50% complete, ~5 day avg age
        $this->runner->queueExport([
            ['uuid' => '1', 'project' => 'Home', 'entry' => $tenDaysAgo],
            ['uuid' => '2', 'project' => 'Work', 'entry' => $now],
        ]);
        $this->runner->queueExport([
            ['uuid' => '3', 'project' => 'Home', 'entry' => $now],
        ]);

        $result = $this->tools->projectStatus();

        self::assertSame([['status:pending']], [$this->runner->exportCalls[0]]);
        self::assertSame([['status:completed']], [$this->runner->exportCalls[1]]);

        $byProject = [];
        foreach ($result as $row) {
            $byProject[$row['project']] = $row;
        }

        self::assertSame(1, $byProject['Home']['remaining']);
        self::assertSame(1, $byProject['Home']['completed']);
        self::assertSame(50.0, $byProject['Home']['completePercentage']);
        self::assertEqualsWithDelta(5.0, $byProject['Home']['averageAgeDays'], 0.01);

        self::assertSame(1, $byProject['Work']['remaining']);
        self::assertSame(0, $byProject['Work']['completed']);
        self::assertSame(0.0, $byProject['Work']['completePercentage']);
        self::assertEqualsWithDelta(0.0, $byProject['Work']['averageAgeDays'], 0.01);
    }

    public function testProjectStatusReturnsEmptyWhenNoTasks(): void
    {
        $this->runner->queueExport([]);
        $this->runner->queueExport([]);

        self::assertSame([], $this->tools->projectStatus());
    }

    /**
     * @return list<array{name: string, label: ?string, type: ?string, values: ?list<string>}>
     */
    private function sampleUdas(): array
    {
        return [
            ['name' => 'link', 'label' => 'URL', 'type' => 'string', 'values' => null],
            ['name' => 'staleness', 'label' => 'Staleness', 'type' => 'string', 'values' => ['fresh', 'stale']],
        ];
    }
}
