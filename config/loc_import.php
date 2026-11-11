<?php

return [
    'max_file_kilobytes' => (int) env('LOC_IMPORT_MAX_KB', 81920),
    'read_chunk_size' => (int) env('LOC_IMPORT_READ_CHUNK', 5000),
    'write_chunk_size' => (int) env('LOC_IMPORT_WRITE_CHUNK', 250),
    'queue_connection' => env('LOC_IMPORT_QUEUE_CONNECTION', 'loc_import'),
    'queue' => env('LOC_IMPORT_QUEUE', 'loc-imports'),
    'weapons_read_chunk_size' => (int) env('LOC_WEAPONS_IMPORT_READ_CHUNK', 5000),
    'weapons_write_chunk_size' => (int) env('LOC_WEAPONS_IMPORT_WRITE_CHUNK', 100),
    'weapons_queue_connection' => env('LOC_WEAPONS_IMPORT_QUEUE_CONNECTION', 'loc_weapons_import'),
    'weapons_queue' => env('LOC_WEAPONS_IMPORT_QUEUE', 'loc-weapons-imports'),
    'weapons_reuse_ole' => (bool) env('LOC_WEAPONS_IMPORT_REUSE_OLE', true),
    'credentials_read_chunk_size' => (int) env('LOC_CREDENTIALS_IMPORT_READ_CHUNK', 5000),
    'credentials_write_chunk_size' => (int) env('LOC_CREDENTIALS_IMPORT_WRITE_CHUNK', 100),
    'credentials_queue_connection' => env('LOC_CREDENTIALS_IMPORT_QUEUE_CONNECTION', 'loc_credentials_import'),
    'credentials_queue' => env('LOC_CREDENTIALS_IMPORT_QUEUE', 'loc-credentials-imports'),
    'credentials_reuse_ole' => (bool) env('LOC_CREDENTIALS_IMPORT_REUSE_OLE', true),
];
