# Docker Framework API

A small PHP 8.4 JSON API for learning how an API works and building modest
services. The working example is a users API backed by PDO and MySQL 8.4 LTS.
Nginx and PHP-FPM run in Docker; PHP has no runtime Composer packages.

## Philosophy

Keep code and dependencies small. Aim to understand a feature in fifteen minutes.
Introduce an abstraction when working functionality needs it. Controllers receive
one explicit Connection; routes and commands are ordinary PHP arrays. There is
no DI container, ORM, dotenv parser or hidden application bootstrap.
Application PHP files use strict_types=1 and final classes. Composer PSR-4 maps
`App\` to `src/`; tests use `App\Tests\` mapped to `tests/`.

## Architecture

See [Architecture](docs/architecture.md) for domain ownership and dependency rules, and [Layers are earned, not assumed](docs/decisions.md#layers-are-earned-not-assumed) for when shared logic, readonly DTOs and additional layers are justified.
Choose where auxiliary functions belong using the [Helpers rules](docs/architecture.md#helpers).

## Requirements

Docker Engine or Docker Desktop with Docker Compose v2. The documented `make`
shortcuts also require Make on the host; the verification examples use curl.
PHP, Composer and MySQL run only inside containers. Do not install them locally.

## Quick start

From the repository root, run these five setup commands:

```sh
cp .env.example .env
make install
make up
make migrate
make seed
```

Then check the API:

```sh
curl -i http://localhost:8082/health
```

Expected: HTTP 200 and `{"status":"ok"}`. `make up` builds images and waits for
healthy containers. The demo seed creates Alice and Bob once, with random,
undisclosed passwords. Repeating `make seed` preserves existing users and hashes.
Protected routes initially return 401 because `API_KEY_HASHES` is empty; follow
the [API key recipe](docs/cookbook.md#rotate-an-api-key) to enable them.

`docker compose up -d` automatically reads `compose.yaml`, `compose.override.yaml`
and `.env`. The development override mounts the repository into PHP and exposes
MySQL at `127.0.0.1:33306`. The HTTP port defaults to 8082. Change the example
passwords in `.env` before using the service beyond a local demonstration.

## Project structure

Generated from the actual working tree with the following command; local tooling,
secrets, dependencies and caches are excluded. Empty `.gitkeep` files are shown.

```sh
tree -a --noreport -I '.git|vendor|.env|.phpunit.cache|.idea|.vscode|.agents|.aws|.codex'
```

<!-- PROJECT_TREE_START -->
```text
.
├── .docker
│   ├── nginx
│   │   ├── Dockerfile
│   │   ├── nginx.conf
│   │   └── templates
│   │       └── default.conf.template
│   └── php
│       ├── Dockerfile
│       ├── entrypoint.sh
│       ├── php-dev.ini
│       ├── php.ini
│       └── pool.conf
├── .dockerignore
├── .env.example
├── .gitignore
├── LICENSE
├── Makefile
├── README.md
├── SECURITY.md
├── bin
│   └── console
├── commands
│   ├── apikey-generate.php
│   ├── env-check.php
│   ├── make_command.php
│   ├── make_controller.php
│   ├── make_domain.php
│   ├── make_middleware.php
│   ├── make_migration.php
│   ├── migrate.php
│   ├── rollback.php
│   ├── routes.php
│   ├── seed.php
│   ├── status.php
│   ├── users-create.php
│   └── users-list.php
├── compose.override.yaml
├── compose.yaml
├── composer.json
├── composer.lock
├── database
│   ├── migrations
│   │   └── 20260101000000_create_users.php
│   └── seeds
│       └── users.php
├── docs
│   ├── architecture.md
│   ├── cookbook.md
│   ├── decisions.md
│   └── requests.http
├── phpstan.neon
├── phpunit.xml
├── public
│   └── index.php
├── src
│   ├── Config.php
│   ├── Console
│   │   ├── Console.php
│   │   └── Generator.php
│   ├── Controller
│   │   ├── HealthController.php
│   │   └── UserController.php
│   ├── Database
│   │   ├── Connection.php
│   │   ├── Migrator.php
│   │   ├── QueryLog.php
│   │   ├── Seeder.php
│   │   ├── TracedPdo.php
│   │   └── TracedStatement.php
│   ├── Errors.php
│   ├── Http
│   │   ├── HttpException.php
│   │   ├── Middleware.php
│   │   ├── Pipeline.php
│   │   ├── Request.php
│   │   ├── Response.php
│   │   ├── Router.php
│   │   └── Validator.php
│   ├── Log
│   │   ├── Logger.php
│   │   └── Redactor.php
│   ├── Middleware
│   │   ├── AccessLog.php
│   │   ├── ApiKeyAuth.php
│   │   ├── Debug.php
│   │   ├── ErrorHandler.php
│   │   ├── RequestId.php
│   │   └── RequireJson.php
│   ├── Users
│   │   ├── EmailTaken.php
│   │   └── Users.php
│   ├── middleware.php
│   └── routes.php
└── tests
    ├── Architecture
    │   └── DependencyRulesTest.php
    ├── DatabaseTestCase.php
    ├── Feature
    │   ├── .gitkeep
    │   ├── ConnectionTest.php
    │   ├── ConsoleTest.php
    │   ├── DebugTest.php
    │   ├── EnvironmentTest.php
    │   ├── GeneratorsTest.php
    │   ├── MigratorTest.php
    │   ├── RouterTest.php
    │   ├── RoutesCommandTest.php
    │   ├── ScaffoldingTest.php
    │   ├── SeederTest.php
    │   ├── StartupTest.php
    │   ├── TransactionTest.php
    │   └── UserControllerTest.php
    ├── Fixtures
    │   └── InputStream.php
    ├── Unit
    │   ├── .gitkeep
    │   ├── ConfigTest.php
    │   ├── ErrorsTest.php
    │   ├── MiddlewareTest.php
    │   ├── PipelineTest.php
    │   ├── RequestTest.php
    │   ├── ResponseTest.php
    │   ├── RoutesTest.php
    │   └── ValidatorTest.php
    └── Users
        ├── CommandsTest.php
        └── UsersTest.php
