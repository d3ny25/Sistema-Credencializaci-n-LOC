<?php

$userIds = static function (string $key): array {
    return array_values(array_filter(
        array_map('intval', explode(',', (string) env($key, ''))),
        static fn (int $id): bool => $id > 0,
    ));
};

return [
    // Agente local de la estación (agente-estacion/): solo escucha en 127.0.0.1. El secreto se comparte con el
    // agente al instalarlo y firma los tickets de captura; sin secreto, la captura por agente queda deshabilitada.
    'agente_estacion' => [
        'url' => env('ESTACION_AGENTE_URL', 'http://127.0.0.1:47391'),
        'secreto' => env('ESTACION_AGENTE_SECRETO'),
        'ticket_segundos' => (int) env('ESTACION_AGENTE_TICKET_SEGUNDOS', 600),
        // Cola de Windows de la impresora de credenciales (comprobada en la estación con Get-Printer, 15-11-2026).
        'cola_impresora' => env('ESTACION_IMPRESORA_COLA', 'Zebra ZXP Series 7 USB Card Printer'),
    ],
    'permissions' => [
        'viewer_user_ids' => $userIds('DEVICE_VIEWER_IDS'),
        'manager_user_ids' => $userIds('DEVICE_MANAGER_IDS'),
        'tester_user_ids' => $userIds('DEVICE_TESTER_IDS'),
        'administrator_user_ids' => $userIds('DEVICE_ADMINISTRATOR_IDS'),
    ],
    'types' => [
        // Impresora real de la estación: cola "Zebra ZXP Series 7 USB Card Printer", controlador zxps7.inf 3.2.9.0, USB001.
        'zebra_zxp_series_7' => [
            'label' => 'Impresora de credenciales',
            'name' => 'Zebra ZXP Series 7',
            'manufacturer' => 'Zebra',
            'model' => 'ZXP Series 7',
            'connection_methods' => [
                'windows_print_dialog' => 'Diálogo de impresión + controlador Zebra de Windows (seguimiento de cola con el agente de estación)',
            ],
            'configuration' => [
                'offset_x_mm' => [
                    'label' => 'Ajuste horizontal (mm)',
                    'input' => 'number',
                    'step' => '0.1',
                    'rules' => ['numeric', 'between:-10,10'],
                ],
                'offset_y_mm' => [
                    'label' => 'Ajuste vertical (mm)',
                    'input' => 'number',
                    'step' => '0.1',
                    'rules' => ['numeric', 'between:-10,10'],
                ],
            ],
        ],
        'zebra_zxp_series_8' => [
            'label' => 'Impresora de credenciales',
            'name' => 'Zebra ZXP Series 8',
            'manufacturer' => 'Zebra',
            'model' => 'ZXP Series 8',
            'connection_methods' => [
                'windows_print_dialog' => 'Diálogo de impresión + controlador Zebra de Windows',
            ],
            'configuration' => [
                'offset_x_mm' => [
                    'label' => 'Ajuste horizontal (mm)',
                    'input' => 'number',
                    'step' => '0.1',
                    'rules' => ['numeric', 'between:-10,10'],
                ],
                'offset_y_mm' => [
                    'label' => 'Ajuste vertical (mm)',
                    'input' => 'number',
                    'step' => '0.1',
                    'rules' => ['numeric', 'between:-10,10'],
                ],
            ],
        ],
        'canon_eos_rebel_t6' => [
            'label' => 'Cámara',
            'name' => 'Canon EOS Rebel T6',
            'manufacturer' => 'Canon',
            'model' => 'EOS Rebel T6',
            'connection_methods' => [
                'eos_utility_folder' => 'EOS Utility guarda las capturas en una carpeta local',
            ],
            'configuration' => [],
        ],
        'topaz_siglite_lcd_1x5' => [
            'label' => 'Pad de firma',
            'name' => 'Topaz SigLite LCD 1x5',
            'manufacturer' => 'Topaz',
            'model' => 'SigLite LCD 1x5',
            'connection_methods' => [
                'sigweb_local_service' => 'Servicio local SigWeb en la estación del operador',
            ],
            'configuration' => [],
        ],
        'crossmatch_verifier_300_lc_2_0' => [
            'label' => 'Lector de huellas',
            'name' => 'Crossmatch Verifier 300 LC 2.0',
            'manufacturer' => 'Crossmatch',
            'model' => 'Verifier 300 LC 2.0',
            'connection_methods' => [
                'vendor_export_folder' => 'Programa del fabricante exporta capturas a una carpeta local',
            ],
            'configuration' => [
                'capture_format' => [
                    'label' => 'Formato de exportación',
                    'input' => 'select',
                    'options' => ['BMP' => 'BMP', 'PNG' => 'PNG'],
                    'rules' => ['string', 'in:BMP,PNG'],
                ],
            ],
        ],
    ],
];
