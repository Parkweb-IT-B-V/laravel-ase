<?php

namespace ParkWeb\Ase\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use ParkWeb\Ase\Client;
use ParkWeb\Ase\Laravel\Context;
use Symfony\Component\HttpFoundation\Response;

final readonly class AseContextMiddleware
{
    public function __construct(private Client $client) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->client->withScope(function ($scope) use ($request, $next): Response {
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

            return $next($request);
        });
    }
}
