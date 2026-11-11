<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Prefijo Institucional de Tarjetas de Credencialización
    |--------------------------------------------------------------------------
    | Define el prefijo que antecede al periodo y consecutivo (ej. 'CE/TA').
    */
    'tarjeta_prefijo' => env('CREDENCIAL_TARJETA_PREFIJO', 'CE/TA'),

    /*
    |--------------------------------------------------------------------------
    | Periodo de Vigencia Institucional
    |--------------------------------------------------------------------------
    | Define el periodo activo de la credencial (ej. '25-27').
    | Al actualizarse a una nueva vigencia (ej. '27-29'), la numeración
    | continúa ininterrumpida sin reiniciar el contador global.
    */
    'tarjeta_periodo' => env('CREDENCIAL_TARJETA_PERIODO', '25-27'),

    /*
    |--------------------------------------------------------------------------
    | Consecutivo Inicial Obligatorio
    |--------------------------------------------------------------------------
    | Valor numérico que debe recibir la primera tarjeta expedida bajo la
    | nueva secuencia institucional (50001).
    */
    'tarjeta_consecutivo_inicial' => (int) env('CREDENCIAL_TARJETA_CONSECUTIVO_INICIAL', 50001),

    /*
    |--------------------------------------------------------------------------
    | Clave de Secuencia en Base de Datos
    |--------------------------------------------------------------------------
    | Clave primaria unívoca que identifica la fila en la tabla
    | `solicitud_secuencias`.
    */
    'secuencia_clave' => env('CREDENCIAL_SECUENCIA_CLAVE', 'tarjeta_credencial'),

    /*
    |--------------------------------------------------------------------------
    | Relleno Mínimo de Dígitos para el Consecutivo
    |--------------------------------------------------------------------------
    | Longitud mínima numérica formateada con ceros a la izquierda (5 dígitos).
    */
    'consecutivo_min_digits' => (int) env('CREDENCIAL_CONSECUTIVO_MIN_DIGITS', 5),

    /*
    |--------------------------------------------------------------------------
    | Año Institucional de Expedición
    |--------------------------------------------------------------------------
    | Permite fijar un año institucional específico para auditorías o pruebas,
    | o null para utilizar el año corriente del servidor.
    */
    'anio_expedicion_fijo' => env('CREDENCIAL_ANIO_FIJO', null),
];

