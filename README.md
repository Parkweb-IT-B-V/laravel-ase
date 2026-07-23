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
