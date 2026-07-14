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

    /** @var list<string> */
    private array $runResultQueue = [];

    /** @var list<list<array{name: string, label: ?string, type: ?string, values: ?list<string>}>> */
    private array $udasQueue = [];

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

        return $this->runResultQueue === [] ? '' : array_shift($this->runResultQueue);
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

    /**
     * Queue the result the next run() call should return.
     */
    public function queueRunResult(string $result): void
    {
        $this->runResultQueue[] = $result;
    }

    public function sync(): string
    {
        return $this->run(['sync']);
    }

    /**
     * @return list<array{name: string, label: ?string, type: ?string, values: ?list<string>}>
     */
    public function udas(): array
    {
        if ($this->udasQueue === []) {
            throw new LogicException('FakeTaskRunner::udas() called with no queued result');
        }

        return array_shift($this->udasQueue);
    }

    /**
     * Queue the result the next udas() call should return.
     *
     * @param list<array{name: string, label: ?string, type: ?string, values: ?list<string>}> $result
     */
    public function queueUdas(array $result): void
    {
        $this->udasQueue[] = $result;
    }
}
