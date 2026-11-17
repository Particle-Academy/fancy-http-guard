# Contributing to particle-academy/fancy-http-guard (Laravel)

Notes for anyone, human or agent, working ON this package's code. How to USE the
package is in [AGENTS.md](./AGENTS.md); what it is, is in [README.md](./README.md).
`CLAUDE.md` is a symlink to this file, so Claude Code reads these notes when it
works in this repo.

Request guards, the error envelope, and the HTTP-client normaliser. Process
rules live in the envelope's `RULES.md`.

## The contract

fancy-conformance `suites/http/SPEC.md` (CC-fancy-0031).

- **`http/request-guards`:** the guard, the renderer and the workbook pairing.
- **`http/error-envelope`:** the sentences and the client normaliser.

The Python guard and the TypeScript normaliser run the same rows. **Behaviour
changes in the spec and its rows first.**

## The rules that shape this repo

- **Global and PREPENDED.** TrimStrings and ConvertEmptyStringsToNull are global
  middleware that call `$request->json()`. A guard anywhere later checks a body
  already parsed. The sabotage sweep appends it instead and the suite fails.
- **It matches the route itself**, because global middleware runs before
  routing. A 404/405 from that match means the default limits; it is never
  answered there.
- **Refuse unread.** `Content-Length` first. Then
  `stream_get_contents(getContent(true), cap + 1)`, which is bounded. Symfony
  rewinds a content resource on the next `getContent()`, and `php://input` is
  re-readable, so a passing body still reaches the controller whole.
- **An empty `CONTENT_LENGTH` is absent.** PHP-FPM sends one on every GET.
- **Exception text is never echoed.** `Renderer::render` ends in a fixed 500. An
  HttpException made from ANOTHER exception (it has a `getPrevious()`) does not
  show its message, except when the previous is an `AuthorizationException`,
  whose message the app wrote.
- **Inertia:** the guard runs before the session, so a 422 there is deferred to
  `DeferredInertiaValidation` in the `web` group. Every other refusal answers at
  once.
- **No third-party runtime dependency.** Only `illuminate/*` (PinTest enforces
  it). holy-sheet, `illuminate/auth` and `Context` are optional, through
  `class_exists` / `instanceof`.

## Testing

```bash
composer update
vendor/bin/pest               # conformance tables + hostile cases, through the real HTTP kernel
php tests/sabotage.php        # 19 mutations, every one must be CAUGHT
```

- **A real Laravel app.** `tests/Support/TestApp.php` builds one with
  `Application::configure(...)->create()`, exactly as a consumer's
  `bootstrap/app.php` does, and drives the real HTTP kernel. There is no
  orchestra/testbench (not approved): Laravel itself is the test dependency,
  under the framework carve-out.
- **Bodies are php://temp STREAMS**, so `ftell()` after a refusal measures what
  the guard read.
- **The tables come from a fancy-conformance checkout**, never Packagist:
  `FANCY_CONFORMANCE_ROOT` in CI (at `ref: v<Rows::PINNED_SUITE_VERSION>`),
  else the envelope's sibling checkout. Move the pin and the `ci.yml` ref
  together.
- **SQLite is forced** in `phpunit.xml` (`<env force>` and `<server force>`),
  though nothing here touches a database.
- **json_decode's depth is containers + 1.** The normaliser passes
  `maxDepth + 1`, and rows depth-64 / depth-65 pin the edge.
