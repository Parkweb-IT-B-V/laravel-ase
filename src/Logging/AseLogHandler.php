<?php

namespace ParkWeb\Ase\Laravel\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use ParkWeb\Ase\Client;
use ParkWeb\Ase\Level as AseLevel;
use ParkWeb\Ase\Scope;
use Throwable;

final class AseLogHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly Client $client,
        int|string|Level $level = Level::Warning,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (($record->context['ase_skip'] ?? false) === true) {
            return;
        }

        $this->client->withScope(function (Scope $scope) use ($record): void {
            foreach ($record->context as $key => $value) {
                if ($key !== 'exception') {
                    $scope->setExtra((string) $key, $value);
                }
            }

            $scope->setExtra('logger', [
                'channel' => $record->channel,
                'level' => $record->level->getName(),
            ]);

            if (($record->context['exception'] ?? null) instanceof Throwable) {
                $this->client->captureException($record->context['exception']);

                return;
            }

            $this->client->captureMessage($record->message, $this->mapLevel($record->level));
        });
    }

    private function mapLevel(Level $level): AseLevel
    {
        return match ($level) {
            Level::Emergency, Level::Alert, Level::Critical => AseLevel::Fatal,
            Level::Error => AseLevel::Error,
            Level::Warning => AseLevel::Warning,
            Level::Notice, Level::Info => AseLevel::Info,
            default => AseLevel::Debug,
        };
    }
}
