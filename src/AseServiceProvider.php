<?php

namespace ParkWeb\Ase\Laravel;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
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
use ParkWeb\Ase\Laravel\Telemetry\LaravelTelemetryRecorder;
use ParkWeb\Ase\Laravel\Transport\LaravelQueuedTransport;
use ParkWeb\Ase\Level;
use ParkWeb\Ase\Transport\BufferedTransport;
use ParkWeb\Ase\Transport\NullTransport;
use ParkWeb\Ase\Transport\Transport;
use Throwable;

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
        $this->app->singleton(LaravelExceptionReporter::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/ase.php' => config_path('ase.php')], 'ase-config');

        $client = $this->app->make(Client::class);
        Ase::init($client);

        if ((bool) config('ase.enabled')) {
            (new ErrorHandler($client, (bool) config('ase.capture_warnings')))->register();
            $this->registerLaravelExceptionReporter();
            Event::listen(JobFailed::class, CaptureQueueFailure::class);
            Event::listen(CommandFinished::class, CaptureCommandFailure::class);
            $this->registerObservability();
            $router = $this->app['router'];
            $router->pushMiddlewareToGroup('web', AseContextMiddleware::class);
            $router->pushMiddlewareToGroup('api', AseContextMiddleware::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([AseTestCommand::class]);
        }
    }

    private function registerObservability(): void
    {
        $recorder = $this->app->make(LaravelTelemetryRecorder::class);

        if ((bool) config('ase.observability.queries', true)) {
            DB::listen(function ($query) use ($recorder): void {
                $time = (float) ($query->time ?? 0);
                if (! $recorder->shouldCaptureQuery($time)) {
                    return;
                }

                $recorder->capture('queries', 'Slow SQL query', [
                    'sql' => (string) ($query->sql ?? ''),
                    'duration_ms' => $time,
                    'connection' => (string) ($query->connectionName ?? ''),
                    'bindings' => $recorder->safeBindings((array) ($query->bindings ?? [])),
                ], Level::Warning);
            });
        }

        $this->listenIfExists('Illuminate\Http\Client\Events\ResponseReceived', function ($event) use ($recorder): void {
            $response = $event->response ?? null;
            $request = $event->request ?? null;
            $status = method_exists($response, 'status') ? $response->status() : null;
            $recorder->capture('outgoing_requests', 'Outgoing HTTP request', [
                'method' => method_exists($request, 'method') ? $request->method() : null,
                'url' => method_exists($request, 'url') ? $request->url() : null,
                'status' => $status,
            ], is_int($status) && $status >= 400 ? Level::Warning : Level::Info);
        });

        $this->listenIfExists('Illuminate\Http\Client\Events\ConnectionFailed', function ($event) use ($recorder): void {
            $request = $event->request ?? null;
            $recorder->capture('outgoing_requests', 'Outgoing HTTP request failed', [
                'method' => method_exists($request, 'method') ? $request->method() : null,
                'url' => method_exists($request, 'url') ? $request->url() : null,
            ], Level::Warning);
        });

        $this->listenIfExists('Illuminate\Queue\Events\JobProcessed', function ($event) use ($recorder): void {
            $job = $event->job ?? null;
            if ($job && str_contains($job->resolveName(), 'Ase')) {
                return;
            }
            $recorder->capture('jobs', 'Queue job processed', [
                'connection' => (string) ($event->connectionName ?? ''),
                'queue' => $job && method_exists($job, 'getQueue') ? $job->getQueue() : null,
                'name' => $job && method_exists($job, 'resolveName') ? $job->resolveName() : null,
                'attempts' => $job && method_exists($job, 'attempts') ? $job->attempts() : null,
            ]);
        });

        $this->listenIfExists('Illuminate\Queue\Events\JobFailed', function ($event) use ($recorder): void {
            $job = $event->job ?? null;
            if ($job && str_contains($job->resolveName(), 'Ase')) {
                return;
            }
            $recorder->capture('jobs', 'Queue job failed', [
                'connection' => (string) ($event->connectionName ?? ''),
                'queue' => $job && method_exists($job, 'getQueue') ? $job->getQueue() : null,
                'name' => $job && method_exists($job, 'resolveName') ? $job->resolveName() : null,
                'attempts' => $job && method_exists($job, 'attempts') ? $job->attempts() : null,
                'exception' => isset($event->exception) ? $event->exception::class : null,
            ], Level::Error);
        });

        $this->listenIfExists('Illuminate\Mail\Events\MessageSent', function ($event) use ($recorder): void {
            $message = $event->message ?? null;
            $recorder->capture('mail', 'Mail sent', [
                'subject' => method_exists($message, 'getSubject') ? $message->getSubject() : null,
                'to' => method_exists($message, 'getTo') ? array_keys($message->getTo()) : [],
                'cc' => method_exists($message, 'getCc') ? array_keys($message->getCc()) : [],
                'bcc_count' => method_exists($message, 'getBcc') ? count($message->getBcc()) : 0,
            ]);
        });

        $this->listenIfExists('Illuminate\Notifications\Events\NotificationSent', function ($event) use ($recorder): void {
            $recorder->capture('notifications', 'Notification sent', [
                'channel' => (string) ($event->channel ?? ''),
                'notification' => isset($event->notification) ? $event->notification::class : null,
                'notifiable' => isset($event->notifiable) ? $event->notifiable::class : null,
            ]);
        });

        $this->listenIfExists('Illuminate\Notifications\Events\NotificationFailed', function ($event) use ($recorder): void {
            $recorder->capture('notifications', 'Notification failed', [
                'channel' => (string) ($event->channel ?? ''),
                'notification' => isset($event->notification) ? $event->notification::class : null,
                'notifiable' => isset($event->notifiable) ? $event->notifiable::class : null,
                'data' => (array) ($event->data ?? []),
            ], Level::Warning);
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event) use ($recorder): void {
            $recorder->capture('commands', 'Artisan command finished', [
                'command' => $event->command,
                'exit_code' => $event->exitCode,
            ], $event->exitCode === 0 ? Level::Info : Level::Warning);
        });

        $this->listenIfExists('Illuminate\Console\Events\ScheduledTaskFinished', function ($event) use ($recorder): void {
            $task = $event->task ?? null;
            $recorder->capture('scheduled_tasks', 'Scheduled task finished', [
                'description' => $task && method_exists($task, 'getSummaryForDisplay') ? $task->getSummaryForDisplay() : null,
                'exit_code' => $event->exitCode ?? null,
            ], (int) ($event->exitCode ?? 0) === 0 ? Level::Info : Level::Warning);
        });

        $this->listenIfExists('Illuminate\Console\Events\ScheduledTaskFailed', function ($event) use ($recorder): void {
            $task = $event->task ?? null;
            $recorder->capture('scheduled_tasks', 'Scheduled task failed', [
                'description' => $task && method_exists($task, 'getSummaryForDisplay') ? $task->getSummaryForDisplay() : null,
                'exception' => isset($event->exception) ? $event->exception::class : null,
            ], Level::Error);
        });

        foreach ([
            'Illuminate\Cache\Events\CacheHit' => 'Cache hit',
            'Illuminate\Cache\Events\CacheMissed' => 'Cache miss',
            'Illuminate\Cache\Events\KeyWritten' => 'Cache key written',
            'Illuminate\Cache\Events\KeyForgotten' => 'Cache key forgotten',
        ] as $eventClass => $message) {
            $this->listenIfExists($eventClass, function ($event) use ($recorder, $message): void {
                $recorder->capture('cache', $message, [
                    'key' => (string) ($event->key ?? ''),
                    'store' => (string) ($event->storeName ?? ''),
                ], str_contains($message, 'miss') ? Level::Debug : Level::Info);
            });
        }
    }

    private function listenIfExists(string $eventClass, callable $listener): void
    {
        if (class_exists($eventClass)) {
            Event::listen($eventClass, $listener);
        }
    }

    private function registerLaravelExceptionReporter(): void
    {
        if (! $this->app->bound(ExceptionHandlerContract::class)) {
            return;
        }

        $handler = $this->app->make(ExceptionHandlerContract::class);
        if (! method_exists($handler, 'reportable')) {
            return;
        }

        $reporter = $this->app->make(LaravelExceptionReporter::class);
        $handler->reportable(function (Throwable $throwable) use ($reporter): void {
            $reporter->capture($throwable);
        });
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