```
<!-- PROJECT_TREE_END -->

`src/routes.php` declares the route table; `src/middleware.php` returns the global
middleware class names, with Debug first when enabled. `public/index.php` composes the HTTP
pipeline; `bin/console` loads command files. `database/` contains PDO callables.
See the [cookbook](docs/cookbook.md), [decisions](docs/decisions.md) and
[REST Client / PhpStorm requests](docs/requests.http).

## Request lifecycle

```text
HTTP request
    |
    v
nginx (body limit, CORS preflight, rate limit)
    |
    v
public/index.php (Errors::register, Composer autoload, Config::boot, Request::fromGlobals)
    |
    v
global middleware: [Debug if enabled] -> ErrorHandler -> RequestId -> AccessLog
    |
    v
router (exact METHOD + path)
    |
    v
route middleware: e.g. ApiKeyAuth -> RequireJson
    |
    v
controller (new Controller(Connection), PDO opened only when needed)
    |
    v
Response <- route layers <- global layers <- index.php sends <- nginx
```

The first middleware is the outer layer. A layer can return early. Unknown paths
produce 404; a known path with a different method produces 405 and `Allow`.
Routes have no implicit HEAD or OPTIONS handlers. Nginx handles API preflight
with an empty 204 before PHP; direct `/index.php` requests are blocked.
`/nginx-health` is served directly by Nginx, outside the PHP route table.

Request headers are lowercase. Trailing slashes are removed, except for `/`.
Only `application/json` (case-insensitive, optional charset) is decoded, and its
root must be an object. Form data is ignored. Invalid JSON returns 400
`invalid_json`; input over 1 MiB returns 413. PHP reads at most 1 MiB plus a
one-byte probe; Nginx also enforces 1 MiB. Request attributes are immutable copies.

Application errors have this shape, with an object for empty details and a
request_id when request context is available:

```json
{"error":{"code":"validation_failed","message":"Validation failed","details":{"email":["Must satisfy rule: email"]},"request_id":"client-123"}}
```

Unexpected errors are logged. With `APP_DEBUG=0`, they return 500 `internal_error`
without diagnostics. Public HttpException field details are retained. Only
`APP_DEBUG=1` in local/test adds `class`, sanitized `message`, `file:line` (under `file`) and a
`trace` array to details. Trace arguments and objects are excluded.

`Errors::register()` runs before Composer in both entry points. It converts
reported PHP warnings/notices/deprecations into ErrorException, catches uncaught
exceptions, and reports fatal shutdown errors. HTTP failures are JSON; uncaught
CLI failures print a sanitized message to stderr and exit 1. It reserves a small
memory buffer and buffers HTTP output so a handled failure can discard partial
output. Error classes are preloaded so an autoload failure can also be reported.
Native duplicate PHP error output is disabled after registration; the handlers
write one sanitized application log instead.

## Environment

[.env.example](.env.example) lists the application and Compose settings. Docker
Compose reads `.env` and passes it to PHP with `env_file`; PHP never parses it.
All runtime environment reads go through `Config`, which reads `$_ENV`, then
`getenv()`. Missing values can use explicit defaults; `require()` reports all missing
names without values. A variable is present when set, including an empty string.
Typed getters validate input. `Config::all()` supplies the
same merged environment to the log redactor; application code does not read the
process environment directly.

`Config::env()` accepts only `local`, `test` and `production`; if APP_ENV is
missing, this getter defaults to production, but startup requires APP_ENV explicitly.
`isDebug()` always returns false in production, even when APP_DEBUG requests
diagnostics. Both entry points call `Config::boot()` after error registration and
autoloading, before constructing a Request or accessing the database. It checks
`Config::REQUIRED` first, then calls `assertSafe()`. Console `list`, `help`, `--help`,
`-h` and the default command skip boot so help works with an incomplete environment.
Production rejects enabled/invalid APP_DEBUG, an empty or malformed API_KEY_HASHES list,
empty/example database passwords and wildcard CORS. It reports all affected
variable names, never their values. local/test do not run those safety checks.
Changing `.env` requires recreating affected containers, not just restarting them.

| Variable | Required by boot | Example default | Used for |
| --- | --- | --- | --- |
| `APP_ENV` | Yes | `local` | local/test/production only; must be set at startup. Production enables safety checks and requires --force for seed/rollback. |
| `APP_DEBUG` | No | `1` | Boolean diagnostics in local/test. Production always hides them and refuses startup when enabled. |
| `APP_PORT` | No | `8082` | Host HTTP port mapped to Nginx port 80. |
| `TZ` | No | `UTC` | Container and PHP timezone. |
| `DB_HOST` | Yes | `db` | MySQL service name for the application connection. |
| `DB_PORT` | Yes | `3306` | MySQL port inside the Compose network. |
| `DB_NAME` | Yes | `app` | Database name; also `MYSQL_DATABASE` on initial MySQL setup. |
| `DB_USER` | Yes | `app` | Application user; also `MYSQL_USER` on initial setup. |
| `DB_PASSWORD` | Yes | `change-me` | Application password; replace it. |
| `DB_ROOT_PASSWORD` | No (required for safe production) | `change-me-too` | MySQL initialization root password; replace it. |
| `API_KEY_HASHES` | Yes (may be empty in local/test) | empty | Comma-separated client:sha256hex entries; empty/malformed denies all protected local/test requests and prevents production startup. |
| `CORS_ALLOW_ORIGIN` | Yes | `*` | Single Nginx Access-Control-Allow-Origin value; production rejects the wildcard. |
| `COMPOSE_PROJECT_NAME` | No | `docker_framework_api` | Compose resource and named-volume prefix. |
| `DB_HOST_PORT` | No | `33306` | Development-only MySQL port bound to 127.0.0.1. |
| `NGINX_BACKEND_DOMAIN` | No | `localhost` | Nginx server_name, despite the historical variable name; FastCGI uses `php:9000`. |

The required column describes PHP startup checks; Compose and MySQL also use the
infrastructure settings in this table.

### If the php container does not start

Inspect its status and stderr logs:

```sh
docker compose ps --all php
docker compose logs php
```

The entrypoint runs `bin/console env:check` before starting PHP-FPM. A missing
setting exits with code 1 and logs, for example, `Missing environment variables: DB_HOST`.
Restore the named variables in `.env` (copy their definitions from `.env.example`)
and fix any `Unsafe production configuration` names. Values are never printed.
Then recreate the service and its dependent Nginx:

```sh
docker compose up -d --wait
```

If logs say `run: make install`, install dependencies with `make install`, then
run the command above. With no vendor/autoload.php yet, the entrypoint prints
that hint and allows PHP-FPM to start. Composer installation and the tests service
bypass the preflight because their command is not `php-fpm`.
HTTP startup failures return JSON 500 without the variable list; only local/test
with APP_DEBUG=1 includes it in diagnostics. The full list is always in stderr.

MySQL initialization variables apply to a new data directory. Editing credentials
in `.env` does not update users in an existing database. Data lives in the named
volume `db_data`; `down -v` deletes it. API key hashes are separate from users'
Argon2id password hashes.

## Routes

Routes use exact method/path matching without placeholders; see [the routing decision](docs/decisions.md#exact-match-routing-no-path-placeholders) for the rationale and tradeoffs.

| Method and path | Middleware | Result |
| --- | --- | --- |
| `GET /health` | Public | 200 `{"status":"ok"}`; 503 `{"status":"down"}` if SELECT 1 fails. |
| `GET /users?limit=20&offset=0` | ApiKeyAuth | `{"data":[...],"meta":{"limit":20,"offset":0}}`. Default limit 20, allowed range 1–100; nonnegative offset. Invalid pagination (including limit > 100): 422. |
| `GET /users/show?id=1` | ApiKeyAuth | One user object. Missing or invalid positive integer ID: 422; missing user: 404. |
| `POST /users` | ApiKeyAuth, RequireJson | 201 `{"data":{"id":1,"email":"...","created_at":"..."}}` and `Location: /users/show?id=1`. |

User responses contain only `id`, `email` and `created_at`. Creation requires a
valid email of at most 255 characters and a string password of 8–256 Unicode
characters. There is no default password. [Validator rules](docs/cookbook.md#validation-rules)
return 422 `validation_failed` with lists of messages by field in details.
Duplicate email returns 409 `email_taken`. The password is hashed with
Argon2id and is never returned. An unsupported creation media type returns 415.

## Middleware and logs

| Middleware | Behavior |
| --- | --- |
| Debug | Local/test with APP_DEBUG=1 only: adds Server-Timing (pipeline duration, SQL execution time/count, peak memory) and X-Debug-Queries. Connection uses traced PDO; queries over 100 ms log their SQL template without bound values. Plain PDO is used otherwise, including production. See the [timing recipe](docs/cookbook.md#inspect-api-timing). |
| ErrorHandler | Preserves HttpException status/code/public details; logs handled exceptions with class, sanitized message, file:line and request_id. Other failures return 500 internal_error. Trace appears only when Config::isDebug() allows it in local/test. |
| RequestId | Accepts X-Request-Id matching `[A-Za-z0-9-]{8,64}`, or generates 16 random hex characters. Adds an attribute, response header and error.request_id. Errors registers the HTTP ID before request parsing too. |
| AccessLog | Emits `{ts,request_id,method,path,status,ms}` plus client when authenticated as one JSON line to PHP stderr, including controller failures. Excludes query strings and bodies. |
| ApiKeyAuth | Accepts X-Api-Key or Bearer authorization; compares SHA-256 against every client:hash entry with hash_equals without early exit, then sets the client request attribute. Missing/invalid keys have the same 401 message and WWW-Authenticate: ApiKey. Empty/malformed configuration denies everyone and logs a warning. |
| RequireJson | Requires application/json for POST, PUT and PATCH; otherwise returns 415. |

A nonempty X-Api-Key takes precedence over Authorization. There is no per-user
session or role system. `App\Log\Logger::info/warning/error` writes JSON context
to stderr; `write()` supports a flat access record. Redactor replaces known
DB_* values, environment credentials, request passwords and API/Bearer keys with
field names, and masks sensitive fields in log context. It does not serialize
arbitrary objects. Do not put arbitrary unlabeled secrets in exception messages:
redaction needs the secret to be known through its environment, input or label.
Nginx and FPM also emit their
own container logs, so not every line is application JSON.

```sh
docker compose logs php
docker compose logs nginx
```

Nginx limits PHP requests by client IP to 10 requests/second, with burst 20 and
`nodelay`; rejection is 429. Its CORS headers allow GET, POST, PUT, PATCH, DELETE,
OPTIONS and Content-Type, Authorization, X-Api-Key, X-Request-Id.

## API keys and rotation

Use `X-Api-Key`, with hyphens. `Authorization: Bearer` is the fallback when that
header is absent or empty. `X_API_KEY` is not supported: Nginx's default
`underscores_in_headers off` is retained.

API_KEY_HASHES contains comma-separated `client:sha256hex` entries. Client names
are case-sensitive, 1–64 ASCII letters/digits/underscores/hyphens, starting with
a letter or digit. Hashes are exactly 64 hexadecimal characters; the generator
prints lowercase and the parser also accepts uppercase. Spaces around entries
are trimmed; an empty entry, trailing comma or any malformed entry invalidates
the entire list. The previous bare-hash format is no longer accepted.
One client may appear several times for rotation. Use different credentials for
different clients; if a hash is assigned to multiple labels, the first matching
entry supplies the client. Labels do not implement roles or permissions.
Only `/health` is public in `src/routes.php`; every users route visibly lists
ApiKeyAuth. `/nginx-health` is infrastructure health served outside the app router.

Rotate a mobile key in three steps:

1. Generate and securely save the new plaintext key. The second output line is
   `mobile:<64-hex-hash>`, ready to add to API_KEY_HASHES; it is not the plaintext key.

   ```sh
   docker compose exec -T php bin/console apikey:generate mobile
   ```

2. Keep both old and new mobile entries in .env, separated by a comma, preserving
   other clients' entries. Recreate the containers, update the client, and verify
   the new key (set API_KEY to its plaintext value in your shell).

   ```sh
   docker compose up -d --force-recreate --wait php nginx
   curl -i -H "X-Api-Key: $API_KEY" http://localhost:8082/users
   ```

3. Remove only the old mobile entry, recreate again, and check that the old key
   returns 401 while the new key and other clients still work.

   ```sh
   docker compose up -d --force-recreate --wait php nginx
   ```

Both credentials work during step 2. This avoids a credential cutover gap; the
single PHP container still restarts when its environment is recreated. In
production, use `docker compose -f compose.yaml` for these commands and keep
production safety settings valid. See the [cookbook](docs/cookbook.md#rotate-an-api-key).

## Console and database tools

Run commands inside PHP, for example `docker compose exec php bin/console list`.
Colors are enabled only for a terminal. `list`, `help`, `--help`, `-h`, `routes`,
file generators and key generation work without a database connection.

| Command | Behavior |
| --- | --- |
| `list` / `help` | List commands; also the default with no argument. |
| `migrate` | Apply pending migrations in filename order. |
| `status` | Read applied/pending status; does not create the migrations table. |
| `rollback [batches] [--force]` | Undo latest batches, default one; production requires --force. |
| `seed [name] [--force]` | Run all seeders or one snake_case name; production requires --force. |
| `apikey:generate <client>` | Display a random 32-byte hex key once and a ready client:hash entry; stores neither. |
| `env:check` | Run Config::boot() without a database; exit 1 with the same missing or unsafe variable names as startup. |
| `users:create <email>` | Create a user through Users; password is read without terminal echo or from STDIN, never from arguments. Prints the public user as JSON. |
| `users:list [--limit=20]` | List public users as JSON, with a validated limit of 1–100. |
| `routes [--check]` | Show global middleware and an aligned METHOD / PATH / HANDLER / MIDDLEWARE table in route file order; --check reports configuration errors and exits 1. |
| `make:migration <name>` | Generate database/migrations/YYYYMMDDHHMMSS_name.php with empty up/down; name matches `[a-z][a-z0-9_]*`. |
| `make:command <name>` | Generate commands/name.php, replacing `:` with `_`; name is action or group:action, matching `[a-z][a-z0-9]*(:[a-z][a-z0-9_]*)?`. |
| `make:controller <Name> [action ...]` | Generate src/Controller/NameController.php; default action is index. Accepts StudlyCase or snake_case and removes a trailing Controller suffix; prints routes for manual registration. |
| `make:middleware <Name>` | Generate src/Middleware/Name.php; requires StudlyCase and prints global/per-route registration hints. |
| `make:domain <Name>` | Generate src/Name/Name.php and tests/Name/NameTest.php; use a meaningful plural StudlyCase name such as Posts, without Service/Repository/Manager suffixes. Prints a migration hint. |

Generators print the relative path, create files with mode `0644`, and never
overwrite existing files. Migrations use UTC timestamps; if the newest existing
timestamp is at or ahead of the current time, the next timestamp is one second
later. A migration name may only be generated once, regardless of timestamp.
Command generation rejects already registered names, including `list` and `help`.
All generators exit 1 in production or when the destination is not writable;
new directories use mode `0755`. Use the development bind mount through the Make
targets below; they explicitly run as the host's UID/GID so generated files belong
to the invoking user. Direct container commands use the image's default UID/GID
`1000:1000`. See the [endpoint](docs/cookbook.md#add-an-endpoint),
[middleware](docs/cookbook.md#write-a-middleware), [migration](docs/cookbook.md#add-a-migration)
and [command](docs/cookbook.md#add-a-console-command) recipes.
`make:domain` checks both destination files before writing either and removes its
new class file if writing the test fails. Plural meaning is chosen by the developer;
the generator validates syntax and naming suffixes, without guessing grammar.
Names reserved by the application's infrastructure and test suites are rejected.

Controller actions must match `[a-z][A-Za-z0-9]*` and be unique ignoring case.
The suggested path is the snake_case class name plus `s` (`BlogPost` becomes
`/blog_posts`). Index/show use GET, create uses POST, update uses PATCH, delete uses
DELETE, and other actions use GET with the action appended to the path. Show uses
`/<resource>/show` with query parameters. All suggested routes include ApiKeyAuth;
POST/PATCH suggestions also include RequireJson. Generators never edit
`src/routes.php` or `src/middleware.php`. They provide only a working skeleton;
developers add validation and SQL, using `UserController` as a working example.
The scaffold accepts `Connection $_connection` as Router requires, without storing
an unused property; promote it to a property when adding SQL.

`routes` reads `src/routes.php` and `src/middleware.php` without constructing
controllers or middleware or opening the database. `--check` verifies controller
classes and methods, global and route middleware classes and their implementation
of `App\Http\Middleware`, and duplicate literal keys in the returned route array.
It reads the source tokens because PHP overwrites duplicate array keys when
loading the file. Problem rows start with `!`; errors follow the table, one per
line. `-` means no route middleware; global middleware still apply. See the
[inspection recipe](docs/cookbook.md#inspect-route-middleware).

Migration files use `YYYYMMDDHHMMSS_snake_case.php` and callable `up`/`down`.
Migrate and rollback create the tracking table explicitly, under a per-database
MySQL advisory lock with a five-second wait. Rollback deletes each tracking row
immediately after its successful `down`. Seeder files use `snake_case.php` and a
callable `run`. Files and callable shapes are validated before execution.

`Connection::one()` returns a row or null, `all()` a list, and `run()` affected rows; all bind typed parameters through prepared statements (see [database queries](docs/cookbook.md#query-the-database)).

`Connection::transaction($fn)` passes the same Connection to the callback, commits
and returns its result, or rolls back an active transaction and rethrows the same
Throwable. Existing transactions are rejected with LogicException; there are no
nested transactions or savepoints. It uses `pdo()` in both normal and debug mode.

| Make target | Behavior |
| --- | --- |
| `help` | Show shortcuts; works without .env. |
| `install` | Build PHP and run composer install in a disposable container. |
| `up` / `down` | Build/start and wait for health / stop the stack, including test services. |
| `logs` / `sh` | Follow logs / open a PHP shell. |
| `migrate` / `status` | Invoke database commands. |
| `migration name=create_posts_table` | Generate an empty migration in the dev PHP container. |
| `command name=report:users` | Generate a console command in the dev PHP container. |
| `controller name=Post actions="index show"` | Generate a controller in dev and print protected routes to add manually. |
| `middleware name=Throttle` | Generate pass-through middleware in dev and print registration hints. |
| `domain name=Posts` | Generate a domain and its DatabaseTestCase constructor test in dev; print a migration hint. |
| `routes` / `routes-check` | Show routes / run routes --check in a disposable PHP container without starting MySQL. |
| `rollback ARGS="2"` | Roll back two batches. |
| `seed ARGS="users"` | Run the users seed. |
| `fresh` | Delete volumes, start, migrate and seed; destroys the database. |
| `test` / `lint` | Build/run the test profile / check PHP syntax. |
| `analyse` / `check` | Run PHPStan level 8 / run lint, analysis, routes --check and tests in order. |
| `prod-check` | Run env:check in a disposable PHP container with APP_ENV=production and no database dependency. |

Every Docker Make target requires `.env` and reports `cp .env.example .env` if
it is missing. Add `--force` through ARGS when required, for example
`make seed ARGS="users --force"`.

## Tests

Run `make analyse` for PHPStan level 8 in Docker (`composer analyse` inside the container). The analysis covers `src`, `bin`, `public`, `commands`, `database` and `tests`; all new code must pass level 8 without a baseline or `ignoreErrors`. Run `make check` to execute lint, analysis, `routes --check` and tests in order with one exit code; it stops at the first failure, including with `make -j check`.

```sh
make test
make lint
docker compose --profile test run --rm tests vendor/bin/phpunit --order-by=random --random-order-seed=20261102
docker compose --profile test run --rm tests vendor/bin/phpunit --order-by=reverse
```

Unit tests cover configuration, request parsing, responses, pipeline order and
middleware, early error registration, fatal shutdowns and secret redaction.
Feature tests pass Request through Router/Pipeline into Response,
without an HTTP server. All database tests use real MySQL, never SQLite.
`phpunit.xml` forces APP_ENV and DB settings to the isolated `db-test` service.
The Domains suite discovers `tests/<Domain>/*Test.php` automatically, including
generated constructor tests; Unit, Feature, Architecture and Fixtures are excluded
from that suite so existing tests are not run twice.
Its data directory is tmpfs and it has separate credentials and no host port.

`DatabaseTestCase` applies migrations and opens a transaction before each test;
teardown rolls back. Migrator tests instead drop every table before and after
each test because MySQL DDL commits implicitly. Tests cover seed idempotency,
partial rollback recovery, 404/405, health 200/503, users, auth and private 500s.
The input stream fixture tests JSON and the 1 MiB boundary in CLI.

`composer lint` checks `src`, `bin`, `public` and `tests` PHP files. `make lint`
also checks the extensionless `bin/console`. To include command and migration files:

```sh
docker compose exec -T php sh -c 'find commands database -name "*.php" -print0 | xargs -0 -n1 php -l'
```

## Production

See [SECURITY.md](SECURITY.md) for secret handling, key rotation, network exposure, HTTPS requirements and vulnerability reporting.

There is one `.env.example` and one local `.env`; no separate production env
file is used. Set `APP_ENV=production`, `APP_DEBUG=0`, new database passwords,
API key hashes and a non-wildcard CORS origin in `.env` before creating the
production database. If you need the first API key while production settings
are still incomplete, generate it in a disposable container without HTTP ports:

```sh
docker compose -f compose.yaml run --rm --no-deps -e APP_ENV=local php bin/console apikey:generate mobile
```

Build the prod image first if it is not present, and save only the printed hash
in .env. Every CLI command except help/list is gated by startup checks.
`env:check` reports the same failures as startup.
Run `make prod-check` after dependencies/images are available and before deployment;
it forces production even when .env currently selects local. On failure, the PHP
command exits 1 (Make reports a failed recipe with its own nonzero exit code).

PHP-FPM `/ping` checks the worker process. Before PHP-FPM starts, the entrypoint
validates required variables and production safety and exits 1 on failure.
HTTP and CLI entry points repeat the same check; `prod-check` runs it explicitly.

Explicitly select the base Compose file on every production command to exclude the development override:

```sh
docker compose -f compose.yaml build php nginx
docker compose -f compose.yaml run --rm --no-deps php bin/console env:check
docker compose -f compose.yaml up -d
docker compose -f compose.yaml exec -T php bin/console migrate
docker compose -f compose.yaml ps
```

Build first, including when replacing an image built for development. The prod
stage bundles code and no-dev vendor with authoritative autoloading; code changes
require rebuilding. It includes neither Composer nor PHPUnit. PHP runs as UID
1000 with a read-only root filesystem, temporary /tmp, and CPU/memory limits.
OPcache timestamp validation is off. MySQL is not published to the host.
Nginx still publishes APP_PORT over HTTP. Use your deployment's TLS termination
and backup procedures; this repository does not configure them.

FPM health uses FastCGI `/ping` via cgi-fcgi, with `clear_env=no`. MySQL health uses
an authenticated mysqladmin ping; Nginx health uses `/nginx-health`. These check
container services; `/health` performs the application database query.
The Makefile uses the automatic development override unless Compose is explicitly
configured otherwise; use the commands above for production.

## Dependency policy

`require` contains only PHP >=8.4, ext-pdo, ext-pdo_mysql and ext-json. PHPUnit and
PHPStan are the only direct require-dev packages. Commit composer.lock for
reproducible application installs. No runtime Composer packages are installed.
The Docker image supplies the needed extensions, including mbstring and Argon2
support; it compiles pdo_mysql. No ORM, PSR logger package or dotenv library is used.

## Limitations

- This is a small example, not a complete user-management or identity product.
  Client labels identify service credentials in attributes/logs; every client
  currently has the same access to protected routes. There is no
  login, per-user authorization, key expiry, password reset, update or delete API.
- Routes match exact methods and paths. Parameters use query strings; there are
  no placeholders, implicit HEAD handling or route groups.
- Input is JSON objects only, capped at 1 MiB. There is no form/file-upload API,
  streaming response API or schema validation package. Pagination has no total
  count or cursor; large offsets and unindexed new queries need separate care.
- Parsing failures occur before the middleware pipeline. Errors registers the
  request ID early, so they have JSON errors and matching header/body IDs, but
  they do not produce AccessLog records. Nginx-served responses bypass PHP and
  do not get its request ID. Its configured 404/413/429 pages use
  uppercase codes. Gateway 500/502/503/504 errors also return JSON with their
  original HTTP status; lower-level Nginx protocol rejections may still use HTML.
- CORS supports one configured origin value and no credentials or dynamic
  allowlist. No Access-Control-Expose-Headers is set, so cross-origin browser
  JavaScript cannot read Location or X-Request-Id by default. Rate limiting uses
  the peer IP; trusted reverse-proxy real-IP settings are not configured.
- Migration DDL is not transactional. A failed migration can leave partial
  schema changes; fix them before retrying. There are no checksums or automatic
  repairs. Seeders must implement their own idempotency. MySQL 8.4 is the supported
  database; PDO availability does not imply support for other database engines.
- Errors cannot recover from syntax/startup failures before register executes,
  or replace an HTTP status after headers have actually been flushed. Keep both
  entry points minimal; the shutdown handler covers fatal failures after setup.
- Images use moving branch tags rather than digest pins. TLS, backups, deployment
  orchestration, production monitoring and log retention are outside this repository.

## License

MIT. See [LICENSE](LICENSE).
