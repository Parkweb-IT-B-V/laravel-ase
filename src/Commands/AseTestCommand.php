<?php

namespace ParkWeb\Ase\Laravel\Commands;

use Illuminate\Console\Command;
use ParkWeb\Ase\Ase;
use ParkWeb\Ase\Dsn;
use ParkWeb\Ase\Level;

final class AseTestCommand extends Command
{
    protected $signature = 'ase:test {--exception : Send a test exception instead of a warning message}';

    protected $description = 'Send a test event to All Seeing Eye and print the effective SDK configuration.';

    public function handle(): int
    {
        $dsn = $this->effectiveDsn();
        $this->line('ASE enabled: '.((bool) config('ase.enabled') ? 'yes' : 'no'));
        $this->line('ASE transport: '.(string) config('ase.transport', 'sync'));
        $this->line('ASE queue: '.(string) config('ase.queue', 'ase'));
        $this->line('ASE release: '.((string) config('ase.release') ?: '-'));

        if ($dsn === '') {
            $this->error('ASE_DSN is empty.');

            return self::FAILURE;
        }

        try {
            $parsed = Dsn::parse($dsn);
            $this->line('ASE endpoint: '.$parsed->endpoint);
            $this->line('ASE key id: '.$parsed->keyId);
            if (! str_starts_with($parsed->keyId, 'sk_ase_')) {
                $this->warn('ASE key id should normally start with sk_ase_. You appear to be using a database id instead of the server credential public_identifier.');
            }
        } catch (\Throwable $throwable) {
            $this->error('Invalid ASE_DSN: '.$throwable->getMessage());

            return self::FAILURE;
        }

        if ((string) config('ase.transport') === 'queue') {
            $this->warn('Queue transport selected. Run: php artisan queue:work --queue='.(string) config('ase.queue', 'ase').',default');
        }

        $eventId = $this->option('exception')
            ? Ase::captureException(new \RuntimeException('ASE Laravel SDK test exception'))
            : Ase::captureMessage('ASE Laravel SDK test warning', Level::Warning);
        Ase::flush();

        $this->info('ASE capture invoked. Event id: '.($eventId ?: 'not returned'));
        $this->line('If the event is not visible, set ASE_DEBUG=true and check laravel.log for "ASE transport rejected event batch".');

        return self::SUCCESS;
    }

    private function effectiveDsn(): string
    {
        $dsn = (string) config('ase.dsn', '');
        if ($dsn !== '') {
            return $dsn;
        }

        $token = (string) config('ase.token', '');
        $endpoint = (string) config('ase.endpoint', '');
        if ($token === '' || $endpoint === '') {
            return '';
        }

        $parts = parse_url($endpoint);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $path = $parts['path'] ?? '/api/v1/ingest/envelope';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $parts['scheme'].'://'.rawurlencode($token).'@'.$parts['host'].$port.$path.$query;
    }
}
