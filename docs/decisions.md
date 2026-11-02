# Design decisions

This project is an API teaching example that can serve modest applications.
A feature should be understandable in about fifteen minutes. Keep a decision
only while it helps working functionality; revisit it when requirements change.

| Choice | Reason and tradeoff |
| --- | --- |
| Explicit construction, no DI container | index.php shows the global layers and creates Router with Connection; Router creates controllers with that connection. This makes dependencies visible without registrations or reflection. Constructors are intentionally limited; a larger dependency graph would require revisiting that construction code. |
| PDO, no ORM | Queries and schema changes stay visible next to the behavior they implement. Native prepares, explicit selected columns and MySQL exceptions make the users example easy to trace. Developers write SQL and mapping themselves; the code is MySQL-specific. |
| Exact routes, no placeholders | A route is a METHOD/path table lookup, with parameters passed through query strings. 404 and 405/Allow need little code. There is no route parser, binding layer or automatic resource routing; nested path parameters would need deliberate new functionality. |
| Environment variables, no environment PHP files | Compose owns .env loading and passes variables to PHP. Config only reads $_ENV/getenv and validates types or required names. This avoids two configuration systems and a PHP dotenv dependency. Environment changes require container recreation, and existing MySQL credentials need database-side changes. src/routes.php is application code, not environment configuration. |
| Container streams, no file logger | Application JSON logs go to stderr; Docker collects them along with FPM and Nginx streams. The application needs no writable logs directory, file permissions or rotation code. Deployment must arrange retention and access controls. AccessLog excludes query/body data, but callers still must choose safe Logger messages/context. |
| Nginx owns rate limits and CORS | These checks happen before PHP workers and apply at the HTTP boundary. PHP middleware stays focused on application behavior. The configuration supports one origin and a per-peer-IP limit, not distributed user quotas or a dynamic CORS policy. Reverse-proxy real-IP trust requires deployment-specific configuration. |
| Minimal Composer policy | Runtime require is PHP and ext-* only; PHPUnit is development tooling. Commit the lockfile and install dependencies inside Docker. An added package must solve a concrete need worth its code, maintenance and teaching cost. |

The implementation has limits rather than hidden generalization. See
[README limitations](../README.md#limitations) for current HTTP, authentication,
migration and deployment boundaries, and the [cookbook](cookbook.md) for extensions.
