<?php

declare(strict_types=1);

namespace ParticleAcademy\HttpGuard;

use HolySheet\Agent;
use HolySheet\Exceptions\ArchiveRefused;
use Illuminate\Http\Request;
use ParticleAcademy\HttpGuard\Http\Middleware\DeferredInertiaValidation;
use ParticleAcademy\HttpGuard\Http\Middleware\GuardRequests;
use RuntimeException;
use Throwable;

/**
 * Wiring, in bootstrap/app.php:
 *
 *     ->withMiddleware(fn (Middleware $m) => FancyHttpGuard::install($m))
 *     ->withExceptions(fn (Exceptions $e) => FancyHttpGuard::exceptions($e))
 */
final class FancyHttpGuard
{
    /**
     * PREPENDS the guard to the global stack, so it runs before TrimStrings
     * and ConvertEmptyStringsToNull decode the body, and adds the deferred
     * Inertia validation to the `web` group.
     *
     * @param  \Illuminate\Foundation\Configuration\Middleware  $middleware
     */
    public static function install(object $middleware): void
    {
        $middleware->prepend(GuardRequests::class);
        $middleware->appendToGroup('web', DeferredInertiaValidation::class);
    }

    /**
     * Renders exceptions as the envelope for JSON requests. HTML pages and
     * Inertia requests keep Laravel's own handling (Inertia's validation
     * redirect included).
     *
     * @param  \Illuminate\Foundation\Configuration\Exceptions  $exceptions
     */
    public static function exceptions(object $exceptions): void
    {
        $exceptions->render(static fn (Throwable $e, Request $request) => self::renderFor($request, $e));
    }

    public static function renderFor(Request $request, Throwable $e): ?\Symfony\Component\HttpFoundation\Response
    {
        if ($request->headers->has('X-Inertia') || ! $request->expectsJson()) {
            return null;
        }
        $rh = strtolower((string) config('fancy-http-guard.request_id_header', 'x-request-id'));
        $rid = $request->attributes->get('request_id');
        if (! is_string($rid)) {
            $rid = (string) \Illuminate\Support\Str::uuid();
            $request->attributes->set('request_id', $rid);
        }

        // The route's limits, as the guard resolved them; the app's own when the
        // guard did not run for this request.
        $state = $request->attributes->get('fancy_guard');
        $limits = $state instanceof GuardState ? $state->limits : Settings::resolve((array) config('fancy-http-guard.limits', []));

        return Renderer::render($e, $rid, $rh, (int) $limits['maxMessageLength'])->toResponse();
    }

    /**
     * Read an uploaded workbook under a route's limits (SPEC section 5): the
     * guard sets the limits, holy-sheet COUNTS them, streaming each part before
     * one cell exists. A limit refusal is rethrown as holy-sheet raised it (the
     * renderer makes it a 413 from its numbers); a file that is not a
     * spreadsheet becomes a 422 naming `$field`.
     *
     * @param  array<string, mixed>  $limits
     * @return array<string, mixed>
     */
    public static function readWorkbook(string $bytes, array $limits = [], string $field = 'file', bool $resolved = false): array
    {
        if (! class_exists(Agent::class)) {
            throw new RuntimeException('fancy-http-guard: readWorkbook() needs particle-academy/holy-sheet installed.');
        }
        $settings = $resolved ? $limits : Settings::resolve($limits);
        $unreadable = new ValidationFailed([['error' => 'workbook_unreadable', 'message' => Sentences::UNREADABLE_WORKBOOK, 'field' => $field]]);
        try {
            return Agent::read($bytes, ['limits' => Settings::workbookLimits($settings)]);
        } catch (ArchiveRefused $e) {
            if (in_array($e->reason, ['archive_limit', 'read_limit'], true)) {
                throw $e;
            }
            throw $unreadable;
        } catch (\RuntimeException|\InvalidArgumentException|\ValueError $e) {
            throw $unreadable;
        }
    }
}
