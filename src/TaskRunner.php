<?php

declare(strict_types=1);

namespace Expanse\TaskMcp;

use RuntimeException;
use Symfony\Component\Process\Process;

final class TaskRunner implements TaskRunnerInterface
{
    public function __construct(
        private readonly string $binary = 'task',
        private readonly ?string $taskrc = null,
        private readonly ?string $taskdata = null,
        private readonly ?float $timeout = 30.0,
    ) {
    }

    /**
     * Run a `task` subcommand and return raw stdout.
     *
     * @param list<string> $args
     */
    public function run(array $args): string
    {
        $process = new Process([$this->binary, ...$args], env: $this->env());
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                sprintf('task %s failed: %s', implode(' ', $args), trim($process->getErrorOutput())),
            );
        }

        return $process->getOutput();
    }

    /**
     * Run a filtered export and decode the resulting JSON array of tasks.
     *
     * @param list<string> $filters
     * @return list<array<string, mixed>>
     */
    public function export(array $filters = []): array
    {
        $output = $this->run([...$filters, 'export']);
        $decoded = json_decode($output !== '' ? $output : '[]', true);

        if (! is_array($decoded)) {
            throw new RuntimeException('task export returned unexpected output: ' . $output);
        }

        return $decoded;
    }

    /**
     * Sync with the configured TaskWarrior sync server and return raw stdout.
     */
    public function sync(): string
    {
        return $this->run(['sync']);
    }

    /**
     * List the User Defined Attributes (UDAs) configured for this
     * TaskWarrior installation.
     *
     * @return list<array{name: string, label: ?string, type: ?string, values: ?list<string>}>
     */
    public function udas(): array
    {
        $output = $this->run(['show', 'uda']);

        $definitions = [];

        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^uda\.([a-zA-Z0-9_]+)\.(label|type|values)\s{2,}(.*)$/', $line, $matches) !== 1) {
                continue;
            }

            [, $name, $attribute, $value] = $matches;
            $definitions[$name][$attribute] = $value;
        }

        $udas = [];

        foreach ($definitions as $name => $attributes) {
            $values = isset($attributes['values'])
                ? array_values(array_filter(explode(',', $attributes['values']), fn (string $v): bool => $v !== ''))
                : null;

            $udas[] = [
                'name' => $name,
                'label' => $attributes['label'] ?? null,
                'type' => $attributes['type'] ?? null,
                'values' => $values === [] ? null : $values,
            ];
        }

        return $udas;
    }

    /**
     * @return array<string, string>
     */
    private function env(): array
    {
        $env = [];

        if ($this->taskrc !== null) {
            $env['TASKRC'] = $this->taskrc;
        }

        if ($this->taskdata !== null) {
            $env['TASKDATA'] = $this->taskdata;
        }

        return $env;
    }
}
