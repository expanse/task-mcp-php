<?php

declare(strict_types=1);

namespace Expanse\TaskMcp\Tests\Unit;

use Expanse\TaskMcp\TaskRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

final class TaskRunnerTest extends TestCase
{
    public function testRunEnforcesATimeout(): void
    {
        $runner = new TaskRunner(binary: 'sleep', timeout: 0.2);

        $this->expectException(ProcessTimedOutException::class);

        $runner->run(['2']);
    }

    public function testRunAllowsDisablingTheTimeout(): void
    {
        $runner = new TaskRunner(binary: 'true', timeout: null);

        $output = $runner->run([]);

        self::assertSame('', $output);
    }

    public function testRunSucceedsWellUnderTheTimeout(): void
    {
        $runner = new TaskRunner(binary: 'echo', timeout: 5.0);

        $output = $runner->run(['hello']);

        self::assertSame("hello\n", $output);
    }
}
