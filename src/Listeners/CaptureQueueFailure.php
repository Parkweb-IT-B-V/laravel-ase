<?php

namespace ParkWeb\Ase\Laravel\Listeners;

use Illuminate\Queue\Events\JobFailed;
use ParkWeb\Ase\Ase;

final class CaptureQueueFailure
{
    public function handle(JobFailed $event): void
    {
        if (str_contains($event->job->resolveName(), 'Ase')) {
            return;
        }

        Ase::withScope(function ($scope) use ($event): void {
            $scope->setTag('laravel.queue', $event->connectionName);
            $scope->setExtra('job', ['name' => $event->job->resolveName(), 'queue' => $event->job->getQueue()]);
            Ase::captureException($event->exception);
        });
    }
}
