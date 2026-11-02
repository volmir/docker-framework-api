# Cookbook

Run commands from the repository root. Start with the README's quick start.
PHP and Composer commands below always run in Docker. These recipes are examples
to add to your application, not additional routes shipped in the repository.
Try them on a disposable development database. Complete the notes migration
before calling the notes endpoint, seed or command.

## Add an endpoint

Create `src/Controller/GreetingController.php`. The router passes its Connection
to every controller; this endpoint does not open it.

<!-- snippet: greeting-controller -->
```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Database\Connection;
use App\Http\Request;
use App\Http\Response;

final class GreetingController
{
    public function __construct(private Connection $connection)
    {
    }

    public function show(Request $request): Response
    {
        return Response::json(['data' => ['message' => 'Hello']]);
    }
}
```

Add this entry inside the array returned by `src/routes.php`. Fully qualified
class names work without adding imports. The path is literal, without placeholders.

<!-- snippet: greeting-route -->
```php
'GET /greeting' => [\App\Controller\GreetingController::class, 'show', [\App\Middleware\ApiKeyAuth::class]],
```

Use an API key configured as described in [rotation](#rotate-an-api-key). Set the
shell variable `API_KEY` to the plaintext key you saved; only its hash goes in .env.

<!-- snippet: greeting-request -->
```sh
curl -i http://localhost:8082/greeting -H "X-Api-Key: $API_KEY"
```

Expected: 200 and `{"data":{"message":"Hello"}}`. In development, the mounted
code is picked up automatically. Production needs a new image and authoritative
autoload map, so rebuild it after adding classes.

## Write a middleware

Create `src/Middleware/ExampleHeader.php`. Middleware receives a Request and the
next callable, then returns a Response. Return early to reject a request; call
`$next($request)` to continue. Request attribute changes use `withAttribute()`.

<!-- snippet: example-middleware -->
```php
<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Http\Middleware;
use App\Http\Request;
use App\Http\Response;

final class ExampleHeader implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        return $next($request)->withHeader('X-Example', 'enabled');
    }
}
```

## Attach route middleware

Replace the greeting entry with this one. Auth runs before ExampleHeader; a
rejected request never reaches it. Route middleware are constructed without
arguments. Read settings through `App\Config` when a middleware needs them.

<!-- snippet: greeting-route-middleware -->
```php
'GET /greeting' => [\App\Controller\GreetingController::class, 'show', [\App\Middleware\ApiKeyAuth::class, \App\Middleware\ExampleHeader::class]],
```

Repeat the greeting request: a successful response now has `X-Example: enabled`.
Other routes do not get the header from this route-specific registration.

## Attach global middleware

In `src/middleware.php`, replace the `$middleware` assignment with this line.
Keep ErrorHandler outside the other regular layers and keep the conditional
Debug registration below it. The first element is the outermost layer.
ExampleHeader is now reached for health, 404s and auth
failures too; request parsing errors still happen before the pipeline.

<!-- snippet: global-middleware -->
```php
$middleware = [ErrorHandler::class, RequestId::class, AccessLog::class, \App\Middleware\ExampleHeader::class];
```

The existing imports in middleware.php provide ErrorHandler, RequestId and AccessLog.
You can remove ExampleHeader from the route list when using it globally.

## Inspect route middleware

Run these commands to see which middleware apply to each route and validate the
configuration. They use a disposable PHP container and do not start MySQL.

<!-- snippet: inspect-routes -->
```sh
make routes
make routes-check
```

The first line shows `Global: ErrorHandler → RequestId → AccessLog` when debug is
off; Debug is prepended when `Config::isDebug()` is true. Global middleware run
before the route middleware shown in the table, in the displayed order.
`GET /health` has `-` in MIDDLEWARE, `GET /users` has `ApiKeyAuth`, and `POST /users`
has `ApiKeyAuth, RequireJson`. `-` means the route adds no middleware.

`make routes-check` runs `routes --check`: controller classes and methods must
exist, and global/route middleware must exist and implement `App\Http\Middleware`.
It also detects duplicate literal `METHOD /path` keys in `src/routes.php`, even
though PHP silently keeps the last value. Errors appear after the table, one per
line; affected rows start with `!`, and the console command exits 1. Successful
validation exits 0. `make check` includes this validation.

To try the failure path, temporarily change a controller class to
`\App\Controller\TypoController::class` in `src/routes.php` and run
`docker compose run --rm --no-deps php php bin/console routes --check`: the row is
marked `!` and the missing class is reported with exit code 1. Restore the class
afterwards. Neither listing nor validation constructs controllers or middleware.

## Inspect API timing

Set `APP_ENV=local` and `APP_DEBUG=1` in `.env`, then run
`docker compose up -d --force-recreate php nginx` so PHP receives the changed
environment. No additional package or browser toolbar is needed.

<!-- snippet: debug-timing-request -->
```sh
curl -i http://localhost:8082/health
```

Expected: `X-Debug-Queries: 1` and a header shaped like
`Server-Timing: total;dur=1.234, db;dur=0.123;desc="1 queries", mem;desc="2.00 MB peak"`.
Durations and memory depend on the request. In the browser's developer tools,
open Network, reload `/health`, select the request, and inspect Response Headers
and Timing (Server Timing where supported).

`total` measures the middleware pipeline, including error formatting and access
logging, rather than Nginx, request parsing or network time. `db` sums query(),
exec() and prepared execute() durations; each execution is counted once, including
failed executions. Preparing/fetching separately and opening the connection are
not timed as queries. The counter resets at the start of each debug request.
Memory is the PHP peak allocation in MiB (the header labels it MB).

Queries over 100 ms emit a warning with their SQL template and duration in
`docker compose logs php`. Bound parameter values are never recorded. Keep user
data in placeholders; literal values written into SQL remain part of its text.
Set `APP_DEBUG=0` and recreate PHP again: neither debug header is added and
Connection uses plain PDO. Production never enables Debug; `APP_DEBUG=1` in
production is also rejected by the existing startup safety check. Responses
generated before the pipeline or directly by Nginx do not have these metrics.

## Return an application error

Throw HttpException inside a controller or middleware. ErrorHandler preserves
its status, code, sanitized message, public field details and optional response
headers, and adds error.request_id when available. For
example, temporarily replace the body of `GreetingController::show()` with:

<!-- snippet: http-exception -->
```php
throw new \App\Http\HttpException(
    409,
    'example_conflict',
    'This operation conflicts with the current state',
    ['name' => 'Choose another name'],
    ['X-Example-Error' => '1'],
);
```

The greeting request returns 409, the uniform error object and X-Example-Error.
Restore its original body after trying this example. Reserve unexpected exceptions
for actual failures: they are logged and become 500. APP_DEBUG=0 hides diagnostic
details; public validation details remain. APP_DEBUG=1 in local/test adds sanitized class,
message, file and a trace array without arguments. Never put credentials in a
public exception message or details.

## Validate input

Add this method inside GreetingController. It validates before doing any work,
using the small `App\Http\Validator`. See the [rule list](#validation-rules)
for accepted types and optional fields. Use the returned array: extra input
fields are discarded.

<!-- snippet: greeting-create -->
```php
public function create(Request $request): Response
{
    $data = \App\Http\Validator::validate($request->body, [
        'name' => 'required|string|min:1|max:80',
    ]);
    return Response::json(['data' => ['message' => 'Hello, ' . $data['name']]], 201);
}
```

Add a route with authentication before the JSON media-type check:

<!-- snippet: greeting-create-route -->
```php
'POST /greeting' => [\App\Controller\GreetingController::class, 'create', [\App\Middleware\ApiKeyAuth::class, \App\Middleware\RequireJson::class]],
```

<!-- snippet: greeting-create-request -->
```sh
curl -i -X POST http://localhost:8082/greeting \
  -H "X-Api-Key: $API_KEY" -H 'Content-Type: application/json' \
  -d '{"name":"Ada"}'
```

Expected: 201. `{}` returns 422 with `error.code: validation_failed` and
`error.details.name: ["This field is required"]`; missing/wrong Content-Type
returns 415 after authentication. Malformed JSON returns 400 while building the
Request, before this route executes. No key returns 401 for a valid request body.

## Validation rules

`Validator::validate(array $data, array $rules): array` takes field names mapped
to pipe-separated rules. Rules run left to right; put type rules before bounds
or `in`. It returns only declared, present fields with converted int/bool values.
Missing optional fields are omitted, so apply defaults afterwards with `??`.
It does not trim strings or recursively validate nested fields.

| Rule | Behavior |
| --- | --- |
| `required` | Rejects missing fields, null, an empty string or an empty array. Accepts 0 and false. |
| `nullable` | Preserves explicit null and skips other checks, unless `required` rejects it. |
| `string` | Requires a string; no coercion. |
| `int` | Accepts PHP integers or integer strings accepted by FILTER_VALIDATE_INT; returns an int. Rejects floats, bools and overflow. |
| `bool` | Accepts bools, 0/1, and case-insensitive strings `true/false`, `1/0`, `yes/no`, `on/off`; returns a bool. Empty strings are invalid. |
| `email` | Validates a string with FILTER_VALIDATE_EMAIL. |
| `min:N`, `max:N` | Inclusive bounds: UTF-8 string length via mb_strlen, or integer value after `int`. |
| `in:a,b,c` | Case-sensitive match against a scalar's string representation; no escaping of commas. |

All failing fields are collected in HttpException(422, 'validation_failed',
'Validation failed'). Its details map each field to a list of messages. A failed
type check stops further checks for that field. Messages contain rule names,
never submitted values. Unknown rules are programming errors (500 through the
error handler).

## Paginate a query

Create `src/Controller/NoteController.php`. This uses the notes table from the
next recipe. Bound integer parameters work with native PDO prepares.

<!-- snippet: note-controller -->
```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Database\Connection;
use App\Http\Request;
use App\Http\Response;
use App\Http\Validator;
use PDO;

final class NoteController
{
    public function __construct(private Connection $connection)
    {
    }

    public function index(Request $request): Response
    {
        $query = Validator::validate($request->query, [
            'limit' => 'nullable|int|min:1|max:100', 'offset' => 'nullable|int|min:0',
        ]);
        $limit = $query['limit'] ?? 20;
        $offset = $query['offset'] ?? 0;
        $statement = $this->connection->pdo()->prepare('SELECT id, title, created_at FROM notes ORDER BY id LIMIT ? OFFSET ?');
        $statement->bindValue(1, $limit, PDO::PARAM_INT);
        $statement->bindValue(2, $offset, PDO::PARAM_INT);
        $statement->execute();
        return Response::json(['data' => $statement->fetchAll(), 'meta' => ['limit' => $limit, 'offset' => $offset]]);
    }
}
```

Add inside `src/routes.php`:

<!-- snippet: notes-route -->
```php
'GET /notes' => [\App\Controller\NoteController::class, 'index', [\App\Middleware\ApiKeyAuth::class]],
```

After the migration and seed below:

<!-- snippet: notes-request -->
```sh
curl -i 'http://localhost:8082/notes?limit=100&offset=1' -H "X-Api-Key: $API_KEY"
```

Expected: `meta.limit` is 100, `meta.offset` is 1, and the second seeded note is
returned. A limit above 100 returns 422 with `details.limit`; it is not clamped.
Pagination does not compute a total count.

## Add a migration

Generate the migration in the development environment:

<!-- snippet: notes-migration-generate -->
```sh
make migration name=create_notes
```

The command prints `database/migrations/YYYYMMDDHHMMSS_create_notes.php`. Its UTC
timestamp sorts after every existing migration, even if the clock moved back or
another file was created in the same second. A repeated name is rejected at any
timestamp. Names must match `[a-z][a-z0-9_]*`, for example `create_posts_table`.
Production and non-writable directories are rejected; use the dev bind mount.
Files have mode `0644` and belong to the container user; compare host `id` with
`docker compose run --rm --no-deps php id` if ownership differs.

Open the printed file and fill its empty up/down bodies as follows. Do not edit
an already applied migration. Both entries must remain callable. MySQL DDL is
not wrapped in a transaction.

<!-- snippet: notes-migration -->
```php
<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec('CREATE TABLE notes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(64) NOT NULL UNIQUE,
            title VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec('DROP TABLE notes');
    },
];
```

On the already migrated development database, this creates a new batch. Test its
rollback before inserting notes; rollback destroys the table and its contents.
Do not use this rollback example against a shared or production database.

<!-- snippet: notes-migration-commands -->
```sh
make migrate
make status
make rollback ARGS="1"
make migrate
```

Migrate/rollback hold a database advisory lock. If down fails, records for earlier
successful downs have already been removed; repair the failure and retry.

## Add an idempotent seeder

Create `database/seeds/notes.php`. Seeder names have no timestamp. A unique slug
makes repeated INSERT IGNORE runs safe and preserves any existing edited title.

<!-- snippet: notes-seeder -->
```php
<?php
declare(strict_types=1);

return [
    'run' => static function (PDO $pdo): void {
        $statement = $pdo->prepare('INSERT IGNORE INTO notes (slug, title) VALUES (?, ?)');
        $statement->execute(['first-note', 'First note']);
        $statement->execute(['second-note', 'Second note']);
    },
];
```

<!-- snippet: notes-seed-commands -->
```sh
make seed ARGS="notes"
make seed ARGS="notes"
```

There are still two notes. In production, seed requires an explicit --force;
use it only after reviewing the seed's effects.

## Add a console command

Generate the command in the development environment:

<!-- snippet: notes-command-generate -->
```sh
make command name=notes:count
```

The command prints `commands/notes_count.php`; `:` becomes `_` in the filename.
Names are `action` or `group:action`, matching
`[a-z][a-z0-9]*(:[a-z][a-z0-9_]*)?`. Registered names and existing files are never
overwritten. Production and non-writable directories are rejected with a dev
command hint. Generated files have mode `0644`.

Console discovers the returned command array automatically on its next run.
The template validates arguments, returns 1 for invalid input and 0 on success,
and shows a commented `$connection->pdo()` call: the database opens only when
that method is called. Replace the description and execute body to count notes:

<!-- snippet: notes-command -->
```php
<?php
declare(strict_types=1);

use App\Database\Connection;

return [
    'name' => 'notes:count',
    'description' => 'Count notes',
    'execute' => static function (array $args, Connection $connection): int {
        if ($args !== []) {
            throw new RuntimeException('Usage: notes:count');
        }
        $count = (int) $connection->pdo()->query('SELECT COUNT(*) FROM notes')->fetchColumn();
        printf("Notes: %d\n", $count);
        return 0;
    },
];
```

<!-- snippet: notes-command-run -->
```sh
docker compose exec -T php bin/console notes:count
```

Expected after seeding: `Notes: 2`. An unexpected argument produces a readable
error and exit code 1. There is no separate command registration file.

## Test a controller without HTTP

Create `tests/Feature/NotesExampleTest.php`. DatabaseTestCase applies migrations,
opens a real MySQL transaction and rolls it back after the test. Include the new
notes migration before running it. Do not do DDL inside the test transaction.

<!-- snippet: notes-test -->
```php
<?php
declare(strict_types=1);

namespace App\Tests\Feature;

use App\Http\Pipeline;
use App\Http\Request;
use App\Http\Router;
use App\Middleware\AccessLog;
use App\Middleware\ErrorHandler;
use App\Middleware\RequestId;
use App\Tests\DatabaseTestCase;

final class NotesExampleTest extends DatabaseTestCase
{
    public function test_notes_use_auth_and_paginate_without_an_http_server(): void
    {
        $environment = $_ENV;
        $key = bin2hex(random_bytes(16));
        $_ENV['API_KEY_HASHES'] = 'notes:' . hash('sha256', $key);
        try {
            $seed = require __DIR__ . '/../../database/seeds/notes.php';
            $seed['run']($this->pdo);
            $router = new Router(require __DIR__ . '/../../src/routes.php', $this->connection);
            $layers = [new ErrorHandler(), new RequestId(), new AccessLog()];
            $response = Pipeline::run($layers, new Request('GET', '/notes'), $router->handle(...));
            self::assertSame(401, $response->status);
            $request = new Request('GET', '/notes', ['limit' => '100', 'offset' => '1'], ['X-Api-Key' => $key]);
            $response = Pipeline::run($layers, $request, $router->handle(...));
            $body = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(200, $response->status);
            self::assertSame(['limit' => 100, 'offset' => 1], $body['meta']);
            self::assertCount(1, $body['data']);
            self::assertSame('Second note', $body['data'][0]['title']);
        } finally {
            $_ENV = $environment;
        }
    }
}
```

The test image bundles code, so rebuild after editing tests or migrations:

<!-- snippet: notes-test-commands -->
```sh
docker compose --profile test build tests
docker compose --profile test run --rm tests vendor/bin/phpunit tests/Feature/NotesExampleTest.php
```

## Rotate an API key

Use `X-Api-Key` with hyphens; `X_API_KEY` is dropped by Nginx's default header
policy, which is left unchanged. Bearer authorization is supported as a fallback.
A nonempty X-Api-Key takes precedence, so test one authentication header at a time.

Entries are `client:sha256hex`, separated by commas. Names contain 1–64 ASCII
letters/digits/underscores/hyphens and start with a letter or digit. A client can
have multiple keys; the same credential should not be assigned to different
clients. Whitespace around entries is allowed; trailing commas, missing clients,
non-hex or non-64-character hashes invalidate the entire list. Bare hashes from
the old format are rejected. Use `env:check` before a production deployment.

Rotate the mobile client's key in three steps:

1. Generate a replacement and save the plaintext key securely. It is 32 random
   bytes encoded as 64 hex characters and is displayed once. The second line is
   a complete `mobile:<hash>` entry; copy that entry into .env, not the key.

   <!-- snippet: rotate-generate -->
   ```sh
   docker compose exec -T php bin/console apikey:generate mobile
   ```

2. Add the new mobile entry alongside the old mobile entry in API_KEY_HASHES,
   separated by a comma, retaining other clients' entries. Recreate the containers
   to load the updated environment. Both mobile keys now work; update clients
   and verify the replacement. Set API_KEY to its new plaintext value.

   <!-- snippet: rotate-recreate -->
   ```sh
   docker compose up -d --force-recreate --wait php nginx
   ```

   <!-- snippet: rotate-request -->
   ```sh
   curl -i -H "X-Api-Key: $API_KEY" http://localhost:8082/users
   ```

3. Remove only the old mobile entry and repeat the recreate command. The old key
   must now return 401; the replacement and other clients' keys still return 200.
   This removes the credential cutover gap, although a single PHP container still
   restarts during recreation.

Successful authentication sets `$request->attributes['client']`. AccessLog adds
that name to its JSON line, including for controller failures. Client labels are
identifiers for service credentials, not per-user authorization roles. Missing
and incorrect keys both return the same 401 message and `WWW-Authenticate: ApiKey`.
An empty or malformed list fails closed and logs a warning without the key or
configuration value. Production assertSafe rejects that configuration at startup.

If production configuration is incomplete, regular CLI commands are blocked.
For the first key, build the prod image and run a disposable command without
HTTP ports: `docker compose -f compose.yaml run --rm --no-deps -e APP_ENV=local php bin/console apikey:generate mobile`.
Then finish .env and run env:check. For normal production rotation use
`docker compose -f compose.yaml` throughout, with APP_DEBUG=0 and a non-wildcard
CORS origin. Generation needs no database. API keys are unrelated to users'
Argon2id passwords; never store plaintext API keys in .env or application logs.
