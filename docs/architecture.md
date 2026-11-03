# Architecture

A domain is `src/<Domain>/<Domain>.php`, with its exceptions beside it.
Controllers stay in `src/Controller`; commands never call controllers.

```text
src/
├── Controller/UserController.php
├── Users/Users.php + EmailTaken.php
└── Http/ Middleware/ Database/ Console/ Log/
```

| Location | Owns |
| --- | --- |
| `src/<Domain>/` | SQL, reusable operations, typed arguments/results and domain exceptions. |
| `src/Controller/` | Validator, domain calls, HTTP status/headers and exception mapping. |
| `commands/*.php` | CLI arguments/STDIN, Validator, domain calls and output; Console returns 1 on failure. |
| `src/Http`, `Middleware`, `Database`, `Console`, `Log` | Existing infrastructure; no container, ORM or repository interfaces. |
| `src/Config.php`, `Errors.php`, `routes.php`, `middleware.php` | Environment, error registration and explicit wiring. |
| `database/`, `tests/<Domain>/`, `tests/Architecture/` | Migrations/seeds, automatically discovered MySQL domain/CLI tests and dependency rules. |

## Dependencies

HTTP → controller → domain → Connection/PDO; CLI → command → the same domain.
Domains cannot reference `App\Http`, `App\Controller` or `App\Middleware`.
Domain-to-domain dependencies must be acyclic. Every top-level `src` directory
is a domain except Http, Middleware, Controller, Database, Console and Log.
Controllers cannot use PDO/prepare/query directly. HealthController keeps its
single-consumer `SELECT 1` through `Connection::one()`.
`tests/Architecture/DependencyRulesTest.php` checks imports/full names, ignores
comments/strings, and reports file:line plus cycle paths. Run `make check`.

## Users example

UserController constructs `new Users($connection)`, validates input and calls
`$this->users->create($data['email'], $data['password'])`; EmailTaken becomes 409,
success becomes 201 with Location. Users hashes the password, inserts and calls
find; list/find return public fields. `commands/users-create.php` validates and
reads the password without echo or from STDIN, then calls
`(new Users($connection))->create($data['email'], $data['password'])`.
`users:list [--limit=20]` calls Users::list. Neither command calls a controller;
results contain id/email/created_at, never password_hash.

## When to extract

Extract for a working second consumer (command, seeder, test); also review the
boundary for a multi-query transaction or an action beyond roughly 40 lines.
At three or more domains, review grouping related directories and update the
scanner if namespaces change. Keep today's flat directories until needed;
add no speculative interfaces. Follow the [domain recipe](cookbook.md#add-a-domain).

## Helpers

Choose an owner by the operation's meaning and its actual consumers:

| Belongs to | Put it in |
| --- | --- |
| One class's implementation | A private method of that class. |
| An existing domain or infrastructure responsibility | A method of its concrete class, such as `Users`, `Console` or `Response`. |
| A shared concern with at least two working consumers in different layers | `App\<MeaningfulName>`, only if no existing class owns it. |
| One console command, migration or seed | A closure inside that file's returned array. |
| Test setup only | The relevant test class; shared test fixtures may live in `tests/Support`. |

1. Keep a single-class detail private. For example, `Console::listCommands()`
   belongs to Console's implementation.
2. Reuse the existing owner before adding a class. Route inspection is console
   behavior: `commands/routes.php` calls `Console::routesTable()` and
   `Console::routeKeys()`. Both methods live in `src/Console/Console.php` and
   load through PSR-4; they need no manually required function file.
3. Extract a new `App\<MeaningfulName>` only for at least two actual consumers
   from different layers, such as HTTP and CLI. Tests alone or a possible future
   caller do not justify extraction. `App\Config` already serves HTTP and CLI.
4. Name the responsibility. Do not create common dumping grounds named
   Helper(s), Util(s), Support, Common or Misc; `tests/Support` is the test-only
   exception. Source file and class names must not end in Helper, Helpers, Util,
   Utils or Manager.
5. Declare no functions outside classes, including namespaced functions. Keep
   command callbacks as closures inside the command array. Do not register
   helper files through Composer `autoload.files`; keep it absent or empty.

`test_helpers_have_a_specific_owner()` in
`tests/Architecture/DependencyRulesTest.php` checks source names and directories,
class declarations, named function declarations at the beginning of a line in
`src/`, `bin/` (including `bin/console`), `public/` and `commands/`, and nonempty
`autoload.files`. Failures include file:line and point here. Run `make check`;
the [helper recipe](cookbook.md#where-to-put-a-new-helper) walks through placement.
