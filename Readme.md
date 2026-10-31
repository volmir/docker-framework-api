# Minimal API Framework

This repository contains a small PHP API with a file-based console, PDO migrations and seeders, and exact-path routing. The API code uses PHP and PDO; the current Composer manifest also declares `psr/log` for the existing logger.

## Requirements

- PHP 8.4 or newer, with `pdo_mysql` and `json`. Tests also use `pdo_sqlite`; the test image includes `dom`, `intl`, `mbstring`, `xsl`, and `zip`, and Composer checks `fileinfo`.
- Composer 2 to install the project dependencies.
- Docker Engine and Docker Compose v5 or newer to run the configured containers.
- PHPUnit 12 for development tests.

## Philosophy

- Keep the HTTP and database path in PHP and PDO; the existing logger uses the declared `psr/log` package.
- Keep each class focused. Commands, migrations, and seeders are plain PHP files returning arrays.
- Add abstractions when a working feature needs them; do not build them for hypothetical features.
- Configure cross-cutting web concerns such as CORS and static files in Nginx.
- If a feature cannot be understood in 15 minutes, keep reducing its code.

## Quick start

1. Copy the Compose settings and Nginx template. Set `NGINX_BACKEND_DOMAIN=localhost` in `.docker/.env.nginx.local`:

   ```sh
   cp .env.example .env
   cp .docker/.env.nginx .docker/.env.nginx.local
   ```

2. Install Composer dependencies on the host. The project bind-mounts `vendor/` into the PHP container.

   ```sh
   composer install
   ```

3. Build and start the Compose stack:

   ```sh
   docker compose --env-file .env -f .docker/docker-compose.yml up -d --build
   ```

4. Create the users table and insert the sample users:

   ```sh
   docker compose --env-file .env -f .docker/docker-compose.yml exec php php bin/console migrate
   docker compose --env-file .env -f .docker/docker-compose.yml exec php php bin/console seed
   ```

5. Check the API on the host port configured by Compose:

   ```sh
   curl http://localhost:8082/health
   curl http://localhost:8082/users
   ```

   The health response is `{"status":"ok"}`. The users response contains the two seeded rows under `data`.

## Project structure

