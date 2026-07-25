<?php

namespace ParkWeb\Ase\Laravel;

use ParkWeb\Ase\Client;
use SplObjectStorage;
use Throwable;

final class LaravelExceptionReporter
{
    /** @var SplObjectStorage<Throwable, true> */
    private SplObjectStorage $reported;

    public function __construct(private readonly Client $client)
    {
        $this->reported = new SplObjectStorage;
    }

    public function capture(Throwable $throwable): void
    {
        if ($this->reported->contains($throwable)) {
            return;
        }

        $this->reported[$throwable] = true;
        $this->client->captureException($throwable);
        $this->client->flush();
    }
}
