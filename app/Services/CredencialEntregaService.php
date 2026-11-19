<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CredencialEmitida;
use App\Models\CredencialEntrega;
use App\Models\CredencialImpresion;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registro de entregas físicas (Iteración 1.3). La elegibilidad es la de SeguimientoTarjetaService::estadoEntrega,
 * reevaluada dentro de la transacción con datos recién leídos; el responsable y la fecha los fija el servidor.
 *
 * Orden de bloqueo: tarjeta (serializa las entregas del mismo expediente) → elementos → credenciales, compatible con
 * R5 (elemento → credencial → …) de CredencialPreparacionService. Última barrera: unicidad de credencial_impresion_id
 * y el trigger de validez de credencial_entrega_detalles.
 */
class CredencialEntregaService
{
    public function __construct(private readonly SeguimientoTarjetaService $seguimiento) {}

    /**
     * Credenciales disponibles (una tarjeta física por elemento) y no disponibles con su motivo.
     *
     * @return array{disponibles: Collection<int, array{elemento: SolicitudElemento, impresion: CredencialImpresion}>, no_disponibles: Collection<int, array{elemento: SolicitudElemento, estado: string, motivo: string}>}
     */
    public function opciones(SolicitudCredencial $tarjeta): array
    {
        $estados = $tarjeta->elementos->map(fn (SolicitudElemento $e): array => ['elemento' => $e, ...$this->seguimiento->estadoEntrega($e)]);
        // Advertencia, no bloqueo: la incidencia no tiene regla institucional de entrega (ver CredencialPreparacionService::confirmar).
        $duplicadas = $this->seguimiento->preparacionesConPosibleDuplicado($tarjeta->elementos);

        return [
            'disponibles' => $estados->where('estado', SeguimientoTarjetaService::ENTREGA_DISPONIBLE)->map(static fn (array $f): array => [
                'elemento' => $f['elemento'],
                'impresion' => $f['impresion'],
                'posible_duplicado' => ($f['elemento']->credencialEmitida?->impresiones->pluck('credencial_preparacion_id')->filter() ?? collect())->intersect($duplicadas)->isNotEmpty(),
            ])->values(),
            // Solo elementos con credencial o impresiones: los pendientes de etapas previas se consultan en Pendientes.
            'no_disponibles' => $estados->reject(static fn (array $f): bool => $f['estado'] === SeguimientoTarjetaService::ENTREGA_DISPONIBLE
                || ($f['estado'] === SeguimientoTarjetaService::ENTREGA_SIN_IMPRESION && $f['elemento']->credencialEmitida === null))
                ->map(static fn (array $f): array => ['elemento' => $f['elemento'], 'estado' => $f['estado'], 'motivo' => $f['motivo']])->values(),
        ];
    }

    /**
     * Valida la selección contra el estado actual y devuelve las opciones elegidas (para confirmar o registrar).
     *
     * @param  list<int>  $impresionIds
     * @return Collection<int, array{elemento: SolicitudElemento, impresion: CredencialImpresion}>
     */
    public function seleccion(SolicitudCredencial $tarjeta, array $impresionIds): Collection
    {
        $disponibles = $this->opciones($tarjeta)['disponibles']->keyBy(static fn (array $f): int => $f['impresion']->id);
        $invalidas = collect($impresionIds)->reject(static fn (int $id): bool => $disponibles->has($id));
        if ($invalidas->isNotEmpty()) {
            throw ValidationException::withMessages(['impresiones' => $this->motivoRechazo($tarjeta, $invalidas->all())]);
        }

        return collect($impresionIds)->map(static fn (int $id): array => $disponibles[$id])->values();
    }

    /**
     * @param  list<int>  $impresionIds
     * @return array{entrega: CredencialEntrega, repetida: bool}
     */
    public function registrar(SolicitudCredencial $tarjeta, array $impresionIds, string $receptor, string $clave, User $actor): array
    {
        try {
            return DB::transaction(function () use ($tarjeta, $impresionIds, $receptor, $clave, $actor): array {
                // Tras el bloqueo de la tarjeta: un doble envío concurrente espera aquí y luego ve la operación ya creada.
                $bloqueada = SolicitudCredencial::query()->whereKey($tarjeta->id)->lockForUpdate()->firstOrFail();
                $previa = CredencialEntrega::query()->where('clave_operacion', $clave)->first();
                if ($previa !== null) {
                    // Doble envío de la misma confirmación: se devuelve la operación ya registrada, sin duplicarla.
                    if ((int) $previa->solicitud_id !== (int) $tarjeta->id) {
                        throw ValidationException::withMessages(['impresiones' => 'La clave de la operación no corresponde a esta tarjeta. Inicie de nuevo la entrega.']);
                    }

                    return ['entrega' => $previa, 'repetida' => true];
                }

                $elementoIds = SolicitudElemento::query()->where('solicitud_id', $bloqueada->id)->orderBy('id')->lockForUpdate()->pluck('id');
                CredencialEmitida::query()->whereIn('solicitud_elemento_id', $elementoIds)->orderBy('id')->lockForUpdate()->get(['id']);

                $seleccion = $this->seleccion($this->seguimiento->cargarTarjeta($bloqueada), $impresionIds);

                $entrega = CredencialEntrega::create([
                    'solicitud_id' => $bloqueada->id,
                    'receptor_nombre' => $receptor,
                    'entregado_por_user_id' => $actor->id,
                    'entregada_at' => now(),
                    'clave_operacion' => $clave,
                ]);
                foreach ($seleccion as $fila) {
                    $entrega->detalles()->create(['credencial_impresion_id' => $fila['impresion']->id]);
                }

                return ['entrega' => $entrega, 'repetida' => false];
            });
        } catch (UniqueConstraintViolationException) {
            // Otra operación concurrente registró la misma tarjeta física (o la misma clave) primero.
            throw ValidationException::withMessages(['impresiones' => 'Otra operación registró al mismo tiempo la entrega de alguna de estas credenciales. No se guardó ningún cambio; revise la selección.']);
        }
    }

    /** Mensaje de negocio para identificadores que dejaron de ser elegibles o no pertenecen a la tarjeta. */
    private function motivoRechazo(SolicitudCredencial $tarjeta, array $ids): string
    {
        $impresiones = CredencialImpresion::query()->whereIn('id', $ids)->with('credencial:id,solicitud_id,folio')->get()->keyBy('id');
        $motivos = collect($ids)->map(function (int $id) use ($tarjeta, $impresiones): string {
            $impresion = $impresiones->get($id);
            if ($impresion === null || (int) $impresion->credencial?->solicitud_id !== (int) $tarjeta->id) {
                return "El registro de impresión #{$id} no pertenece a esta tarjeta.";
            }
            $elemento = $tarjeta->elementos->firstWhere('id', $impresion->solicitud_elemento_id);
            $estado = $elemento ? $this->seguimiento->estadoEntrega($elemento) : null;
            $motivo = $estado === null ? 'ya no está disponible' : ($estado['estado'] === SeguimientoTarjetaService::ENTREGA_DISPONIBLE
                ? 'corresponde a otra tarjeta física del mismo elemento' : mb_lcfirst(rtrim((string) $estado['motivo'], '.')));

            return 'Folio '.($impresion->credencial?->folio ?? '—')." (impresión #{$id}): {$motivo}.";
        });

        return 'No se registró la entrega. '.$motivos->implode(' ');
    }
}
