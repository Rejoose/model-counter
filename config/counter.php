<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | The cache store to use for counter increments.
    |
    | Supported: "redis", "array", or any Laravel cache store
    |
    | - "redis" (recommended for production): Uses atomic INCR/DECR operations
    |   for high performance and thread safety. Requires Redis and sync command.
    |
    | - "array": In-memory store, perfect for local development and testing.
    |   Data is lost when the request ends. Best used with 'direct' => true.
    |
    */
    'store' => env('COUNTER_STORE', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Direct Database Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, increments/decrements write directly to the database
    | instead of caching in Redis first. This bypasses the sync command.
    |
    | Recommended for:
    | - Local development and testing (set to true)
    | - Low-traffic applications where sync overhead isn't worth it
    |
    | For high-traffic production environments, keep this false and use
    | Redis with the scheduled sync command.
    |
    */
    'direct' => env('COUNTER_DIRECT', false),

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for all counter keys stored in cache. This helps organize
    | and namespace your counters.
    |
    */
    'prefix' => env('COUNTER_PREFIX', 'counter:'),

    /*
    |--------------------------------------------------------------------------
    | Sync Batch Size
    |--------------------------------------------------------------------------
    |
    | Number of counters to process in a single batch during sync.
    | Adjust based on your Redis and database performance.
    | Only applies when using Redis store.
    |
    */
    'sync_batch_size' => env('COUNTER_SYNC_BATCH_SIZE', 1000),

    /*
    |--------------------------------------------------------------------------
    | Table Name
    |--------------------------------------------------------------------------
    |
    | The database table name for storing counter values.
    |
    */
    'table_name' => 'model_counters',
];
