# Cookbook

Run commands from the repository root. Start with the README's quick start.
PHP and Composer commands below always run in Docker. These recipes are examples
to add to your application, not additional routes shipped in the repository.
Try them on a disposable development database. Complete the notes migration
before calling the notes endpoint, seed or command.

## Start, stop or restart the service

Run these complete commands in the host terminal from the repository root:

```sh
make up
make down
make restart
```

`make up` builds/starts MySQL, PHP and Nginx and waits for healthy services.
`make down` stops and removes the stack's containers, including test-profile
containers; named database volumes remain. `make restart` recreates/starts the
whole application stack with the current `.env` and waits for health. It also
works after `make down`, preserving the existing database volume.
Use `make up` to rebuild images after Dockerfile changes. `make fresh` removes
database volumes and is intended for a disposable development database.

These are Make targets on the host. Application commands such as
`apikey:generate` and `make:controller` run through `bin/console` inside PHP.
For production, explicitly use the base Compose file, as shown in README:

```sh
docker compose -f compose.yaml up -d --force-recreate --wait
docker compose -f compose.yaml down
```

## Create your first API key

After the README's local quick start, `/health` is public, while `/users` and
generated controller routes require an API key. A request without a valid key
returns 401 with `WWW-Authenticate: ApiKey`; this is expected authentication behavior.

1. Generate a key in the running PHP container:

   ```sh
   docker compose exec -T php bin/console apikey:generate mobile
   ```

   The command prints one plaintext `mobile:key` entry. Save the key part
   (everything after the colon) securely for requests. Copy the whole line for
   server configuration; no hash is generated.

2. Edit `.env`: set `API_KEYS` to the complete output line. If entries
   already exist, append the new entry with a comma, preserving existing entries.
   `API_KEYS` stores plaintext credentials. Keep the key part in your client's
   secret store as well, and restrict `.env` permissions with `chmod 600 .env`. Do not paste real credentials into documentation or commit them.

3. Recreate PHP and Nginx to apply the changed environment:

   ```sh
   docker compose up -d --force-recreate --wait php nginx
   ```

