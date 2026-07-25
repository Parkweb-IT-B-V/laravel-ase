<?php

namespace ParkWeb\Ase\Laravel\Telemetry;

use Illuminate\Support\Str;
use ParkWeb\Ase\Client;
use ParkWeb\Ase\Level;

final readonly class LaravelTelemetryRecorder
{
    public function __construct(private Client $client) {}

    /** @param array<string, mixed> $extra */
    public function capture(string $type, string $message, array $extra = [], Level $level = Level::Info): void
    {
        if (! (bool) config('ase.enabled') || ! (bool) config("ase.observability.{$type}", true)) {
            return;
        }

        $this->client->captureTelemetry($type, $message, [
            'laravel' => [
                'app' => config('app.name'),
                'environment' => app()->environment(),
                'version' => app()->version(),
            ],
            ...$this->scrub($extra),
        ], $level);
    }

    public function shouldCaptureQuery(float $milliseconds): bool
    {
        if (! (bool) config('ase.observability.queries', true)) {
            return false;
        }

        return $milliseconds >= (float) config('ase.observability.query_threshold_ms', 100);
    }

    /** @return array<int, mixed> */
    public function safeBindings(array $bindings): array
    {
        if (! (bool) config('ase.observability.include_query_bindings', false)) {
            return [];
        }

        return $this->scrub($bindings);
    }

    /** @param array<string|int, mixed> $value */
    private function scrub(array $value): array
    {
        foreach ($value as $key => $item) {
            $keyName = Str::of((string) $key)->lower()->toString();
            if (in_array($keyName, ['authorization', 'cookie', 'password', 'password_confirmation', 'token', 'api_token', 'secret', 'key'], true)) {
                $value[$key] = '[REDACTED]';
            } elseif (is_array($item)) {
                $value[$key] = $this->scrub($item);
            } elseif (is_string($item) && strlen($item) > 2000) {
                $value[$key] = substr($item, 0, 2000).'...';
            }
        }

        return $value;
    }
}
