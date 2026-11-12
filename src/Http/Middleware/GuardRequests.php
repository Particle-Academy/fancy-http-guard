<?php

declare(strict_types=1);

namespace ParticleAcademy\HttpGuard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use ParticleAcademy\HttpGuard\Guard;
use ParticleAcademy\HttpGuard\GuardState;
use ParticleAcademy\HttpGuard\Settings;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The guard, as GLOBAL middleware, PREPENDED (FancyHttpGuard::install()).
 *
 * Why global: TrimStrings and ConvertEmptyStringsToNull are global middleware
 * and call `$request->json()`, which decodes the whole body before any route
 * middleware runs. A route middleware would check a body that has already been
 * parsed.
 *
 * Why it matches the route itself: global middleware runs before routing, so to
 * honour per-route limits it asks the route collection which route will serve
 * the request. A miss (404/405) means the default limits; the router raises the
 * real error moments later.
 *
 * On an Inertia request a 422 is DEFERRED to DeferredInertiaValidation, in the
 * `web` group after the session starts, so the form gets native flat errors.
 * Every other refusal answers at once: deferring an oversize body would mean
 * parsing it.
 */
final class GuardRequests
{
    public const ID_PATTERN = '/^[A-Za-z0-9._:-]{1,128}$/D';

    /** @var array<string, array<string, mixed>> per-route limits, cached by route identity */
    private array $cache = [];

    public function handle(Request $request, Closure $next): Response
    {
        $config = (array) config('fancy-http-guard', []);
        $rh = strtolower((string) ($config['request_id_header'] ?? 'x-request-id'));
        $rid = $this->requestId($request, $rh, (bool) ($config['trust_incoming_request_id'] ?? false));
        $request->attributes->set('request_id', $rid);
        if (class_exists(\Illuminate\Support\Facades\Context::class)) {
            \Illuminate\Support\Facades\Context::add('request_id', $rid);
        }

        $limits = $this->limitsFor($request, $config);
        $request->attributes->set('fancy_guard', new GuardState($rid, $limits));

        $refusal = Guard::inspect($request, $limits, $rid, $rh);
        if ($refusal !== null) {
            if ($refusal->status === 422 && $request->headers->has('X-Inertia')) {
                $request->attributes->set('fancy_guard.deferred', $refusal);
            } else {
                return $this->withId($refusal->toResponse(), $rh, $rid);
            }
        }

        return $this->withId($next($request), $rh, $rid);
    }

    private function requestId(Request $request, string $rh, bool $trust): string
    {
        if ($trust) {
            $inbound = $request->headers->get($rh);
            if (is_string($inbound) && preg_match(self::ID_PATTERN, $inbound) === 1) {
                return $inbound;
            }
        }

        return (string) Str::uuid();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function limitsFor(Request $request, array $config): array
    {
        $base = Settings::resolve((array) ($config['limits'] ?? []));
        $profiles = (array) ($config['profiles'] ?? []);
        try {
            $route = app('router')->getRoutes()->match($request);
        } catch (Throwable) {
            return $base; // 404 / 405: the router answers that itself, moments later
        }
        if (! $route instanceof Route) {
            return $base;
        }
        $key = spl_object_id($route);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $declared = $route->getAction('fancy_guard');
        $byName = $route->getName() !== null ? (($config['routes'] ?? [])[$route->getName()] ?? null) : null;
        $limits = match (true) {
            $declared !== null => Settings::forRoute($declared, $profiles, $base),
            $byName !== null => Settings::forRoute($byName, $profiles, $base),
            default => $base,
        };

        return $this->cache[$key] = $limits;
    }

    private function withId(Response $response, string $rh, string $rid): Response
    {
        $response->headers->set($rh, $rid);

        return $response;
    }
}
