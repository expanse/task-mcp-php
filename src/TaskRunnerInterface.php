<?php

declare(strict_types=1);

namespace Expanse\TaskMcp;

interface TaskRunnerInterface
{
    /**
     * Run a `task` subcommand and return raw stdout.
     *
     * @param list<string> $args
     */
    public function run(array $args): string;

    /**
     * Run a filtered export and decode the resulting JSON array of tasks.
     *
     * @param list<string> $filters
     * @return list<array<string, mixed>>
     */
    public function export(array $filters = []): array;

    /**
     * Sync with the configured TaskWarrior sync server and return raw stdout.
     */
    public function sync(): string;
}
