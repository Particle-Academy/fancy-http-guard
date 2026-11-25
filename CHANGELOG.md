# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

**Pre-1.0, breaking changes land in MINOR releases.** The version number is not
promising otherwise until 1.0.

## [Unreleased]

### Added

- `limits.maxMessageLength`, a setting for public error messages
  (CC-fancy-0063): how many code points of the app's own sentence a 4xx message,
  a validation item or a public 5xx keeps. Default 300, so **nothing changes
  unless you set it**. Set it in config, a profile or `->guard([...])` like every
  other limit; a value over 4,000 (`Settings::MESSAGE_LENGTH_BOUND`) is refused
  at boot. A published `config/fancy-http-guard.php` without the key keeps 300.
- A per-error override for one long refusal: `HttpError`'s new
  `maxMessageLength` argument (also on `HttpError::public(...)`) and a
  `ValidationFailed` item's `'maxMessageLength'`. It replaces the route's
  setting for that error or item alone, and counts as 4,000 above 4,000. A
  non-positive or non-integer value throws `InvalidArgumentException` where the
  error is created.
- `Renderer::render()` takes the route's length as a fourth argument (default
  300); `FancyHttpGuard::exceptions()` passes it for you.
- Tests: `tests/Feature/MessageLengthTest.php`, the shared rows' new `failed`
  kind and `input.limits` in `tests/Support/Rows.php`, and four sabotage
  mutations (23 in all). The shared rows (`http/request-guards` 0089 to 0098)
  land in fancy-conformance after 0.63.0; all 98 pass here against that branch,
  and the pin moves when it is released. That release also raises the envelope
  schema's `message` bound to 4,000, and `resources/envelope.schema.json` must
  take the new copy with the pin (`PinTest` enforces it).

### Changed

- Sanitising is unchanged at any length: control characters are still stripped,
  a message over its limit still truncates with an ellipsis, a crash is still
  the fixed 500 and a non-public 5xx still never shows its message.

- CI: re-pin org third-party allowlist to 2614224
- Conformance fixtures 0.62.0: the `http/request-guards` rows are now pinned at fancy-conformance 0.62.0 (was 0.48.0), in the CI checkout ref and `PINNED_SUITE_VERSION`; the sabotage sweep still catches 19 of 19. Test-only; no runtime change, nothing to do.

## [0.1.0] - 2026-11-18

First release (CC-fancy-0031).

### Added

- **`FancyHttpGuard::install()`** prepends the guard to the global middleware,
  so it runs before TrimStrings parses the body. The guard:
  - refuses a `Content-Length` over the route's cap without reading the body;
  - catches a missing or lying length with a bounded read;
  - in one scan of a JSON body, refuses nesting deeper than `maxDepth`, more
    than `maxListLength` members, and `NaN`/`Infinity`/overflowing numbers,
    naming the field.
- **Per-route limits** through `Route::...->guard('upload')` or inline limits,
  named profiles, or route names in config. Every limit is a setting; a bad one
  is refused at boot or at route declaration.
- **`FancyHttpGuard::exceptions()`** renders JSON errors as one envelope, with
  the request id in the body, in the `X-Request-Id` header on every response,
  and in `Context`.
  - A crash is a fixed 500 even with `APP_DEBUG` on.
  - A 5xx message is never shown unless it comes from `HttpError::public()`.
  - Framework-made messages, such as a ModelNotFoundException naming the model
    class, are hidden.
- **Inertia:** a guard 422 becomes a native ValidationException, so the form
  gets flat `props.errors`.
- **`FancyHttpGuard::readWorkbook()`**, paired with holy-sheet's reader limits.
  A refusal becomes a 413 `workbook_too_large`.
- **`Http::...->fancyError()` and `ErrorNormaliser`**, which turn any upstream
  error response into one sentence, reading at most 64 KiB.
- **Conformance:** passes all 88 rows of fancy-conformance
  `http/request-guards` and all 82 rows of `http/error-envelope` (0.48.0),
  through the real HTTP kernel.
- **A 19-mutation sabotage sweep** in CI.

### Changed

- **CI: the lab-certificate gate verifies against the lab's rotated key list.**
  The lab's first signing key is retired: certificates it already issued still
  verify, and nothing new it signs ever can. Consumers do nothing.

- **Docs:** `AGENTS.md` is now the guide to using this package (install, how to find it on the Fancy MCP catalog server once it is listed, API essentials, traps). The contributor notes it held moved to `CONTRIBUTING.md`, and `CLAUDE.md` is now a symlink to it. The README points to the MCP server and to `AGENTS.md`. No code changed; nothing to do.

### Dependencies

- **Runtime:** Laravel's own `illuminate/*` components only.
- **Test only:**
  - `laravel/framework`, the framework this package integrates with, under the
    standing carve-out;
  - `pestphp/pest`, under the standing Pest-for-PHP rule;
  - `particle-academy/holy-sheet`, which is first-party.

  No orchestra/testbench.
