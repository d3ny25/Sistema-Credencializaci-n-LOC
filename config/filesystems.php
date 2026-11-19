<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        'biometricos' => [
            'driver' => 'safe-local-readonly',
            'root' => env('BIOMETRICOS_STORAGE_PATH'),
            'throw' => true,
            'report' => true,
            'serve' => false,
        ],

        'biometricos_ingesta' => [
            'driver' => 'local',
            'root' => storage_path('app/biometricos_ingesta'),
            'throw' => false,
            'report' => false,
            'serve' => false,
        ],

        // Evidencia histórica de impresión (Fase 1B.2): privada, direccionada por SHA-256, sin URL pública.
        // Debe ser un disco local: la publicación usa link() dentro del mismo volumen NTFS.
        'biometricos_evidencia' => [
            'driver' => 'local',
            'root' => storage_path('app/biometricos_evidencia'),
            'throw' => true,
            'report' => false,
            'serve' => false,
        ],

        // Expediente documental de Entrega y Seguimiento (Iteración 1.4): privado, sin URL pública;
        // solo se sirve por rutas autenticadas que verifican el expediente. Nombres internos UUID.
        'documentos_entrega' => [
            'driver' => 'local',
            'root' => storage_path('app/documentos_entrega'),
            'throw' => true,
            'report' => false,
            'serve' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
