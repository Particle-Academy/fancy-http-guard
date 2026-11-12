<?php

declare(strict_types=1);

namespace ParticleAcademy\HttpGuard;

use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;
use ParticleAcademy\HttpGuard\Client\ErrorNormaliser;

final class HttpGuardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/fancy-http-guard.php', 'fancy-http-guard');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/fancy-http-guard.php' => config_path('fancy-http-guard.php')], 'fancy-http-guard-config');

        // Refuse a bad config when the app boots, not on the first request.
        $config = (array) config('fancy-http-guard', []);
        $base = Settings::resolve((array) ($config['limits'] ?? []));
        foreach ((array) ($config['profiles'] ?? []) as $profile) {
            Settings::resolve((array) $profile, $base);
        }

        /*
         * Route::post(...)->guard('upload') or ->guard(['maxBodyBytes' => 8192]).
         * Inline limits are validated HERE, when the route is declared.
         */
        if (! Route::hasMacro('guard')) {
            Route::macro('guard', function (string|array $profileOrLimits): Route {
                /** @var Route $this */
                if (is_array($profileOrLimits)) {
                    Settings::resolve($profileOrLimits);
                }
                $this->action['fancy_guard'] = $profileOrLimits;

                return $this;
            });
        }

        // Http::post(...)->fancyError(): the error as one sentence, or null.
        if (class_exists(ClientResponse::class) && ! ClientResponse::hasMacro('fancyError')) {
            ClientResponse::macro('fancyError', function (array $options = []) {
                /** @var ClientResponse $this */
                return ErrorNormaliser::fromResponse($this, $options);
            });
        }
    }
}
