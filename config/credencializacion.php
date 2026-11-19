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

    /*
    |--------------------------------------------------------------------------
    | Folio Institucional de Credencial
    |--------------------------------------------------------------------------
    | Formato: {prefijo}{periodo sin guion}{separador}{consecutivo}, p. ej. OM2527E00001.
    | El periodo es el mismo de las tarjetas (tarjeta_periodo); el consecutivo es
    | global y no se reinicia al cambiar de periodo (regla institucional confirmada).
    | Un folio asignado nunca se libera ni se reutiliza: la secuencia solo avanza y una
    | credencial cancelada conserva su folio. `folio_ultimo_consecutivo` es el
    | último folio institucional ya utilizado (OM2527E00000 → 0) y solo se usa para
    | inicializar la secuencia cuando todavía no existe.
    */
    'folio_prefijo' => env('CREDENCIAL_FOLIO_PREFIJO', 'OM'),
    'folio_separador' => env('CREDENCIAL_FOLIO_SEPARADOR', 'E'),
    'folio_digitos' => (int) env('CREDENCIAL_FOLIO_DIGITOS', 5),
    'folio_secuencia_clave' => env('CREDENCIAL_FOLIO_SECUENCIA_CLAVE', 'folio_credencial'),
    'folio_ultimo_consecutivo' => (int) env('CREDENCIAL_FOLIO_ULTIMO_CONSECUTIVO', 0),

    /*
    |--------------------------------------------------------------------------
    | Preparación de impresión y evidencia histórica (Fase 1B.2)
    |--------------------------------------------------------------------------
    | Vigencias autorizadas: la vista sirve imágenes 30 min; la preparación vence a las 24 h;
    | una impresión física puede confirmarse extemporáneamente hasta 72 h después de la
    | primera visualización. `app_release` identifica el despliegue en la huella de
    | renderizado (opcional; nulo si no se define en el despliegue).
    */
    'impresion' => [
        'vista_minutos' => 30,
        'preparacion_horas' => 24,
        'extemporanea_horas' => 72,
        'app_release' => env('APP_RELEASE'),
    ],

    /*
    | Expediente documental (Entrega y Seguimiento): tamaño máximo por archivo en KB. 20 MB cubre oficios
    | escaneados y está por debajo de upload_max_filesize (80M) y post_max_size (96M) del servidor.
    */
    'documentos' => [
        'max_kb' => 20480,
    ],
];

