# Changelog — RegistryBundle

## Contents

- [1.0.0](#100)

## 1.0.0

Not released yet.

 * THE FACTS LEDGER. `figure_fact` — one row per subject, figure and period,
   written with `INSERT … ON CONFLICT DO UPDATE` — and `registry.facts.reader`
   (`FactReaderInterface`), which reads a month or a figure that does not add
   up from its own row and an additive figure's quarter or year as the sum of
   its months. Providers tagged `uhifadhi.facts` are collected; a figure
   declared by two is refused
 * `uhifadhi:facts:rebuild [--module=] [--subject=] [--from=] [--until=]` —
   the operator's idempotent recompute over a range of months
 * the recompute of the open periods is a task on the `default` schedule,
   hourly 06:00–20:00 and at 02:00 (`registry.facts.schedule`,
   `registry.facts.timezone`); the task queues `RecomputeOpenFacts` for the
   worker, which also computes a period that has just ended once more, and
   never a closed period again
 * requires `symfony/messenger`, `symfony/scheduler`, `symfony/clock` and
   `dragonmantank/cron-expression`

 * THE STATEMENT TIMEOUT OF A WEB REQUEST: `registry.statement_timeout_ms`
   (null by default: no middleware) registers `StatementTimeoutMiddleware` on
   every connection; a connection opened under a web server API runs
   `SET statement_timeout = <ms>` first, one opened by the console does not,
   and `0` is no limit
 * `messenger_messages`, the Doctrine transport's table, created by
   `Version20260925210000` (`IF NOT EXISTS`); requires
   `symfony/doctrine-messenger`
 * the `default` schedule needs no class of the installation's: the Scheduler
   builds it, and its `scheduler_default` transport, from the core's tasks;
   it is stateful on `cache.app`, runs only the last missed run — a recompute
   missed while the worker was down runs once on restart — and holds the
   framework's default lock; requires `symfony/lock`

 * A MODULE SAYS WHAT IT IS, in one line: `ModuleProviderInterface::description()`
   (null by the trait's default), kept on the catalogue row and printed under
   the module's name by the area's Modules section.

 * the module catalogue, the per-area install ledger and the parking gate
 * the permissions modules declare, collected for an installation to assign
 * `registry:sync`, the command that reconciles the catalogue with the installed module bundles and reports what it added, kept and retired — typed after the migrations and before the warm-up; a request reconciles nothing
