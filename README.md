# parkweb/ase-laravel

Laravel integration for All Seeing Eye.

```bash
composer require parkweb/ase-laravel php-http/discovery guzzlehttp/guzzle nyholm/psr7
php artisan vendor:publish --tag=ase-config
```

If you installed an earlier local path version, run:

```bash
composer update parkweb/ase-php parkweb/ase-laravel guzzlehttp/guzzle nyholm/psr7 php-http/discovery
php artisan optimize:clear
```

Configure:

```env
ASE_DSN=https://sk_ase_key_id:server-secret@api-ase.parkwebit.nl/api/v1/ingest/envelope
ASE_ENABLED=true
ASE_RELEASE=${APP_VERSION}
ASE_ENVIRONMENT=production
ASE_DEPLOY_ID=${FORGE_DEPLOYMENT_ID}
ASE_TRANSPORT=queue
ASE_QUEUE=ase
ASE_DEBUG=false
```

Config file:

```php
return [
    'dsn' => env('ASE_DSN'),
    'enabled' => env('ASE_ENABLED', true),
    'release' => env('ASE_RELEASE'),
    'capture_warnings' => true,
    'send_default_pii' => false,
    'sample_rate' => 1.0,
];
```

What is captured automatically:

- unhandled HTTP exceptions and fatal shutdown errors;
- warnings when `capture_warnings` is enabled;
- authenticated user id/email;
- request URL, method and route;
- request id when present in request context;
- Laravel version, PHP version, environment, release and deploy id;
- queue failures with job metadata;
- command/scheduler failures via failed command exit codes.

Transport note:

- `ASE_TRANSPORT=sync` sends during the request and is easiest for testing.
- `ASE_TRANSPORT=queue` only sends when an `ase` queue worker is running.

## Debugging delivery

Start with sync transport:

```env
ASE_TRANSPORT=sync
ASE_DEBUG=true
```

Clear config and send a test event:

```bash
php artisan optimize:clear
php artisan ase:test
php artisan ase:test --exception
```

If ASE rejects the event, check `storage/logs/laravel.log` for:

```text
ASE transport rejected event batch
```

Common causes:

- DSN host points to the wrong API domain.
- DSN key id is not the server credential public identifier.
- DSN secret is not the plaintext server key.
- The server key is revoked or expired.
- `ASE_TRANSPORT=queue` is configured but no queue worker is running.
- API returns `422` because the installed SDK package is stale.

Safety:

- ASE failures never crash the Laravel app.
- Authorization, cookie and CSRF headers are never sent.
- Request bodies are not sent by default.
- The ASE queue job is guarded against recursive queue failure capture.
- Use `ASE_TRANSPORT=queue` for production web requests.

Laravel Forge deploy snippet:

```bash
export ASE_RELEASE="$(git rev-parse --short HEAD)"
export ASE_DEPLOY_ID="${FORGE_DEPLOYMENT_ID:-$(date +%s)}"
php artisan config:cache
php artisan queue:restart
```

Queue worker:

```bash
php artisan queue:work --queue=ase,default --tries=3 --timeout=30
```

Scheduler/cron:

```bash
* * * * * cd /home/forge/example.com && php artisan schedule:run >> /dev/null 2>&1
```

Manual capture:

```php
use ParkWeb\Ase\Ase;
use ParkWeb\Ase\Level;

Ase::setTag('tenant', 'acme');
Ase::captureMessage('Checkout degraded', Level::Warning);
Ase::captureException($throwable);
```

## Laravel logging channel

Add an ASE channel to `config/logging.php`:

```php
'channels' => [
    'ase' => [
        'driver' => 'custom',
        'via' => ParkWeb\Ase\Laravel\Logging\AseLogger::class,
        'level' => env('ASE_LOG_LEVEL', 'warning'),
        'bubble' => true,
    ],
],
```

Use it directly:

```php
Log::channel('ase')->warning('Checkout latency is high', [
    'tenant' => 'acme',
    'duration_ms' => 1840,
]);

Log::channel('ase')->error('Payment failed', [
    'exception' => $throwable,
]);
```

Or add it to an existing stack:

```php
'stack' => [
    'driver' => 'stack',
    'channels' => ['single', 'ase'],
    'ignore_exceptions' => false,
],
```

The log channel maps Laravel/Monolog levels to ASE levels and sends context as `extra`. Add `['ase_skip' => true]` to a log context if you explicitly want to avoid ASE capture for that record.
