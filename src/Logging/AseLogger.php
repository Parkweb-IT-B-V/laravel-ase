<?php

namespace ParkWeb\Ase\Laravel\Logging;

use Monolog\Level;
use Monolog\Logger;
use ParkWeb\Ase\Client;

final readonly class AseLogger
{
    public function __construct(private Client $client) {}

    /** @param array<string, mixed> $config */
    public function __invoke(array $config): Logger
    {
        $level = (string) ($config['level'] ?? 'warning');

        return new Logger(
            name: (string) ($config['name'] ?? 'ase'),
            handlers: [
                new AseLogHandler(
                    client: $this->client,
                    level: Level::fromName(strtoupper($level)),
                    bubble: (bool) ($config['bubble'] ?? true),
                ),
            ],
        );
    }
}
