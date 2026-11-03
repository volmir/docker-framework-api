# Design decisions

This project is an API teaching example that can serve modest applications.
A feature should be understandable in about fifteen minutes. Keep a decision
only while it helps working functionality; revisit it when requirements change.

| Choice | Reason and tradeoff |
| --- | --- |
| Explicit construction, no DI container | index.php shows the global layers and creates Router with Connection; Router creates controllers with that connection. This makes dependencies visible without registrations or reflection. Constructors are intentionally limited; a larger dependency graph would require revisiting that construction code. |
| PDO, no ORM | Queries and schema changes stay visible next to the behavior they implement. Native prepares, explicit selected columns and MySQL exceptions make the users example easy to trace. Developers write SQL and mapping themselves; the code is MySQL-specific. |
| Domain directory for shared operations | HTTP and CLI share Users without calling controllers. The directory layout, dependency rules and extraction thresholds live in [Architecture](architecture.md). |
| Helpers have a concrete owner | Keep auxiliary operations with their existing responsibility; the placement table, naming rules and automated checks live in [Helpers](architecture.md#helpers). |
| Exact routes, no placeholders | A route is a METHOD/path table lookup, with parameters passed through query strings. See [the routing decision](#exact-match-routing-no-path-placeholders) for context, tradeoffs and when to revisit it. |
| Environment variables, no environment PHP files | Compose owns .env loading and passes variables to PHP. Config only reads $_ENV/getenv and validates types or required names. This avoids two configuration systems and a PHP dotenv dependency. Environment changes require container recreation, and existing MySQL credentials need database-side changes. src/routes.php is application code, not environment configuration. |
| Container streams, no file logger | Application JSON logs go to stderr; Docker collects them along with FPM and Nginx streams. The application needs no writable logs directory, file permissions or rotation code. Deployment must arrange retention and access controls. AccessLog excludes query/body data, but callers still must choose safe Logger messages/context. |
| Nginx owns rate limits and CORS | These checks happen before PHP workers and apply at the HTTP boundary. PHP middleware stays focused on application behavior. The configuration supports one origin and a per-peer-IP limit, not distributed user quotas or a dynamic CORS policy. Reverse-proxy real-IP trust requires deployment-specific configuration. |
| Minimal Composer policy | Runtime require is PHP and ext-* only; PHPUnit is development tooling. Commit the lockfile and install dependencies inside Docker. An added package must solve a concrete need worth its code, maintenance and teaching cost. |

The implementation has limits rather than hidden generalization. See
[README limitations](../README.md#limitations) for current HTTP, authentication,
migration and deployment boundaries, and the [cookbook](cookbook.md) for extensions.

## Exact-match routing, no path placeholders

### Context

This is a teaching project: a reader should understand the router in one minute.
`src/routes.php` reads as a table of HTTP methods, literal paths, controller
methods and optional middleware. Routing needs to serve the current endpoints
without introducing a pattern language.

### Decision

Compare the request path as a string, without placeholders. The HTTP method is
part of the route key, for example `GET /users/show`. Pass resource parameters
through the query string: `GET /users/show?id=1`; controllers validate
`$request->query` with `Validator`. Query values do not participate in matching.
`Request` uppercases the method and removes trailing path slashes before lookup.

### Consequences

- No regular expressions, route priorities or placeholder extraction are needed.
- The `routes` command lists the table directly, without expanding patterns.
- Missing paths return 404; a known path with another method returns 405 and an
  `Allow` header. The router computes these responses without throwing exceptions.
- Resource URLs are less conventional REST URLs: `/users/show?id=1` rather than
  `/users/1`. Identifiers live in query strings, which web-server/proxy access logs
  may retain. The application's `AccessLog` records only the path and omits query
  values; the bundled Nginx uses its default access format, which includes query.

### Alternatives considered

- Placeholders such as `/users/{id}`: an initial implementation might add roughly
  15 lines to `Router`, but requires compiling patterns into regular expressions,
  resolving priorities when literal and parameter routes overlap, and extending
  `routes --check` to validate patterns and detect conflicts.
- A separate routing library: rejected under the dependency policy, which permits
  only PHP and extensions in runtime Composer requirements. Current endpoints do
  not justify another dependency or its routing rules.

### When to revisit

Revisit this decision if at least three resources need nested paths. Base a change
on those concrete routes, including their conflict and validation requirements.

## Layers are earned, not assumed

### Context

A feature should be understandable in fifteen minutes. The legacy Item example
needed five files for one entity: ItemsController, Item, ItemService,
ItemRepository and ItemValidator. The service and repository still exchanged
arrays; more layers had not produced stronger data contracts. The current users
example needs shared operations because both HTTP and CLI actually consume them.

### Decision

Keep a controller as a thin HTTP adapter: Validator → logic → Response.
SQL may remain there through Connection's query methods while the logic has no
second consumer, as with HealthController's `SELECT 1`. Once another consumer needs the
operation, move its logic and SQL into a concrete domain class, as in
`App\Users\Users`, keeping validation and transport-specific responses in the
adapters. Commands call that class, never the controller.

Name domain classes for their meaning, without Service or Manager suffixes.
Domain exceptions such as `App\Users\EmailTaken` are allowed; map them to HTTP
in the controller. Do not introduce ORM/ActiveRecord, Repository interfaces or
Service/Repository pairs by default. Arrays with explicit PHPDoc remain the
current data contracts; no DTO layer is assumed.

### Consequences

- Fewer files and less forwarding code make the working behavior easier to trace.
- Logic containing SQL is covered by feature/integration tests on real MySQL:
  the HTTP tests in `tests/Feature/UserControllerTest.php` and the shared-domain
  and CLI tests in `tests/Users/`. There is no mocked repository layer.
- Array contracts require precise PHPDoc and tests. Extracting shared behavior
  means moving real logic and preserving its consumers, not adding empty layers.

### Alternatives

- Service + Repository for every entity: rejected because layers with no second
  consumer add files and indirection without solving the current sharing need.
- ORM/ActiveRecord: rejected under the dependency policy; runtime Composer
  requirements contain only PHP and extensions, and PDO already serves MySQL.

### When to revisit

- When `src/Controller` represents at least three domains, group controllers by
  domain within that directory; shared logic stays in `src/<Domain>/`.
- When the same array shape must be maintained independently in at least three
  places, introduce a readonly DTO to own that contract. Passing one domain
  result unchanged between adapters is not a new independent shape definition.

These are triggers to revisit the structure, not layers to generate in advance.
See [Architecture](architecture.md) for today's layout and dependency checks and
[the growing-controller recipe](cookbook.md#when-a-controller-grows) for extraction.
