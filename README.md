# particle-academy/fancy-http-guard

Request guards for **Laravel**, checked **before the body is parsed**, plus one
error envelope with a request id on every response, and an HTTP-client
normaliser for the errors your app receives.

```bash
composer require particle-academy/fancy-http-guard
```

```php
// bootstrap/app.php
use ParticleAcademy\HttpGuard\FancyHttpGuard;

->withMiddleware(fn (Middleware $middleware) => FancyHttpGuard::install($middleware))
->withExceptions(fn (Exceptions $exceptions) => FancyHttpGuard::exceptions($exceptions))
```

```php
// routes/api.php
Route::post('/imports', ImportController::class)->guard('upload');        // 10 MiB here
Route::post('/rates', RateController::class)->guard(['maxDepth' => 4]);   // inline limits
```

<!-- fancy-agents-note:start -->
> **For agents.** This package is not listed on the Fancy MCP catalog server
> yet. The server, `https://ui.particle.academy/mcp` (streamable HTTP, no API key; MCP id
> `fancy-ui`), lists the released Fancy packages with install commands and
> docs. How an agent installs and uses this package is in [AGENTS.md](https://github.com/Particle-Academy/fancy-http-guard/blob/main/AGENTS.md).
<!-- fancy-agents-note:end -->

## Before the body is parsed

`install()` PREPENDS the guard to the global middleware stack. That matters:
`TrimStrings` and `ConvertEmptyStringsToNull` are global middleware that decode
the whole JSON body before any route middleware runs. To honour per-route limits
anyway, the guard asks the route collection which route will serve the request.

| Check | Answer |
|---|---|
| `Content-Length` over the route's cap | **413, and not one body byte is read** |
| a missing or lying `Content-Length` | 413 once `maxBodyBytes + 1` bytes are read; the rest is never pulled |
| a malformed `Content-Length` | 400 |
| nesting deeper than `maxDepth` | 422 `too_deep`, naming the field; the scan stops there |
| an array OR object with more than `maxListLength` members | 422 `list_too_long`; the scan stops there |
| `NaN`, `Infinity`, `1e400` (which `json_decode` silently makes `INF`) | 422 `not_finite`, naming the field: `items.3.price` |
| not JSON at all | 400 `malformed_body` |

The scan is one pass over the bytes with no tree built. A body that passes
reaches your controller byte for byte.

**What PHP does before any PHP runs.** Under PHP-FPM, `multipart/form-data` and
urlencoded bodies are parsed into `$_FILES` / `$_POST` by PHP itself, governed by
`post_max_size`. For those, the guard checks only `Content-Length`. Set
`post_max_size` at or above your largest route cap, and nginx
`client_max_body_size` above that. Under Octane the body is already in memory,
so the cap protects the parse, not the read.

## The envelope

Every JSON error is one shape, with the request id in the body and the
`X-Request-Id` header (on every response, a pass included, and in `Context`, so
every log line carries it):

```json
{"error":"validation_failed","message":"2 fields need attention.",
 "errors":[{"error":"not_finite","message":"This must be a finite number.","field":"items.3.price"},
           {"error":"invalid","message":"The email field is required.","field":"email"}],
 "requestId":"4f8c2a9e-..."}
```

- **A crash is a fixed 500 in every environment, `APP_DEBUG` included:**
  "Something went wrong on our side. If it keeps happening, quote reference
  4f8c2a9e-...". The detail is in the log Laravel already wrote, under that id.
- **A deliberate 4xx keeps your sentence:** `abort(410, 'This invite has
  expired.')`. A message Laravel made from another exception is NOT shown, since
  a `ModelNotFoundException` names your model class; a policy's denial message is.
- **A 5xx never shows its message**, unless you declare it public:
  `throw HttpError::public(503, 'maintenance', 'Back at 14:00 UTC.', retryAfter: 600)`.
- 429 and 503 carry `retryAfter` and `Retry-After`. Validation becomes `errors[]`.
- Responses your app RETURNS are never rewritten, so Inertia's 409s pass through.
- **HTML pages and Inertia requests keep Laravel's own handling.** A guard 422 on
  an Inertia request becomes a native `ValidationException` after the session
  starts, so the form gets flat `props.errors`. An oversize Inertia body is
  still refused immediately.

## Workbooks

Rows x columns cannot be checked from the raw bytes, because an xlsx's
`<dimension>` is a claim the file makes about itself. The guard sets the limits
and [holy-sheet](https://packagist.org/packages/particle-academy/holy-sheet)
counts them, streaming each part before one cell exists:

```php
$schema = FancyHttpGuard::readWorkbook($request->file('file')->get(), field: 'file');
// or, under this route's limits:
$schema = $request->attributes->get('fancy_guard')->readWorkbook($bytes);
```

- **A refusal is a 413:** `workbook_too_large`, with holy-sheet's `limit`, `max`
  and `actual`.
- **A file that is not a spreadsheet is a 422** naming the field.
- **The limits are paired.** The archive cap is the route's body cap, and the
  part caps follow `maxCells` at 64 bytes per cell.
- **holy-sheet is optional.**

## The HTTP-client normaliser

```php
$response = Http::post($upstream, $data);
if ($error = $response->fancyError()) {          // null on a 2xx/3xx
    return back()->withErrors($error->fields)->with('status', $error->sentence);
}
```

It reads the Fancy envelope, FastAPI's `detail` sentence and 422 list, Laravel's
validation map and `message`, and HTML or empty bodies.

- **A 5xx never shows the upstream's text**, fields included.
- **It reads at most 64 KiB** of the body (`['maxBodyBytes' => ...]`).

## Settings

`php artisan vendor:publish --tag=fancy-http-guard-config`. Every limit is a
setting with a secure default:

| Setting | Default |
|---|---|
| `maxBodyBytes` | 1 MiB (the `upload` profile: 10 MiB) |
| `maxDepth` | 32 |
| `maxListLength` | 10,000 |
| `maxErrors` | 20 |
| `finiteNumbers` | true |
| `maxMessageLength` | 300 (at most 4,000) |
| `workbook.maxRows` | 100,000 |
| `workbook.maxColumns` | 1,000 |
| `workbook.maxCells` | 100,000 |

Beyond the limits:

- **`profiles`:** your own named profiles.
- **`routes`:** limits by route name.
- **`request_id_header`:** the request-id header's name.
- **`trust_incoming_request_id`:** off by default. Turn it on only behind a proxy
  that sets the header.

A bad key or value is refused when the app boots, or when a route declares it.

### Long error messages

`maxMessageLength` (default 300) is how many code points of YOUR sentence a
public message keeps: an `abort()` / `HttpException` 4xx message, a validation
item, or a public 5xx. Longer ones keep 299 and gain an ellipsis. Raise it in
`limits`, a profile, or per route (`->guard(['maxMessageLength' => 2000])`). It
cannot go above 4,000: a larger value is refused at boot.

For one long, data-built refusal, raise the limit on that error alone instead:

```php
throw HttpError::public(422, 'rows_refused', $sentence, maxMessageLength: 2000);
throw new ValidationFailed([['field' => 'rows', 'message' => $sentence, 'maxMessageLength' => 2000]]);
```

An override above 4,000 counts as 4,000, and it applies to that error or item
only. **Only the length changes.** Control characters are still stripped at any
length, a crash is still the fixed 500, and a non-public 5xx still never shows
its message. A client reading the response through `fancyError()` truncates at
its own `maxMessageLength` option (default 300), so raise that too.

## Contract

The rules are fancy-conformance `suites/http/SPEC.md` (CC-fancy-0031).

- **This package** runs all 88 rows of `http/request-guards` and all 82 rows of
  `http/error-envelope` in CI.
- **The Python guard** (`fancy-http-guard`) runs the same request-guard rows.
- **The TypeScript normaliser** (`@particle-academy/fancy-http-errors`) runs the
  same normaliser rows.

No third-party runtime dependency, only Laravel's own components. MIT.
