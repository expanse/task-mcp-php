# task-mcp-php

An MCP server exposing [TaskWarrior](https://taskwarrior.org/) as tools for LLM agents, built on [php-mcp/server](https://github.com/php-mcp/server).

## Status

Implements 15 tools via `Expanse\TaskMcp\Tools\TaskTools`: `add_task`, `list_tasks`, `get_task_details`, `mark_task_done`, `modify_task` (attributes, tags, dependencies, and UDAs), `add_annotation`, `remove_annotation`, `delete_task`, `start_task`, `stop_task`, `batch_modify_tasks`, `sync_tasks`, `list_udas`, `list_projects`, `list_tags`, and `project_status`.

TaskWarrior's built-in reports (`summary`, `burndown.*`, etc.) have no structured/export output of their own - `project_status` replicates `summary`'s math (remaining/completed/complete % per project, average task age) computed from raw exported tasks rather than shelling out to `summary` directly. Verified against a real `task` binary that the numbers match exactly. Graphical reports (`burndown.*`, `ghistory.*`) are intentionally not exposed - they're ASCII charts meant for terminal rendering, with nothing structured to hand an LLM.

`list_tasks`, `modify_task`, and `batch_modify_tasks` all accept User Defined Attributes (UDAs) - custom fields beyond TaskWarrior's built-ins, configured per-installation. `udas`/`udaFilters` take a list of `"name:value"` strings (e.g. `["staleness:fresh"]`), matching TaskWarrior's own attribute syntax, rather than a JSON object keyed by name - the MCP schema generator in use can't reliably distinguish a `name => value` map from a plain list, so a map-shaped parameter would advertise an unconstrained array schema and silently lose the name/value association for any client that followed it. Call `list_udas` first to see what's actually defined (name, type, and allowed values for constrained ones); every UDA name is checked against that list before being sent to TaskWarrior. This matters more than it sounds: TaskWarrior doesn't reject an unrecognized attribute name on `modify` - it silently reinterprets the whole `name:value` token as literal description text instead, which can overwrite a task's description. Validating UDA names ourselves turns that into a clear error.

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
