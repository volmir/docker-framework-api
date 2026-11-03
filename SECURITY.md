# Security

## Secrets

`.env` is ignored by Git and excluded from the Docker build context by `.dockerignore`; the dev bind mount still exposes it at runtime.
On servers, restrict its permissions with `chmod 600 .env`; this is an operator responsibility.
`API_KEYS` contains comma-separated plaintext credentials in `client:key` format. Keys are compared directly and case-sensitively with `hash_equals`; no API-key hashing is performed.
`apikey:generate <client>` prints one `client:key` entry. Store the whole entry in `.env` API_KEYS and the key part in the client's secret store. Protect `.env` as a credentials file; anyone able to read it can use its API keys.
Send keys in `X-Api-Key` or `Authorization: Bearer` headers, never in query strings; authentication does not read query keys.

## Exposure

Do not expose MySQL: `compose.override.yaml` binds its dev port only to `127.0.0.1`; base `compose.yaml` publishes no database port.
PHP-FPM port 9000 is not published to the host; keep it private.
Build prod images with `docker compose -f compose.yaml build php nginx`; start production only with `docker compose -f compose.yaml up -d`, without the dev override.
Set `APP_ENV=production` and `APP_DEBUG=0`; `Config::assertSafe()` rejects enabled or invalid debug settings in production.
`GET /health` is intentionally public; its database check returns only `status: ok/down` (HTTP 200/503), without database error details.

## Key rotation

1. Generate a new key for the same client: `docker compose -f compose.yaml exec -T php bin/console apikey:generate <client>`; save the key part securely.
2. Append its `client:key` entry to `API_KEYS` in `.env`, separated by a comma; keep the old entry and other clients' entries.
3. Apply both keys: `docker compose -f compose.yaml up -d --force-recreate php`.
4. Switch the client to the new plaintext key and verify it works.
5. Remove only the old `client:key` entry from `API_KEYS`.
6. Apply the removal: `docker compose -f compose.yaml up -d --force-recreate php`.

If compromised, immediately remove the affected entry and recreate PHP as above; removing the last entry prevents production startup.

## Transport

The application and bundled Nginx do not terminate TLS; provide HTTPS through an external reverse proxy or load balancer. Never send API keys over plaintext HTTP.

## Before deployment

Run `make prod-check`: it checks required variables and production configuration safety without opening a database connection; it does not check TLS or network exposure.

## Reporting a vulnerability

On this GitHub repository, use **Security → Report a vulnerability** when private vulnerability reporting is enabled.
