# Using particle-academy/fancy-http-guard (Laravel) — guide for agents

How an agent installs and uses this package in a consumer app. What the package
is, is in [README.md](./README.md). Notes for working ON this repo's code are in
[CONTRIBUTING.md](./CONTRIBUTING.md).

## Install

```bash
composer require particle-academy/fancy-http-guard
```

- **Not on Packagist yet.** The command above works once it is published.
- Requires PHP `^8.4` (with `ext-json`, `ext-mbstring`) and Laravel 13
  components only. The service provider is auto-discovered. holy-sheet is
  optional, for workbooks.

```php
// bootstrap/app.php
use ParticleAcademy\HttpGuard\FancyHttpGuard;

->withMiddleware(fn (Middleware $middleware) => FancyHttpGuard::install($middleware))
->withExceptions(fn (Exceptions $exceptions) => FancyHttpGuard::exceptions($exceptions))
```

Settings: `php artisan vendor:publish --tag=fancy-http-guard-config`.

## Find it on the Fancy MCP server

Not listed on the Fancy MCP catalog server
(`https://ui.particle.academy/mcp`, MCP id `fancy-ui`) yet; it appears there
once published. Look for it later with `search-backend-packages` (stack `php`).
The Python twin is `fancy-http-guard` (FastAPI / Starlette), and the browser
side is `@particle-academy/fancy-http-errors`.

## API essentials

- **Per-route limits:** `Route::post(...)->guard('upload')` (a profile; `upload`
  is 10 MiB) or `->guard(['maxDepth' => 4])` (inline). The `routes` setting maps
  limits by route name.
- **Limits** (all settings, secure defaults): `maxBodyBytes` (1 MiB),
  `maxDepth` (32), `maxListLength` (10,000), `maxErrors` (20), `finiteNumbers`
  (true), `maxMessageLength` (300, at most 4,000), `workbook.maxRows` /
  `maxColumns` / `maxCells`. Also `profiles`, `routes`, `request_id_header`,
  `trust_incoming_request_id`.
- **A long public message:** `maxMessageLength` is how many code points of the
  app's own sentence a message keeps; more than 4,000 is refused at boot. For one
  long data-built refusal, set it on that error alone:
  `HttpError::public(422, 'rows_refused', $text, maxMessageLength: 2000)`, or a
  `ValidationFailed` item's `'maxMessageLength' => 2000`. An override over 4,000
  counts as 4,000 and reaches no other error or item.
- **The envelope:** every JSON error is `{error, message, errors?, limit?,
  retryAfter?, requestId}`, with `X-Request-Id` on every response.
- **A public 5xx:** `throw HttpError::public(503, 'maintenance', 'Back at 14:00
  UTC.', retryAfter: 600)`.
- **Workbooks:** `FancyHttpGuard::readWorkbook($bytes, field: 'file')`, or
  `$request->attributes->get('fancy_guard')->readWorkbook($bytes)` under the
  route's limits.
- **HTTP-client normaliser:** `$response->fancyError()` on a Laravel `Http`
  response returns `null` on a 2xx / 3xx, else an error with `sentence` and
  `fields`.

## Traps

- **Use `FancyHttpGuard::install()`.** It prepends the guard to the global
  middleware stack; `TrimStrings` and `ConvertEmptyStringsToNull` decode the
  body before any route middleware, so a guard added later checks a body already
  parsed.
- **Multipart and urlencoded bodies are parsed by PHP itself** under PHP-FPM;
  the guard checks only their `Content-Length`. Set `post_max_size` at or above
  your largest route cap, and nginx `client_max_body_size` above that.
- **A crash is a fixed 500, `APP_DEBUG` included.** The detail is in the log
  under the request id.
- **A 5xx never shows its message** unless thrown with `HttpError::public`. A
  message Laravel built from another exception (such as
  `ModelNotFoundException`) is not shown either; a policy's denial message is.
- **Returned responses are never rewritten**, so Inertia's 409s pass through.
  HTML pages and Inertia requests keep Laravel's own handling; a guard 422 on an
  Inertia request becomes a normal `ValidationException`.
- **A bad setting fails at boot**, or when a route declares it.
- **A longer message is not an unsanitised one.** `maxMessageLength` changes
  the length only: control characters are still stripped, a crash is still the
  fixed 500 and a non-public 5xx message is still never shown. Never put a path,
  a query or a stack trace in a public sentence on the strength of a raised
  limit.
- **The client normaliser has its own limit.** `$response->fancyError()` cuts a
  message at its `maxMessageLength` option (default 300) whatever the server
  sent; pass a larger one when you raised the server's.
- `trust_incoming_request_id` only behind a proxy that sets the header.
