# task-mcp-php

An MCP server exposing [TaskWarrior](https://taskwarrior.org/) as tools for LLM agents, built on [php-mcp/server](https://github.com/php-mcp/server).

## Status

Implements 11 tools via `Expanse\TaskMcp\Tools\TaskTools`: `add_task`, `list_tasks`, `get_task_details`, `mark_task_done`, `modify_task` (attributes, tags, and dependencies), `add_annotation`, `remove_annotation`, `delete_task`, `start_task`, `stop_task`, `batch_modify_tasks`, and `sync_tasks`.

Sync is never triggered automatically — call `sync_tasks` explicitly before reading if you need the latest state from other devices, or after writing if you want changes pushed out promptly.

`list_tasks`' `tags` filter accepts TaskWarrior's virtual tags (`BLOCKED`, `READY`, `WAITING`, `OVERDUE`, etc.) exactly like real tags — e.g. `list_tasks(tags: ['BLOCKED'])` returns blocked tasks with no dedicated tool needed.

`batch_modify_tasks` requires a `project` or `tags` filter (in addition to `status`) so it can't accidentally match every task in the list.

## Requirements

- PHP 8.2+
- Composer
- The `task` CLI available on the host running this server

## Installation

```bash
composer install
```
