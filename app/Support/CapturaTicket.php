<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SolicitudElemento;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Ticket efímero de un solo uso que liga una captura del agente de estación a un elemento, un usuario y una categoría.
 * Formato: base64url(carga JSON) "." base64url(HMAC-SHA256 de la carga codificada con el secreto de la estación).
 * El agente lo consume al abrir la sesión de cámara; Laravel lo consume al recibir la fotografía.
 */
final class CapturaTicket
{
    public const VERSION = 1;

    /** Componentes del expediente que el agente puede capturar: cámara (foto) y lector de huellas (pulgares). */
    public const CATEGORIAS = ['foto', 'pulgar_derecho', 'pulgar_izquierdo'];

    /** Marcador tEXt que el agente pone en sus PNG simulados; una imagen así nunca se acepta como evidencia. */
    public const MARCADOR_SIMULACION = 'tEXtSimulacion';

    public static function configurado(): bool
    {
        return filled(config('devices.agente_estacion.secreto'));
    }

    public static function emitir(SolicitudElemento $elemento, User $usuario, string $categoria): string
    {
        $carga = self::b64(json_encode([
            'v' => self::VERSION,
            'n' => bin2hex(random_bytes(16)),
            'e' => (int) $elemento->id,
            'u' => (int) $usuario->id,
            'c' => $categoria,
            'x' => now()->getTimestamp() + max(30, (int) config('devices.agente_estacion.ticket_segundos', 600)),
        ], JSON_THROW_ON_ERROR));

        return $carga.'.'.self::b64(self::firma($carga));
    }

    /**
     * Verifica el ticket y lo consume. Devuelve null si es válido o el motivo del rechazo.
     */
    public static function consumir(string $ticket, SolicitudElemento $elemento, User $usuario, string $categoria): ?string
    {
        if (! self::configurado()) {
            return 'La captura por agente de estación no está configurada en el servidor.';
        }

        $partes = explode('.', $ticket);
        if (count($partes) !== 2 || ! hash_equals(self::b64(self::firma($partes[0])), $partes[1])) {
            return 'El ticket de captura no es válido.';
        }

        $datos = json_decode((string) self::desdeB64($partes[0]), true);
        if (! is_array($datos) || ($datos['v'] ?? null) !== self::VERSION || ! is_string($datos['n'] ?? null)) {
            return 'El ticket de captura no es válido.';
        }
        if ((int) ($datos['x'] ?? 0) < now()->getTimestamp()) {
            return 'El ticket de captura expiró; repita la captura.';
        }
        if ((int) ($datos['e'] ?? 0) !== (int) $elemento->id) {
            return 'La captura se inició para otro elemento; no se guardó. Repita la captura con el elemento seleccionado.';
        }
        if ((int) ($datos['u'] ?? 0) !== (int) $usuario->id || ($datos['c'] ?? null) !== $categoria) {
            return 'El ticket de captura no corresponde a este usuario o componente biométrico.';
        }

        // Cache::add solo escribe si la clave no existe: un ticket ya usado se rechaza.
        $vigencia = max(60, (int) $datos['x'] - now()->getTimestamp() + 60);
        if (! Cache::add('captura_ticket:'.$datos['n'], true, $vigencia)) {
            return 'Este ticket de captura ya se utilizó; repita la captura.';
        }

        return null;
    }

    /**
     * Revierte el consumo de un ticket cuyo guardado falló después de validarse, para que la misma captura pueda
     * reintentarse. Solo se llama si no se registró nada: un guardado exitoso deja el ticket consumido.
     */
    public static function liberar(string $ticket): void
    {
        $nonce = self::nonce($ticket);
        if ($nonce !== '') {
            Cache::forget('captura_ticket:'.$nonce);
        }
    }

    /** Nonce de un ticket emitido por este servidor (identificador del trabajo de impresión en la cola). */
    public static function nonce(string $ticket): string
    {
        $datos = json_decode((string) self::desdeB64(explode('.', $ticket)[0]), true);

        return (string) ($datos['n'] ?? '');
    }

    private static function firma(string $cargaCodificada): string
    {
        return hash_hmac('sha256', $cargaCodificada, (string) config('devices.agente_estacion.secreto'), true);
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function desdeB64(string $texto): string|false
    {
        return base64_decode(strtr($texto, '-_', '+/'), true);
    }
}
