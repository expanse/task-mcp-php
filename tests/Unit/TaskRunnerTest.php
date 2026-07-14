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

    public function testUdasParsesRealTaskWarriorOutput(): void
    {
        $dir = sys_get_temp_dir() . '/task-mcp-php-test-' . uniqid();
        mkdir($dir);
        $taskrc = $dir . '/.taskrc';
        $taskdata = $dir . '/data';

        file_put_contents($taskrc, implode("\n", [
            "data.location={$taskdata}",
            'uda.link.label=URL',
            'uda.link.type=string',
            'uda.staleness.label=Staleness',
            'uda.staleness.type=string',
            'uda.staleness.values=fresh,stale,',
        ]));

        $runner = new TaskRunner(taskrc: $taskrc, taskdata: $taskdata);

        $udas = $runner->udas();
        $byName = [];

        foreach ($udas as $uda) {
            $byName[$uda['name']] = $uda;
        }

        self::assertSame(['name' => 'link', 'label' => 'URL', 'type' => 'string', 'values' => null], $byName['link']);
        self::assertSame(
            ['name' => 'staleness', 'label' => 'Staleness', 'type' => 'string', 'values' => ['fresh', 'stale']],
            $byName['staleness'],
        );

        self::removeDirectory($dir);
    }

    private static function removeDirectory(string $path): void
    {
        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = "{$path}/{$item}";

            is_dir($itemPath) ? self::removeDirectory($itemPath) : unlink($itemPath);
        }

        rmdir($path);
    }
}
