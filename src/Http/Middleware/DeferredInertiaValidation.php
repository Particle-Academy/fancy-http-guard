<?php

declare(strict_types=1);

namespace ParticleAcademy\HttpGuard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use ParticleAcademy\HttpGuard\Envelope;
use Symfony\Component\HttpFoundation\Response;

/**
 * In the `web` group, after the session has started: a guard 422 on an Inertia
 * request becomes a ValidationException, so Laravel flashes the errors and the
 * form reads them the native Inertia way (`props.errors`, flat, dotted keys).
 * The guard itself runs before the session exists and cannot flash anything.
 */
final class DeferredInertiaValidation
{
    public function handle(Request $request, Closure $next): Response
    {
        $deferred = $request->attributes->get('fancy_guard.deferred');
        if ($deferred instanceof Envelope) {
            $messages = [];
            foreach ($deferred->body['errors'] ?? [] as $item) {
                $field = $item['field'] ?? 'request';
                $messages[$field] ??= [$item['message']];
            }
            throw ValidationException::withMessages($messages);
        }

        return $next($request);
    }
}
