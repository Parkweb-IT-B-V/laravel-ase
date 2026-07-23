<?php

return [
    'dsn' => env('ASE_DSN'),
    'token' => env('ASE_TOKEN'),
    'endpoint' => env('ASE_ENDPOINT', 'https://api-ase.parkwebit.nl/api/v1/ingest/envelope'),
    'enabled' => env('ASE_ENABLED', true),
    'release' => env('ASE_RELEASE'),
    'environment' => env('ASE_ENVIRONMENT', env('APP_ENV')),
    'deploy_id' => env('ASE_DEPLOY_ID'),
    'capture_warnings' => true,
    'debug' => env('ASE_DEBUG', false),
    'send_default_pii' => false,
    'sample_rate' => 1.0,
    'timeout' => 1.5,
    'max_retries' => 1,
    'gzip' => true,
    'transport' => env('ASE_TRANSPORT', 'sync'), // sync|queue|buffer
    'queue' => env('ASE_QUEUE', 'ase'),
    'offline_buffer_path' => storage_path('framework/ase/offline-buffer.jsonl'),
];
