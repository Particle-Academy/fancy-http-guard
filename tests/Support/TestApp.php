<?php

declare(strict_types=1);

namespace ParticleAcademy\HttpGuard\Tests\Support;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use ParticleAcademy\HttpGuard\FancyHttpGuard;
use ParticleAcademy\HttpGuard\HttpGuardServiceProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * A real Laravel 13 application, configured the way a consumer's
 * bootstrap/app.php does it (Application::configure ... ->create()), and driven
 * through the real HTTP kernel. No orchestra/testbench: Laravel itself is the
 * test dependency, under the framework carve-out.
 *
 * That matters here more than usual: the guard's whole claim is about ORDER --
 * that it runs before TrimStrings decodes the body -- and only the real global
 * middleware stack can show it.
 */
final class TestApp
{
    public const APP_KEY = 'base64:a2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2s=';

    /** @var array<string, mixed> what the last request's handler saw */
    public static array $seen = [];

    /**
     * @param  array<string, mixed>  $config  dotted keys set after boot
     * @param  (callable(): void)|null  $routes
     */
    public static function make(array $config = [], ?callable $routes = null, bool $debug = false): Application
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fancy-http-guard-tests';
        foreach (['/bootstrap/cache', '/storage/logs', '/storage/framework/views', '/config'] as $dir) {
            if (! is_dir($base.$dir)) {
                mkdir($base.$dir, 0777, true);
            }
        }
        if (! is_file($base.'/.env')) {
            touch($base.'/.env'); // an absent .env is a warning, and warnings fail this suite
        }
        self::$seen = [];

        $app = Application::configure(basePath: $base)
            ->withProviders([HttpGuardServiceProvider::class])
            ->withRouting(using: $routes ?? static function (): void {})
            ->withMiddleware(static function (Middleware $middleware): void {
                FancyHttpGuard::install($middleware);
            })
            ->withExceptions(static function (Exceptions $exceptions): void {
                FancyHttpGuard::exceptions($exceptions);
            })
            ->create();

        $app->booted(static function (Application $app) use ($config, $debug): void {
            $app['config']->set([
                'app.key' => self::APP_KEY,
                'app.debug' => $debug,
                'app.env' => 'testing',
                'session.driver' => 'array',
                'cache.default' => 'array',
                'logging.default' => 'null',
                'database.default' => 'sqlite',
            ]);
            foreach ($config as $key => $value) {
                $app['config']->set($key, $value);
            }
        });

        return $app;
    }

    /** Bootstrap without a request: config, providers, routes, macros. */
    public static function boot(Application $app): Application
    {
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public static function handle(Application $app, Request $request): Response
    {
        $kernel = $app->make(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    /**
     * A request with these headers and this body, as a STREAM, so a test can
     * read afterwards how many bytes were pulled from it (ftell).
     *
     * @param  array<string, string>  $headers
     * @param  resource|null  $stream  receives the body stream
     */
    public static function request(string $uri, string $method, array $headers, string $body, &$stream = null): Request
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $body);
        rewind($stream);

        $server = [];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }
        $request = Request::create($uri, $method, [], [], [], $server, $stream);
        // Symfony invents `application/x-www-form-urlencoded` for a POST with no
        // Content-Type. A body with NO Content-Type is a case the contract pins
        // (it is scanned), so the invention is undone here.
        if (! array_key_exists('content-type', array_change_key_case($headers))) {
            $request->server->remove('CONTENT_TYPE');
            $request->headers->remove('CONTENT_TYPE');
        }

        return $request;
    }
}
