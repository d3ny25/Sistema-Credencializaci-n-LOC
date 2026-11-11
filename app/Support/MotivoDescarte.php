<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Catálogo centralizado de motivos de descarte y excepciones en Credencialización LOC.
 * Provee códigos estables, etiquetas institucionales legibles y descripciones para
 * desacoplar el motor de prefiltro de los textos visibles en la interfaz.
 */
final class MotivoDescarte
{
    public const CUIP_VACIO = 'CUIP_VACIO';
    public const CUIP_DUPLICADO = 'CUIP_DUPLICADO';
    public const CUIP_NO_LOCALIZADO = 'CUIP_NO_LOCALIZADO';
    public const EXP_ALTA_ACTIVA = 'EXP_ALTA_ACTIVA';
    public const EXP_SITUACION_ESPECIAL = 'EXP_SITUACION_ESPECIAL';
    public const EXP_CANCELACION = 'EXP_CANCELACION';
    public const EXP_ESTATUS_DESCONOCIDO = 'EXP_ESTATUS_DESCONOCIDO';
    public const ARM_SIN_ASIGNACION = 'ARM_SIN_ASIGNACION';
    public const ARM_CORTA_NO_LOCALIZADA = 'ARM_CORTA_NO_LOCALIZADA';
    public const ARM_CORTA_ESTATUS_INVALIDO = 'ARM_CORTA_ESTATUS_INVALIDO';
    public const ARM_CORTA_CUPO_EXCEDIDO = 'ARM_CORTA_CUPO_EXCEDIDO';
    public const ARM_LARGA_NO_LOCALIZADA = 'ARM_LARGA_NO_LOCALIZADA';
    public const ARM_LARGA_ESTATUS_INVALIDO = 'ARM_LARGA_ESTATUS_INVALIDO';
    public const ARM_LARGA_CUPO_EXCEDIDO = 'ARM_LARGA_CUPO_EXCEDIDO';
    public const MOT_SIN_CLASIFICAR = 'MOT_SIN_CLASIFICAR';

    /**
     * Definición institucional de códigos, etiquetas y descripciones.
     *
     * @var array<string, array{label: string, description: string, category: string}>
     */
    private const CATALOGO = [
        self::CUIP_VACIO => [
            'label' => 'CUIP no proporcionado',
            'description' => 'El elemento no cuenta con una clave CUIP o presenta un marcador de ausencia.',
            'category' => 'Identidad Institucional',
        ],
        self::CUIP_DUPLICADO => [
            'label' => 'CUIP duplicado',
            'description' => 'El CUIP se encuentra duplicado en dos o más filas de la misma solicitud.',
            'category' => 'Integridad de Solicitud',
        ],
        self::CUIP_NO_LOCALIZADO => [
            'label' => 'Persona no localizada',
            'description' => 'El CUIP no fue localizado en el padrón oficial vigente de Personal LOC.',
            'category' => 'Personal Autorizado',
        ],
        self::EXP_ALTA_ACTIVA => [
            'label' => 'Credencial activa con ALTA',
            'description' => 'El elemento cuenta con una credencial oficial activa con estatus ALTA y sin fecha de baja.',
            'category' => 'Vigencias de Credenciales',
        ],
        self::EXP_SITUACION_ESPECIAL => [
            'label' => 'Situación Especial',
            'description' => 'El antecedente oficial más reciente sitúa al elemento en Situación Especial.',
            'category' => 'Vigencias de Credenciales',
        ],
        self::EXP_CANCELACION => [
            'label' => 'Credencial cancelada',
            'description' => 'El último antecedente oficial del elemento corresponde a una cancelación de credencial.',
            'category' => 'Vigencias de Credenciales',
        ],
        self::EXP_ESTATUS_DESCONOCIDO => [
            'label' => 'Estatus de credencial no reconocido',
            'description' => 'El estatus de la credencial oficial más reciente no es reconocido para la tramitación ordinaria.',
            'category' => 'Vigencias de Credenciales',
        ],
        self::ARM_SIN_ASIGNACION => [
            'label' => 'Sin asignación de armamento',
            'description' => 'El elemento no cuenta con ninguna matrícula de arma asignada en la solicitud.',
            'category' => 'Armamento',
        ],
        self::ARM_CORTA_NO_LOCALIZADA => [
            'label' => 'Arma corta no localizada',
            'description' => 'El arma corta solicitada no fue localizada en el padrón oficial de armamento LOC.',
            'category' => 'Armamento',
        ],
        self::ARM_CORTA_ESTATUS_INVALIDO => [
            'label' => 'Estatus no válido de arma corta',
            'description' => 'El arma corta tiene un estatus distinto de FÍSICA en el padrón de armamento.',
            'category' => 'Armamento',
        ],
        self::ARM_CORTA_CUPO_EXCEDIDO => [
            'label' => 'Cupo excedido de arma corta',
            'description' => 'El arma corta excede el límite institucional de 3 asignaciones activas.',
            'category' => 'Armamento',
        ],
        self::ARM_LARGA_NO_LOCALIZADA => [
            'label' => 'Arma larga no localizada',
            'description' => 'El arma larga solicitada no fue localizada en el padrón oficial de armamento LOC.',
            'category' => 'Armamento',
        ],
        self::ARM_LARGA_ESTATUS_INVALIDO => [
            'label' => 'Estatus no válido de arma larga',
            'description' => 'El arma larga tiene un estatus distinto de FÍSICA en el padrón de armamento.',
            'category' => 'Armamento',
        ],
        self::ARM_LARGA_CUPO_EXCEDIDO => [
            'label' => 'Cupo excedido de arma larga',
            'description' => 'El arma larga excede el límite institucional de 3 asignaciones activas.',
            'category' => 'Armamento',
        ],
        self::MOT_SIN_CLASIFICAR => [
            'label' => 'Motivo histórico sin clasificar',
            'description' => 'Motivo de descarte histórico cuyo texto no pudo ser asociado de forma unívoca a una regla del motor.',
            'category' => 'Histórico Residual',
        ],
    ];

    /**
     * Retorna todos los códigos válidos.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_keys(self::CATALOGO);
    }

    /**
     * Determina si un código es válido.
     */
    public static function isValid(string $code): bool
    {
        return isset(self::CATALOGO[$code]);
    }

    /**
     * Retorna la etiqueta institucional legible para el usuario.
     */
    public static function label(string $code): string
    {
        return self::CATALOGO[$code]['label'] ?? $code;
    }

    /**
     * Retorna la descripción institucional del motivo.
     */
    public static function description(string $code): string
    {
        return self::CATALOGO[$code]['description'] ?? '';
    }

    /**
     * Retorna la categoría institucional del motivo.
     */
    public static function category(string $code): string
    {
        return self::CATALOGO[$code]['category'] ?? '';
    }

    /**
     * Retorna el catálogo completo con metadatos.
     *
     * @return array<string, array{label: string, description: string, category: string}>
     */
    public static function catalog(): array
    {
        return self::CATALOGO;
    }

    /**
     * Retorna un mapa asociativo de [codigo => etiqueta institucional] para componentes select o filtros.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::CATALOGO as $code => $meta) {
            $options[$code] = $meta['label'];
        }

        return $options;
    }
}

