<?php

namespace ParkWeb\Ase\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ParkWeb\Ase\ClientOptions;
use ParkWeb\Ase\Dsn;
use ParkWeb\Ase\Transport\NullTransport;
use ParkWeb\Ase\Transport\SyncTransport;

final class SendAseEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /** @param array<int, array<string, mixed>> $events */
    public function __construct(private readonly array $events) {}

    public function handle(): void
    {
        if ($this->events === [] || ! class_exists(\Http\Discovery\Psr18ClientDiscovery::class)) {
            (new NullTransport)->flush();

            return;
        }

        $options = ClientOptions::fromArray(config('ase'));
        $transport = new SyncTransport(
            $options,
            Dsn::parse($options->dsn),
            \Http\Discovery\Psr18ClientDiscovery::find(),
            \Http\Discovery\Psr17FactoryDiscovery::findRequestFactory(),
            \Http\Discovery\Psr17FactoryDiscovery::findStreamFactory(),
            app()->bound('log') ? app('log') : null,
        );
        $transport->sendBatch($this->events);
    }
}
