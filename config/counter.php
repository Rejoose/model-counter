<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | The cache store to use for counter increments. Redis is recommended
    | for atomic operations and high performance.
    |
    */
    'store' => env('COUNTER_STORE', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Redis Key Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for all counter keys stored in Redis. This helps organize
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

