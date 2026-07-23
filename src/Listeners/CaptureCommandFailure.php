<?php

namespace ParkWeb\Ase\Laravel\Listeners;

use Illuminate\Console\Events\CommandFinished;
use ParkWeb\Ase\Ase;
use RuntimeException;

final class CaptureCommandFailure
{
    public function handle(CommandFinished $event): void
    {
        if ($event->exitCode === 0) {
            return;
        }

        Ase::withScope(function ($scope) use ($event): void {
            $scope->setTag('laravel.command', $event->command ?? 'unknown');
            $scope->setExtra('command', ['exit_code' => $event->exitCode, 'input' => (string) $event->input]);
            Ase::captureException(new RuntimeException('Laravel command failed with exit code '.$event->exitCode));
        });
    }
}
