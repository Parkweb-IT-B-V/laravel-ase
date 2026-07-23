<?php

namespace ParkWeb\Ase\Laravel\Transport;

use ParkWeb\Ase\Laravel\Jobs\SendAseEvent;
use ParkWeb\Ase\Transport\Transport;

final readonly class LaravelQueuedTransport implements Transport
{
    public function __construct(private string $queue) {}

    public function send(array $event): void
    {
        $this->sendBatch([$event]);
    }

    public function sendBatch(array $events): void
    {
        if ($events === []) {
            return;
        }

        SendAseEvent::dispatch($events)->onQueue($this->queue);
    }

    public function flush(): void {}
}