4. In Bash, enter the plaintext key at a hidden prompt and make a request:

   ```bash
   read -r -s -p 'API key: ' API_KEY
   printf '\n'
   curl -i -H "X-Api-Key: $API_KEY" http://localhost:8082/users
   ```

   Paste only the key part after the colon, excluding the client label. This keeps
   it out of the command text and shell history. Expected: 200 and the users JSON.
   Keep using this Bash session for the examples below; repeat the hidden prompt
   when opening a new session. For existing clients, follow [rotation](#rotate-an-api-key).

## Add an endpoint

Run this complete Make command from the repository root:

```sh
make controller name=Greeting actions="show" routes=1
```

Make's `controller` target takes `name=`, `actions=` and the optional `routes=1`.
For example, `make controller name=Post actions="index show" routes=1` creates
the two protected posts routes. Omit `routes=1` to print entries for manual registration.
The equivalent complete console invocation for the greeting example is:

```sh
docker compose run --rm --no-deps php bin/console make:controller Greeting show --routes
```

`make:controller` is a `bin/console` subcommand; invoke it through the full
command above. Its controller name and actions are positional arguments, and
`--routes` is optional. Make uses a space in `make controller`.

It creates `src/Controller/GreetingController.php` and inserts its protected route
into `src/routes.php` with fully qualified class names, leaving imports alone.
The comment `// make:controller: new routes are inserted above this line` is a
permanent insertion marker in `src/routes.php`. The generator inserts entries
above it and leaves it in place for later runs. It is not a command or a missing
route. Keep its exact text and four-space indentation inside the array.

Existing route keys are skipped with a warning; repeating `--routes` reuses the
controller without overwriting it. Requested actions must already exist when
reusing a controller. Missing/ambiguous markers refuse before creating a new
controller and print manual entries. Updates preserve permissions and ownership,
use atomic rename, then run `php -l` and reload the table; failures restore the
original file. Production refuses this mode like the other generators.

For manual registration instead, omit `routes=1` (or `--routes`). Add
`use App\Controller\GreetingController;` to `src/routes.php` (ApiKeyAuth is
already imported), then paste the printed entry inside its route array:

<!-- snippet: greeting-route -->
```php
'GET /greetings/show' => [GreetingController::class, 'show', [ApiKeyAuth::class]],
```

Check the registration:

```sh
make routes-check
```

The generator edits routes only with `--routes` and never edits global middleware. It provides
only a working skeleton returning `{"data":[]}`; developers write SQL and
validation. `src/Controller/UserController.php` is the working example. The
constructor accepts `Connection $_connection` even when the endpoint does not use
the database, without storing an unused property. Promote it to a property when
adding SQL, as in UserController.
Change the generated `show` method to return a greeting:

<!-- snippet: greeting-controller -->
```php
public function show(Request $request): Response
{
    return Response::json(['data' => ['message' => 'Hello']]);
}
```

Paths are literal, without placeholders. Pass resource parameters through query
strings, for example `/users/show?id=1`, and validate `$request->query` with
`Validator::validate()` before using them (see [input validation](#validate-input));
do not add `/users/{id}` placeholders.

Before the request, complete [first API key setup](#create-your-first-api-key)
and use the same Bash session, where `API_KEY` holds the plaintext key. Generated
routes are protected by ApiKeyAuth, so omitting the header returns 401.

<!-- snippet: greeting-request -->
```sh
curl -i http://localhost:8082/greetings/show -H "X-Api-Key: $API_KEY"
```

Expected: 200 and `{"data":{"message":"Hello"}}`. Add a feature test through Router
for the new route, its authentication and validation; use
`tests/Feature/RouterTest.php` and [the controller test recipe](#test-a-controller-without-http)
as examples, then run `make test`. Development picks up mounted code automatically;
production needs rebuilt code and its authoritative autoload map.

## Write a middleware

Start with `make middleware name=ExampleHeader`, then edit the generated
`src/Middleware/ExampleHeader.php`. Registration is manual as described in the
following recipes. Middleware receives a Request and the
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
'GET /greetings/show' => [\App\Controller\GreetingController::class, 'show', [\App\Middleware\ApiKeyAuth::class, \App\Middleware\ExampleHeader::class]],
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
'POST /greetings' => [\App\Controller\GreetingController::class, 'create', [\App\Middleware\ApiKeyAuth::class, \App\Middleware\RequireJson::class]],
```

<!-- snippet: greeting-create-request -->
```sh
curl -i -X POST http://localhost:8082/greetings \
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

## Query the database

Use the existing `Connection` in a controller, domain or command. It opens PDO
only when needed; all three methods prepare, bind values and execute:

```php
$user = $connection->one('SELECT id, email FROM users WHERE id = :id', ['id' => 1]);
$users = $connection->all('SELECT id, email FROM users ORDER BY id LIMIT ? OFFSET ?', [5, 10]);
$affected = $connection->run('UPDATE users SET email = :email WHERE id = :id',
    [':email' => 'reader@example.com', 'id' => 1]);
```

`one()` returns the first associative row or null; `all()` returns a list of
associative rows, including `[]` when nothing matches. `run()` returns affected
rows: MySQL `ON DUPLICATE KEY UPDATE` reports 1 for an insert, 2 for a changed
update, and 0 if the existing row is unchanged. PDOException propagates unchanged,
including `errorInfo[1]` (1062 for a duplicate key).

Named parameter keys accept `id` or `:id`. Positional arrays start at index 0
and bind to positions starting at 1. PHP int, bool and null bind as PARAM_INT,
PARAM_BOOL and PARAM_NULL; other scalar values bind as PARAM_STR. Keep LIMIT and
OFFSET as ints, as in the example. Helpers inside `transaction()` use the same
connection, and debug mode counts their executions in Server-Timing.

Arrays and objects throw InvalidArgumentException. For `IN (...)`, generate one
placeholder per scalar value; passing `[$ids]` does not expand a list:

```php
$ids = [1, 2, 3];
$users = $ids === [] ? [] : $connection->all(
    'SELECT id, email FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id',
    $ids,
);
```

Handle the empty list explicitly. Only placeholder syntax is constructed here;
values stay in bound parameters, including strings containing quotes.

## Paginate a query

This example keeps SQL in a controller with one consumer. Use
[the domain recipe](#add-a-domain) when another consumer needs the operation.

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
        $notes = $this->connection->all('SELECT id, title, created_at FROM notes ORDER BY id LIMIT ? OFFSET ?', [$limit, $offset]);
        return Response::json(['data' => $notes, 'meta' => ['limit' => $limit, 'offset' => $offset]]);
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

## When to extract controller logic

Keep the controller as a thin HTTP adapter: validate input, call the operation,
and map its result or domain exception to HTTP. Extract logic into a concrete
class only when a second consumer needs it (a command, seeder or test); do not
call a controller from commands. The directory layout and dependency rules are
kept in [Architecture](architecture.md), together with the thresholds to review.

Users is the working example: UserController and the two commands call
`App\Users\Users`. It owns Argon2id hashing and SQL; `App\Users\EmailTaken`
is a RuntimeException beside the class. The controller maps it to 409; Console
prints its message and returns 1. Input format validation stays in the adapters.
The existing API tests exercise the same HTTP behavior after extraction.

### When a controller grows

Review extraction when a second consumer needs the logic, a transaction spans
multiple queries, or an action grows beyond roughly 40 lines. Complexity is a
reason to review the boundary, not to create Service/DTO/Repository files for
every entity. Follow [the layer decision](decisions.md#layers-are-earned-not-assumed).

The Users extraction is already implemented; use it as a step-by-step reference:

1. Preserve the existing HTTP contract with
   [UserControllerTest](../tests/Feature/UserControllerTest.php): validation errors,
   duplicate email, pagination, public fields and Location must keep their behavior.
2. Create one concrete [Users](../src/Users/Users.php) class accepting Connection.
   Move hashing, INSERT and SELECTs there as create/list/find. Keep Validator and
   Request/Response in the adapters; introduce no repository interface.
3. Put [EmailTaken](../src/Users/EmailTaken.php) beside Users. Convert MySQL error
   1062 to that exception and let other database failures propagate.
4. Make [UserController](../src/Controller/UserController.php) construct Users,
   validate input and delegate. Map EmailTaken to 409, a missing find result to
   404, and successful creation to 201 with Location.
5. Make [users:create](../commands/users-create.php) and
   [users:list](../commands/users-list.php) validate their CLI input and call the
   same Users methods directly; neither command constructs a controller.
6. Cover shared SQL and both commands in [tests/Users](../tests/Users/), and run
   `make check` without rewriting the existing API tests to accept changed behavior.

Users follows these steps today; HealthController keeps its single-consumer
`SELECT 1` through `Connection::one()`. No new layer or file is needed merely to follow this recipe.

```sh
docker compose exec php bin/console users:create a@example.com
docker compose exec -T php bin/console users:list --limit=20
```

The create command prompts with terminal echo disabled and restores the terminal
settings after reading. For automation, supply one password line on STDIN with
`docker compose exec -T`; read it from your secret store, never append it to the
command arguments or place a literal password in shell history. Repeating an
email reports `Email is already taken` and exits 1. Both commands print only
id/email/created_at; list defaults to 20 rows and accepts limits from 1 to 100.

## Add a domain

Start with the domain generator in the development bind mount:

```sh
make domain name=Notes
```

It creates `src/Notes/Notes.php` and `tests/Notes/NotesTest.php`, then prints
`make migration name=create_notes_table`. The constructor accepts Connection
without opening it or storing an unused property; the test extends DatabaseTestCase.
Names must be StudlyCase and meaningfully plural. Service/Repository/Manager
suffixes are rejected with a reminder to name the domain for its meaning.
Both paths are checked before writing; existing files and production are refused.

1. Confirm the working second consumer. Implement its operations in the generated
   final Notes class, storing Connection only when it is used. Add domain
   exceptions beside it when needed; they extend RuntimeException without HTTP
   knowledge. The generator supplies no SQL, exception hierarchy or TODOs.
2. Create the schema change with `make migration name=create_notes_table`, write
   up/down SQL and run `make migrate`; see [migrations](#add-a-migration).
3. Run `make controller name=Note actions="index show create"`. In its actions,
   validate query/body with Validator. Rename its unused `$_connection` argument
   to `$connection` and construct `new \App\Notes\Notes($connection)` in the
   constructor, as UserController constructs Users. Delegate operations and
   translate domain exceptions to HTTP; store Connection only if it is used.
4. Paste the generated imports/routes into `src/routes.php`, then run
   `make routes-check`. Add the second command/seeder by calling Notes directly;
   never construct NoteController there.
5. Extend the constructor test in `tests/Notes/`, using DatabaseTestCase and
   transaction rollback. Domain tests are discovered automatically; cover both
   adapters and run `make check`, including the architecture rules. Follow
   `tests/Users/UsersTest.php` and `tests/Users/CommandsTest.php`; CLI children use
   separate connections, so their tests also delete their own committed rows.

For an operation spanning multiple queries, use `Connection::transaction($fn)`
and pass the callback's Connection into the domain. It commits and returns the
callback result, or rolls back and rethrows the same Throwable. Nested or already
active transactions raise LogicException. DatabaseTestCase already starts a
transaction; tests exercising this helper use a separate Connection and clean up
their committed rows, as in `tests/Feature/TransactionTest.php`.

## Where to put a new helper

Use this decision tree; the canonical table and five rules are in
[Helpers](architecture.md#helpers).

```text
Only test setup?
├─ Yes → the test class, or tests/Support for shared fixtures.
└─ No → Only one class needs this implementation detail?
   ├─ Yes → a private method of that class.
   └─ No → Does an existing domain/infrastructure class own the operation?
      ├─ Yes → a method of that class (e.g. Console::routesTable).
      └─ No → Only a command, migration or seed needs it?
         ├─ Yes → a closure inside its returned array.
         └─ No → At least two actual consumers from different layers?
            ├─ Yes → App\<MeaningfulName>.
            └─ No → keep it beside its caller until sharing is needed.
```

Run `make check` after placing the code.

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
        $_ENV['API_KEYS'] = 'notes:' . hash('sha256', $key);
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

Entries in `API_KEYS` are plaintext `client:key` pairs separated by commas.
Client names contain 1–64 ASCII letters/digits/underscores/hyphens and start with
a letter or digit. Keys contain ASCII letters/digits/underscores/hyphens and are
case-sensitive; the generator produces 64 hex characters from 32 random bytes.
Whitespace around complete entries is allowed. Empty entries, trailing commas,
missing clients/keys and invalid characters reject the whole list.
The supplied key is compared directly with `hash_equals`, without hashing.
Use `env:check` before production deployment.

Rotate the mobile client's key:

1. Generate a replacement entry. It prints one `mobile:key` line; save the key
   part in the client secret store and copy the whole line for server setup.

   <!-- snippet: rotate-generate -->
   ```sh
   docker compose exec -T php bin/console apikey:generate mobile
   ```

2. Add the new entry alongside the old one in `.env` API_KEYS, separated by a
   comma, preserving other clients' entries. Recreate the containers:

   <!-- snippet: rotate-recreate -->
   ```sh
   docker compose up -d --force-recreate --wait php nginx
   ```

   Both keys now work. Update the client and enter the replacement key in Bash:

   <!-- snippet: rotate-request -->
   ```bash
   read -r -s -p 'API key: ' API_KEY
   printf '\n'
   curl -i -H "X-Api-Key: $API_KEY" http://localhost:8082/users
   ```

3. Remove only the old entry and repeat the recreate command. The old key now
   returns 401; the replacement and other clients' keys still return 200. A single
   PHP container restarts during recreation, although both keys work during cutover.

Successful authentication sets `$request->attributes['client']`. AccessLog adds
that name to its JSON line, including for controller failures. Client labels are
identifiers for service credentials, not per-user authorization roles. Missing
and incorrect keys both return the same 401 message and `WWW-Authenticate: ApiKey`.
An empty or malformed list fails closed and logs a warning without the key or
configuration value. Production assertSafe rejects that configuration at startup.

When migrating from `API_KEY_HASHES`, replace it with `API_KEYS` containing the
original plaintext keys or generate new entries. Existing SHA-256 digests cannot
recover the original keys; old-only configuration fails the required-variable check.

If production configuration is incomplete, regular CLI commands are blocked.
For the first key, build the prod image and run a disposable command without
HTTP ports: `docker compose -f compose.yaml run --rm --no-deps -e APP_ENV=local php bin/console apikey:generate mobile`.
Then finish .env and run env:check. For normal production rotation use
`docker compose -f compose.yaml` throughout, with APP_DEBUG=0 and a non-wildcard
CORS origin. Generation needs no database. API keys are unrelated to users'
Argon2id passwords; keep plaintext API keys only in the private .env/client secret store, never in Git or application logs.
