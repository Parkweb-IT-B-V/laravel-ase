<?php

namespace ParkWeb\Ase\Laravel;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use ParkWeb\Ase\Ase;
use ParkWeb\Ase\Client;
use ParkWeb\Ase\ClientOptions;
use ParkWeb\Ase\Dsn;
use ParkWeb\Ase\ErrorHandler;
use ParkWeb\Ase\Laravel\Commands\AseTestCommand;
use ParkWeb\Ase\Laravel\Listeners\CaptureCommandFailure;
use ParkWeb\Ase\Laravel\Listeners\CaptureQueueFailure;
use ParkWeb\Ase\Laravel\Middleware\AseContextMiddleware;
use ParkWeb\Ase\Laravel\Transport\LaravelQueuedTransport;
use ParkWeb\Ase\Transport\BufferedTransport;
use ParkWeb\Ase\Transport\NullTransport;
use ParkWeb\Ase\Transport\Transport;

final class AseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ase.php', 'ase');
        $this->app->singleton(Client::class, function (): Client {
            $config = config('ase');
            $config['dsn'] = $this->effectiveDsn($config);
            $options = ClientOptions::fromArray($config);
            $transport = $this->transport($options);

            return new Client($options, $transport);
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/ase.php' => config_path('ase.php')], 'ase-config');

        $client = $this->app->make(Client::class);
        Ase::init($client);

        if ((bool) config('ase.enabled')) {
            (new ErrorHandler($client, (bool) config('ase.capture_warnings')))->register();
            Event::listen(JobFailed::class, CaptureQueueFailure::class);
            Event::listen(CommandFinished::class, CaptureCommandFailure::class);
            $router = $this->app['router'];
            $router->pushMiddlewareToGroup('web', AseContextMiddleware::class);
            $router->pushMiddlewareToGroup('api', AseContextMiddleware::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([AseTestCommand::class]);
        }
    }

    private function transport(ClientOptions $options): Transport
    {
        if (! $options->enabled || $options->dsn === '') {
            return new NullTransport;
        }

        if (config('ase.transport') === 'queue') {
            return new LaravelQueuedTransport((string) config('ase.queue', 'ase'));
        }

        if (class_exists(\Http\Discovery\Psr18ClientDiscovery::class)) {
            $dsn = Dsn::parse($options->dsn);
            $sync = new \ParkWeb\Ase\Transport\SyncTransport(
                $options,
                $dsn,
                \Http\Discovery\Psr18ClientDiscovery::find(),
                \Http\Discovery\Psr17FactoryDiscovery::findRequestFactory(),
                \Http\Discovery\Psr17FactoryDiscovery::findStreamFactory(),
                $this->app->bound('log') ? $this->app->make('log') : null,
            );

            return config('ase.transport') === 'buffer' ? new BufferedTransport($sync) : $sync;
        }

        return new NullTransport;
    }

    /** @param array<string, mixed> $config */
    private function effectiveDsn(array $config): string
    {
        $dsn = (string) ($config['dsn'] ?? '');
        if ($dsn !== '') {
            return $dsn;
        }

        $token = (string) ($config['token'] ?? '');
        $endpoint = (string) ($config['endpoint'] ?? '');
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
