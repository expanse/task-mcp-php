<?php

declare(strict_types=1);

namespace Expanse\TaskMcp\Tests\Fakes;

use Expanse\TaskMcp\TaskRunnerInterface;
use LogicException;
use Throwable;

final class FakeTaskRunner implements TaskRunnerInterface
{
    /** @var list<list<string>> */
    public array $runCalls = [];

    /** @var list<list<string>> */
    public array $exportCalls = [];

    /** @var list<list<array<string, mixed>>> */
    private array $exportQueue = [];

    private ?Throwable $nextRunException = null;

    /**
     * @param list<string> $args
     */
    public function run(array $args): string
    {
        $this->runCalls[] = $args;

        if ($this->nextRunException !== null) {
            $exception = $this->nextRunException;
            $this->nextRunException = null;

            throw $exception;
        }

        return '';
    }

    /**
     * @param list<string> $filters
     * @return list<array<string, mixed>>
     */
    public function export(array $filters = []): array
    {
        $this->exportCalls[] = $filters;

        if ($this->exportQueue === []) {
            throw new LogicException('FakeTaskRunner::export() called with no queued result');
        }

        return array_shift($this->exportQueue);
    }

    /**
     * Queue the result the next export() call should return.
     *
     * @param list<array<string, mixed>> $result
     */
    public function queueExport(array $result): void
    {
        $this->exportQueue[] = $result;
    }

    /**
     * Make the next run() call throw instead of succeeding.
     */
    public function failNextRun(Throwable $exception): void
    {
        $this->nextRunException = $exception;
    }
}
