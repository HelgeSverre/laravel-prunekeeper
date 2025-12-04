<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk to use for storing archived exports. This should be
    | configured in your config/filesystems.php file. Defaults to 's3'.
    |
    */
    'disk' => env('PRUNEKEEPER_DISK', 's3'),

    /*
    |--------------------------------------------------------------------------
    | Storage Path
    |--------------------------------------------------------------------------
    |
    | The base path within the storage disk where archived exports will be
    | stored. Files will be named with timestamps and table names.
    |
    */
    'path' => env('PRUNEKEEPER_PATH', 'prunable-exports'),

    /*
    |--------------------------------------------------------------------------
    | Export Format
    |--------------------------------------------------------------------------
    |
    | The format to use when exporting records. Supported formats:
    | - "csv": Comma-separated values (recommended for large datasets)
    | - "sql": SQL INSERT statements (useful for direct database restoration)
    |
    */
    'format' => env('PRUNEKEEPER_FORMAT', 'csv'),

    /*
    |--------------------------------------------------------------------------
    | Compression
    |--------------------------------------------------------------------------
    |
    | Whether to compress the exported file using ZIP compression before
    | uploading to storage. Recommended for reducing storage costs.
    |
    */
    'compress' => env('PRUNEKEEPER_COMPRESS', true),

    /*
    |--------------------------------------------------------------------------
    | Chunk Size
    |--------------------------------------------------------------------------
    |
    | The number of records to process at a time when exporting. Larger values
    | may improve performance but use more memory.
    |
    */
    'chunk_size' => env('PRUNEKEEPER_CHUNK_SIZE', 1000),

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Whether archiving is enabled. When disabled, models will be pruned
    | without being archived first.
    |
    */
    'enabled' => env('PRUNEKEEPER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Fail Silently
    |--------------------------------------------------------------------------
    |
    | When true, archiving failures will be logged but pruning will continue.
    | When false (default), an exception will be thrown and pruning will be
    | aborted if archiving fails.
    |
    */
    'fail_silently' => env('PRUNEKEEPER_FAIL_SILENTLY', false),

    /*
    |--------------------------------------------------------------------------
    | Cleanup Temporary Files
    |--------------------------------------------------------------------------
    |
    | Whether to automatically delete temporary files after uploading to
    | storage. Should generally be true unless debugging.
    |
    */
    'cleanup_temp_files' => true,

    /*
    |--------------------------------------------------------------------------
    | File Open Mode
    |--------------------------------------------------------------------------
    |
    | The mode to use when opening temporary files for writing during export.
    | Common modes: 'w' (write), 'w+' (read/write), 'wb' (binary write).
    |
    */
    'file_open_mode' => env('PRUNEKEEPER_FILE_OPEN_MODE', 'w'),
];