```text
.
├── .env.example                         # Compose interpolation values for local development
├── Makefile                              # Shortcuts for Compose and console commands
├── LICENSE                               # MIT license
├── composer.json                         # Composer dependencies and scripts
├── composer.lock                         # Locked Composer package versions
├── phpunit.xml                            # PHPUnit suites and bootstrap configuration
├── bin/console                            # CLI entry point
├── public/index.php                       # HTTP entry point
├── config/
│   ├── main.php                           # Database configuration by environment
│   └── routes.php                         # Exact path to controller/method map
├── commands/
│   ├── migrate.php                        # Apply pending migrations
│   ├── rollback.php                       # Roll back migration batches
│   ├── seed.php                           # Run seeders
│   └── status.php                         # Print migration status
├── database/
│   ├── migrations/20260101000000_create_users.php # Create and drop users
│   └── seeds/users.php                    # Insert two sample users
├── src/
│   ├── Auth/Guard.php                     # API token check used by legacy controllers
│   ├── Console/Console.php                # Discover and run file-based commands
│   ├── Controller/DefaultController.php   # Legacy default endpoint controller
│   ├── Controller/HealthController.php    # Database health endpoint
│   ├── Controller/ItemsController.php     # Legacy items API controller
│   ├── Controller/UserController.php      # User list, show, and create endpoints
│   ├── Core/Application.php               # Legacy application bootstrap
│   ├── Core/BaseController.php            # Legacy controller response and auth handling
│   ├── Core/Db.php                        # Legacy PDO wrapper
│   ├── Core/Environment.php               # Environment selection for legacy bootstrap
│   ├── Core/Log/Handler/HandlerInterface.php # Legacy log handler contract
│   ├── Core/Log/Handler/FileHandler.php   # Append log entries to a file
│   ├── Core/Log/Logger.php                # PSR logger implementation
│   ├── Core/Request.php                   # Legacy request parser
│   ├── Core/Response.php                  # Legacy response helper
│   ├── Core/Router.php                    # Legacy URL-to-controller router
│   ├── Core/Validator.php                 # Legacy validation base class
│   ├── Database/Migrator.php              # Apply, roll back, and inspect migrations
│   ├── Database/Seeder.php                # Run one or all seeders
│   ├── Http/Request.php                   # Current HTTP request value object
│   ├── Http/Response.php                  # Current HTTP response helper
│   ├── Http/Router.php                    # Exact-path controller dispatcher
│   ├── Logger/LoggerAdapter.php           # Configure the legacy file logger
│   ├── Model/Item.php                     # Legacy item model
│   ├── Repository/ItemRepository.php      # Legacy item database operations
│   ├── Service/ItemService.php            # Legacy item application logic
│   └── Validator/ItemValidator.php        # Legacy item input validation
├── tests/
│   ├── Feature/
│   │   ├── ApiUsersFeatureTest.php         # Legacy router 404 behavior
│   │   ├── ConsoleTest.php                 # Command registration and dispatch
│   │   ├── MigratorTest.php                 # Migration apply, rollback, and status
│   │   ├── RouterTest.php                   # Exact routes and controller responses
│   │   └── SeederTest.php                   # Seeder selection and execution
│   └── Unit/
│       ├── GuardTest.php                    # API token checks
│       ├── ItemRepositoryTest.php            # Item repository behavior
│       ├── RequestTest.php                   # Legacy request body parsing
│       └── ValidatorTest.php                 # Validator behavior
├── docs/
│   ├── db_dump.sql                          # Database export
│   └── No Framework API.postman_collection.json # Postman request collection
└── .docker/
    ├── docker-compose.yml                  # nginx, php, and db services
    ├── php/
    │   ├── Dockerfile                       # PHP-FPM image
    │   └── php.ini                          # PHP runtime settings
    ├── nginx/
    │   ├── Dockerfile                       # Nginx image
    │   ├── nginx.conf                       # Nginx configuration
    │   └── templates/default.conf.template  # Routes requests to PHP-FPM
    └── tests/
        ├── Dockerfile                       # PHPUnit CLI image
        ├── Readme.md                        # Test-container notes
        └── docker-compose.test.yml           # PHPUnit container and test database
```

The repository currently contains 28 PHP files under `src/`, five feature tests, and four unit tests. `src/Core`, `src/Controller/ItemsController.php`, and `src/Auth/Guard.php` are retained legacy code; the HTTP entry point uses `src/Http/Router.php` and the controllers in `config/routes.php`.

## Console

Run a command in the configured PHP service:

```sh
docker compose --env-file .env -f .docker/docker-compose.yml exec php php bin/console <command> [args]
```

`bin/console` with no command or with `list` prints registered commands. `--help` and `-h` also print the list. The available commands are `migrate`, `rollback`, `status`, and `seed`. Command-specific arguments are positional. The integer returned by a command becomes the process exit code; the built-in commands return `0` on success, while an unknown command or a caught command error returns `1`.

Each command file returns a `name`, `description`, and `execute` callable. `commands/seed.php` is a complete example; a new command uses the same file and array shape.

```php
<?php
declare(strict_types=1);

return [
    'name' => 'seed',
    'description' => 'Run seeders (all or one by name)',
    'execute' => function (array $args): int {
        /** @var PDO $pdo */
        $pdo = require __DIR__ . '/../src/bootstrap.php';
        $seeder = new App\Database\Seeder($pdo, __DIR__ . '/../database/seeds');
        $name = $args[0] ?? null;
        $ran = $seeder->run($name);
        foreach ($ran as $seedName) {
            echo "\033[32mSeeded: {$seedName}\033[0m\n";
        }
        return 0;
    },
];
```

`src/Console/Console.php` discovers files matching `commands/*.php` with `glob()`. The CLI entry point loads `src/bootstrap.php` before dispatching, so the database connection is also initialized before the command list is printed.

## Migrations

