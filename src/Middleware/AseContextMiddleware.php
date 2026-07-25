<?php

namespace ParkWeb\Ase\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use ParkWeb\Ase\Client;
use ParkWeb\Ase\Laravel\Context;
use ParkWeb\Ase\Laravel\LaravelExceptionReporter;
use ParkWeb\Ase\Laravel\Telemetry\LaravelTelemetryRecorder;
use ParkWeb\Ase\Level;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class AseContextMiddleware
{
    public function __construct(
        private Client $client,
        private LaravelExceptionReporter $reporter,
        private LaravelTelemetryRecorder $telemetry,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->client->withScope(function ($scope) use ($request, $next): Response {
            $started = hrtime(true);
            $user = Context::user($request->user());
            if ($user !== null) {
                $scope->setUser($user);
            }
            $scope->setExtra('request', Context::request($request));
            $scope->setExtra('runtime', Context::runtime());
            $scope->setTag('laravel.environment', app()->environment());
            if (config('ase.release')) {
                $scope->setTag('release', (string) config('ase.release'));
            }

            try {
                $response = $next($request);
                $this->captureRequest($request, $response, $started);

                return $response;
            } catch (Throwable $throwable) {
                $this->reporter->capture($throwable);

                throw $throwable;
            }
        });
    }

    private function captureRequest(Request $request, Response $response, int $started): void
    {
        if (! (bool) config('ase.observability.requests', true)) {
            return;
        }

        foreach ($this->ignoredPaths() as $path) {
            $path = trim($path, " \t\n\r\0\x0B/");
            if ($path !== '' && $request->is($path)) {
                return;
            }
        }

        $duration = (int) round((hrtime(true) - $started) / 1_000_000);
        $status = $response->getStatusCode();

        $this->telemetry->capture('requests', 'Laravel request handled', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'route' => optional($request->route())->uri(),
            'status' => $status,
            'duration_ms' => $duration,
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
        ], $status >= 500 ? Level::Error : ($status >= 400 ? Level::Warning : Level::Info));
    }

    /** @return array<int, string> */
    private function ignoredPaths(): array
    {
        $configured = config('ase.observability.ignored_paths', []);
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (! is_array($configured)) {
            $configured = [];
        }

        return array_values(array_unique(array_filter([
            'api/v1/ingest/*',
            'livewire/update',
            'livewire/message/*',
            ...array_map('strval', $configured),
        ])));
    }
}
