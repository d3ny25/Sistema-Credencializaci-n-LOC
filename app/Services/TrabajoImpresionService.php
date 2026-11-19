<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BiometricoAuditoria;
use App\Models\CredencialPreparacion;
use App\Models\User;
use App\Support\CapturaTicket;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Envío de una preparación a la impresora de credenciales y seguimiento de su trabajo en la cola de Windows.
 *
 * El contenido lo imprime el navegador desde la vista de snapshot; el agente de estación solo observa la cola.
 * Nada de lo que se registra aquí cambia la preparación ni la credencial: el resultado físico lo sigue confirmando
 * el operador mediante CredencialPreparacionService::confirmar().
 */
class TrabajoImpresionService
{
    public const EVENTO_AUTORIZADO = 'trabajo_impresion_autorizado';

    public const EVENTO_ESTADO = 'trabajo_impresion_estado';

    /** Estados de la cola de Windows que la estación puede informar (ver agente-estacion/src/Impresion.cs). */
    public const ESTADOS = ['no_encontrado', 'en_cola', 'imprimiendo', 'completado_en_cola', 'salio_de_cola', 'error', 'eliminado', 'en_otra_cola'];

    public function __construct(private readonly CredencialPreparacionService $preparaciones) {}

    public function enviosPrevios(CredencialPreparacion $preparacion): int
    {
        return BiometricoAuditoria::query()
            ->where('evento', self::EVENTO_AUTORIZADO)
            ->where('detalles->preparacion_uuid', $preparacion->uuid)
            ->count();
    }

    /**
     * Autoriza un envío a imprimir. Un reenvío de la misma preparación exige motivo (evita tarjetas duplicadas).
     *
     * @return array{trabajo: string, documento: string, ticket: string|null, cola: string, envio: int}
     */
    public function autorizar(CredencialPreparacion $preparacion, User $actor, ?string $motivoReenvio): array
    {
        $this->preparaciones->expirarSiCorresponde($preparacion);
        $preparacion->refresh()->load(['elemento.solicitud', 'credencial', 'impresion', 'expediente']);

        if (! $preparacion->estaPreparada() || ! $preparacion->vistaVigente()) {
            throw new ConflictHttpException('La preparación ya no está vigente para imprimir; prepare nuevamente.');
        }
        if ($preparacion->impresion !== null) {
            throw new ConflictHttpException('Esta preparación ya tiene un resultado registrado.');
        }
        $this->preparaciones->validarImprimible($preparacion->elemento, $preparacion->credencial);
        // Mismo requisito que la confirmación "impresa": la vista debió servir las cuatro evidencias del snapshot.
        if ($this->preparaciones->evidenciasPendientes($preparacion) !== []) {
            throw new ConflictHttpException('La vista aún no ha cargado las cuatro evidencias biométricas; recárguela antes de imprimir.');
        }

        $previos = $this->enviosPrevios($preparacion);
        if ($previos > 0 && blank($motivoReenvio)) {
            throw new ConflictHttpException('Esta preparación ya se envió a imprimir. Justifique el reenvío para evitar una tarjeta duplicada.');
        }

        // Con agente configurado, el nonce del ticket identifica el documento en la cola; sin él no hay seguimiento.
        $ticket = CapturaTicket::configurado() ? CapturaTicket::emitir($preparacion->elemento, $actor, 'impresion') : null;
        $trabajo = $ticket !== null ? CapturaTicket::nonce($ticket) : bin2hex(random_bytes(16));
        $cola = (string) config('devices.agente_estacion.cola_impresora');

        BiometricoAuditoria::registrarAcceso(
            evento: self::EVENTO_AUTORIZADO,
            actor: $actor,
            expediente: $preparacion->expediente,
            elemento: $preparacion->elemento,
            detalles: [
                'preparacion_uuid' => $preparacion->uuid,
                'tipo_operacion' => $preparacion->tipo_operacion,
                'trabajo' => $trabajo,
                'envio' => $previos + 1,
                'motivo_reenvio' => $previos > 0 ? $motivoReenvio : null,
                'seguimiento' => $ticket !== null ? 'agente_estacion' : 'sin_agente',
                'cola_esperada' => $cola,
            ],
        );

        return ['trabajo' => $trabajo, 'documento' => 'LOC139-'.$trabajo, 'ticket' => $ticket, 'cola' => $cola, 'envio' => $previos + 1];
    }

    /**
     * Registra el estado de la cola informado por la estación. Devuelve false si no cambió desde el último registro.
     *
     * @param  array{estado: string, job_id?: int|null, cola?: string|null, alertas?: list<string>, simulacion?: bool}  $datos
     */
    public function registrarEstado(CredencialPreparacion $preparacion, User $actor, string $trabajo, array $datos): bool
    {
        $autorizado = BiometricoAuditoria::query()
            ->where('evento', self::EVENTO_AUTORIZADO)
            ->where('detalles->preparacion_uuid', $preparacion->uuid)
            ->where('detalles->trabajo', $trabajo)
            ->exists();
        if (! $autorizado) {
            throw new UnprocessableEntityHttpException('El trabajo de impresión no corresponde a esta preparación.');
        }

        $ultimo = BiometricoAuditoria::query()
            ->where('evento', self::EVENTO_ESTADO)
            ->where('detalles->trabajo', $trabajo)
            ->latest('id')
            ->first();
        $jobId = $datos['job_id'] ?? null;
        if ($ultimo !== null && ($ultimo->detalles['estado'] ?? null) === $datos['estado'] && ($ultimo->detalles['job_id'] ?? null) === $jobId) {
            return false;
        }

        $colaEsperada = (string) config('devices.agente_estacion.cola_impresora');
        $cola = $datos['cola'] ?? null;
        $preparacion->loadMissing(['elemento', 'expediente']);

        BiometricoAuditoria::registrarAcceso(
            evento: self::EVENTO_ESTADO,
            actor: $actor,
            expediente: $preparacion->expediente,
            elemento: $preparacion->elemento,
            detalles: [
                'preparacion_uuid' => $preparacion->uuid,
                'trabajo' => $trabajo,
                'estado' => $datos['estado'],
                'job_id' => $jobId,
                'cola' => $cola,
                'cola_esperada' => $cola === null || $cola === $colaEsperada,
                'alertas' => array_values($datos['alertas'] ?? []),
                'simulacion' => (bool) ($datos['simulacion'] ?? false),
                'fuente' => 'agente_estacion',
                'nota' => 'Estado de la cola de Windows informado por la estación; no confirma que la tarjeta física se imprimió.',
            ],
        );

        return true;
    }
}