Migration files use `database/migrations/YYYYMMDDHHMMSS_<snake_case>.php` and return `up` and `down` callables that receive PDO. Files are applied in filename order. `migrate` applies pending files, `rollback [n]` rolls back the latest `n` batches (one by default), and `status` prints each discovered file as applied or pending. The `migrations` table is created when the `Migrator` is constructed by one of these commands.

The current example migration is:

```php
<?php
declare(strict_types=1);

return [
    'up' => function (PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE users (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    },
    'down' => function (PDO $pdo): void {
        $pdo->exec("DROP TABLE users");
    },
];
```

Migration execution has no transaction. A migration is recorded only after its `up` callable and the record insert complete; earlier successful migrations remain recorded if a later migration fails. Database DDL may have taken effect before a failure.

## Seeders

Seeder files use `database/seeds/<name>.php` and return a `run` callable that receives PDO. `seed` runs every PHP file in filename order; `seed <name>` runs only `database/seeds/<name>.php`.

The current users seeder is:

```php
<?php
declare(strict_types=1);

return [
    'run' => function (PDO $pdo): void {
        $statement = $pdo->prepare(
            'INSERT INTO users (email, password_hash) VALUES (?, ?)'
        );
        $statement->execute(['alice@example.com', password_hash('secret', PASSWORD_ARGON2ID)]);
        $statement->execute(['bob@example.com', password_hash('secret', PASSWORD_ARGON2ID)]);
    },
];
```

The seeder does not enforce idempotency. A seeder that must be repeatable has to implement that behavior itself.

## Routing

`config/routes.php` is an associative map from exact URL path to `Controller/method`. The router resolves a controller to `App\Controller\<ControllerName>` and constructs it with PDO. There are no path placeholders; pass dynamic values in the query string. The router does not dispatch by HTTP method. `UserController::create` checks for `POST` and returns `405` with an `Allow: POST` header for other methods. Unknown paths receive a router-generated `404` response.

The complete route map is:

```php
<?php
declare(strict_types=1);

/**
 * Route map: exact URL path => "ControllerName/methodName".
 * ControllerName resolves to App\Controller\<ControllerName>.
 * Paths must match exactly (no placeholders). Use query string for parameters.
 */
return [
    '/health' => 'HealthController/index',
    '/users' => 'UserController/index',
    '/users/create' => 'UserController/create',
    '/users/show' => 'UserController/show',
];
```

`src/Controller/UserController.php` implements all three user routes:

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;

final class UserController
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function index(Request $req): Response
    {
        $rows = $this->pdo->query(
            'SELECT id, email, created_at FROM users ORDER BY id'
        )->fetchAll();
        return Response::json(['data' => $rows]);
    }

    public function show(Request $req): Response
    {
        $id = $req->query['id'] ?? null;
        if ($id === null || !ctype_digit((string) $id)) {
            return Response::json(['error' => 'id required'], 400);
        }
        $statement = $this->pdo->prepare(
            'SELECT id, email, created_at FROM users WHERE id = ?'
        );
        $statement->execute([(int) $id]);
        $user = $statement->fetch();
        return $user
            ? Response::json($user)
            : Response::json(['error' => 'Not Found'], 404);
    }

    public function create(Request $req): Response
    {
        if ($req->method !== 'POST') {
            return new Response('Method Not Allowed', 405, ['Allow' => 'POST']);
        }
        $email = $req->body['email'] ?? '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'valid email required'], 400);
        }
        $password = $req->body['password'] ?? 'secret';
        $statement = $this->pdo->prepare(
            'INSERT INTO users (email, password_hash) VALUES (?, ?)'
        );
        $statement->execute([$email, password_hash($password, PASSWORD_ARGON2ID)]);
        return Response::json(['id' => (int) $this->pdo->lastInsertId()], 201);
    }
}
```

To add an endpoint:

1. Add the new exact path and its `Controller/method` target to the returned array in `config/routes.php`.
2. Add that method to the target class under `src/Controller/`. The controller constructor must accept PDO, and its method receives `Request` and returns `Response`. `src/Controller/UserController.php` above shows the complete method pattern.

The demo routes can be called with:

```sh
curl http://localhost:8082/health
curl http://localhost:8082/users
curl 'http://localhost:8082/users/show?id=1'
curl -X POST http://localhost:8082/users/create \
  -H 'Content-Type: application/json' \
  -d '{"email":"a@b.com","password":"secret"}'
