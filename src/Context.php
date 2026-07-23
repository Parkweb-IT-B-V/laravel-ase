<?php

namespace ParkWeb\Ase\Laravel;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

final readonly class Context
{
    /** @return array<string, mixed> */
    public static function request(Request $request): array
    {
        return [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'route' => optional($request->route())->uri(),
            'headers' => collect($request->headers->all())
                ->except(['authorization', 'cookie', 'x-csrf-token', 'x-xsrf-token'])
                ->map(fn (array $value): string => implode(',', $value))
                ->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function user(?Authenticatable $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => method_exists($user, 'getAuthIdentifier') ? (string) $user->getAuthIdentifier() : null,
            'email' => property_exists($user, 'email') ? $user->email : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function runtime(): array
    {
        return [
            'laravel_version' => App::version(),
            'php_version' => PHP_VERSION,
            'environment' => app()->environment(),
            'release' => config('ase.release'),
            'deploy_id' => config('ase.deploy_id'),
        ];
    }
}