```

## Request and Response

`Request::fromGlobals()` builds a request from `$_SERVER`, `$_GET`, `$_POST`, and `php://input`. The request exposes `method`, `path`, `query`, and `body`. JSON bodies are decoded to arrays; non-JSON bodies use `$_POST`.

`Response::json($data, $status = 200)` encodes JSON and sets the JSON content type. `Response::text($body, $status = 200)` creates a plain-text response. `Response::send()` sends the status, headers, and body.

## Configuration

`.env.example` supplies values interpolated by Docker Compose: `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `TIMEZONE`, and `COMPOSE_PROJECT_NAME`. The `MYSQL_*` values configure the MySQL service, `TIMEZONE` is passed to the PHP image build, and `COMPOSE_PROJECT_NAME` names the Compose project. The PHP connection credentials themselves are in `.env`; the `develop` values currently match the example database, user, and password.

`src/bootstrap.php` does not parse `.env`. It loads Composer's autoloader and selects a database configuration from `.env`. On CLI, `APP_ENV` selects the config key when set; otherwise the environment defaults to `develop` (an unknown key also falls back to `develop`). For HTTP, the legacy `Environment::get()` selects `develop` for its listed server addresses and `production` otherwise.

## Docker

The current Compose file is `.docker/docker-compose.yml`. It defines `db` (`mysql:9.7`), `php` (built from PHP 8.4.27 FPM Alpine), and `nginx` (built from Nginx 1.30.6 Alpine). Nginx serves `public/`, uses `try_files` to route requests to `index.php`, and sends FastCGI requests to `php:9000`. The host port is `8082`. MySQL data is bind-mounted at `.docker/mysql/db`.

Useful commands:

```sh
docker compose --env-file .env -f .docker/docker-compose.yml up -d
docker compose --env-file .env -f .docker/docker-compose.yml exec php php bin/console status
docker compose --env-file .env -f .docker/docker-compose.yml down
```

`docker compose down -v` does not remove the MySQL bind-mounted directory.

## Testing

Run the PHPUnit test service with:

```sh
docker compose --env-file .env -f .docker/tests/docker-compose.test.yml run --no-deps --rm tests
```

The test container runs `composer install` and `composer test`.

- `ConsoleTest` covers command discovery, dispatch, list output, missing commands, and invalid command files.
- `MigratorTest` covers migrations with SQLite in-memory, including apply, repeated apply, rollback, and status.
- `SeederTest` covers running all seeders, selecting one, and the missing-seeder exception.
- `RouterTest` covers exact route dispatch, controller errors, status responses, and path/query behavior.
- `ApiUsersFeatureTest` covers a 404 response through the legacy `Core\Router`.

## Limitations

- There is no DI container. The current Router constructs controllers with PDO.
- There is no PSR-7 or PSR-15 implementation, middleware pipeline, attribute routing, route grouping, or URL generation.
- Route matching is exact; use query strings for dynamic values.
- The current Router does not select routes by HTTP method. Controllers check methods themselves.
- There is no ORM or query builder. Database access uses PDO.
- Migrations do not run inside transactions.
- Input checks are implemented in individual controllers; there is no shared validation layer for the new routes.
- The new route map does not invoke the legacy `Auth\Guard`; the demo endpoints have no authentication check.
- HTTP database configuration depends on `$_SERVER['SERVER_ADDR']` matching the development address list. An unlisted address selects the production configuration in `.env`.
- PHP does not implement CORS or rate limiting. The current Nginx template adds permissive CORS headers; it does not configure rate limiting.
- The project includes a file logger and PHP-FPM stdout/stderr logging; it does not configure centralized log collection.
- The MySQL database is stored in a bind-mounted directory. `make fresh` runs `down -v`, but this does not clear that directory; subsequent seed runs may encounter duplicate user emails.
- `composer.json` declares `psr/log` as a runtime dependency, so the current repository does not have zero runtime dependencies.

## License

MIT. See `LICENSE`.
