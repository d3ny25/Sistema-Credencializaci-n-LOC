<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EstadoEmision;
use App\Models\BiometricoIncidencia;
use App\Models\CredencialImpresion;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Entrega y Seguimiento — consulta del avance de los elementos de un número de tarjeta.
 *
 * Solo lectura: no ejecuta validaciones que escriban (la validación biométrica 4/4 vive en la cola de impresión)
 * y nunca crea ni cambia emisiones, impresiones o entregas. Cada cifra sale de un registro persistido:
 * - prefiltro: solicitud_elementos.resultado_prefiltro;
 * - biometría: solicitud_elementos.estado_tramite que fijan PrintQueueController y BiometricUpdateService;
 * - impresión: CredencialImpresion::resultadoVigente() (considera rectificaciones), contado por elemento.
 * Entrega, retención y validación LOC aún no tienen fuente: se informan como no disponibles, nunca como cero.
 */
class SeguimientoTarjetaService
{
    public const ETAPA_SIN_PREFILTRO = 'sin_prefiltro';

    public const ETAPA_DESCARTADO = 'descartado';

    public const ETAPA_SIN_VALIDACION_BIOMETRICA = 'sin_validacion_biometrica';

    public const ETAPA_PENDIENTE_BIOMETRICO = 'pendiente_biometrico';

    public const ETAPA_PENDIENTE_IMPRESION = 'pendiente_impresion';

    public const ETAPA_IMPRESA = 'impresa';

    /** Relaciones mínimas para clasificar elementos sin una consulta por impresión. */
    private const RELACIONES = [
        'credencialEmitida:id,solicitud_elemento_id,folio,estado_emision,created_at',
        'credencialEmitida.impresiones:id,credencial_emitida_id,credencial_preparacion_id,usuario_id,resultado,modalidad_confirmacion,numero_intento,created_at',
        'credencialEmitida.impresiones.rectificaciones:id,credencial_impresion_id,resultado_rectificado',
        'credencialEmitida.impresiones.entregaDetalle:id,credencial_impresion_id,credencial_entrega_id',
    ];

    public const ENTREGA_ENTREGADA = 'entregada';

    public const ENTREGA_DISPONIBLE = 'disponible';

    /** Más de una impresión efectiva: anomalía excepcional; no se elige ninguna tarjeta automáticamente. */
    public const ENTREGA_ANOMALIA = 'anomalia_multiple';

    public const ENTREGA_NO_EMITIDA = 'no_emitida';

    public const ENTREGA_SIN_IMPRESION = 'sin_impresion';

    /**
     * @param  array{q?: string|null, estado?: string|null}  $filtros
     */
    public function listado(array $filtros, int $porPagina = 15): LengthAwarePaginator
    {
        $tarjetas = SolicitudCredencial::query()
            ->when($filtros['q'] ?? null, static fn ($q, string $texto) => $q->where('numero_tarjeta', 'like', '%'.addcslashes($texto, '%_\\').'%'))
            ->when($filtros['estado'] ?? null, static fn ($q, string $estado) => $q->where('estado_general', $estado))
            ->orderByDesc('consecutivo_tarjeta')
            ->orderByDesc('id')
            ->paginate($porPagina, ['id', 'numero_tarjeta', 'numero_oficio', 'area_solicitante', 'estado_general', 'fecha_recepcion', 'fecha_prefiltro', 'created_at'])
            ->withQueryString();

        $tarjetas->getCollection()->load(['elementos' => fn ($q) => $q->select(['id', 'solicitud_id', 'resultado_prefiltro', 'estado_tramite'])->with(self::RELACIONES)]);
        $tarjetas->getCollection()->each(function (SolicitudCredencial $tarjeta): void {
            $tarjeta->setAttribute('resumen', $this->resumen($tarjeta->elementos));
        });

        return $tarjetas;
    }

    /** Cifras globales que se pueden calcular con una consulta directa sobre columnas persistidas. */
    public function indicadoresGenerales(): array
    {
        return [
            'tarjetas' => SolicitudCredencial::query()->count(),
            'enviadas_impresion' => SolicitudCredencial::query()->where('estado_general', 'enviada_impresion')->count(),
            'pendientes_biometrico' => SolicitudElemento::query()->where('resultado_prefiltro', 'Apto')->where('estado_tramite', 'pendiente_biometrico')->count(),
        ];
    }

    /** Carga los elementos de una tarjeta con lo necesario para el panel. */
    public function cargarTarjeta(SolicitudCredencial $tarjeta): SolicitudCredencial
    {
        return $tarjeta->load([
            'usuarioPrefiltro:id,name',
            'elementos' => fn ($q) => $q->orderBy('numero_fila')->orderBy('id')->with([
                ...self::RELACIONES,
                'persona:id,nombre',
                'credencialEmitida.preparaciones:id,credencial_emitida_id,tipo_operacion,preparada_at',
                'credencialEmitida.impresiones.preparacion:id,tipo_operacion',
                'credencialEmitida.impresiones.usuario:id,name',
                'credencialEmitida.impresiones.entregaDetalle.entrega:id,entregada_at',
            ]),
        ]);
    }

    /**
     * Situación de entrega del elemento. Regla ordinaria: una persona, una credencial, una tarjeta física.
     * Disponible solo con exactamente una impresión efectiva (resultadoVigente), credencial emitida y sin entrega
     * previa. Conciliación LOC e incidencias no intervienen (sin regla institucional definida).
     *
     * @return array{estado: string, impresion: CredencialImpresion|null, motivo: string|null}
     */
    public function estadoEntrega(SolicitudElemento $elemento): array
    {
        $credencial = $elemento->credencialEmitida;
        $impresiones = $credencial?->impresiones ?? collect();
        $entregada = $impresiones->first(static fn (CredencialImpresion $i): bool => $i->entregaDetalle !== null);
        if ($entregada !== null) {
            return ['estado' => self::ENTREGA_ENTREGADA, 'impresion' => $entregada, 'motivo' => 'La credencial ya fue entregada.'];
        }

        $efectivas = $impresiones->filter(static fn (CredencialImpresion $i): bool => $i->resultadoVigente() === CredencialImpresion::RESULTADO_IMPRESA)->values();

        return match (true) {
            $efectivas->count() > 1 => ['estado' => self::ENTREGA_ANOMALIA, 'impresion' => null,
                'motivo' => "Tiene {$efectivas->count()} impresiones efectivas; en el procedimiento normal existe una sola. Requiere revisión institucional antes de entregarse."],
            $efectivas->count() === 1 && ! $credencial->estaEmitida() => ['estado' => self::ENTREGA_NO_EMITIDA, 'impresion' => null,
                'motivo' => 'Tiene una impresión efectiva, pero la credencial no figura como emitida.'],
            $efectivas->count() === 1 => ['estado' => self::ENTREGA_DISPONIBLE, 'impresion' => $efectivas->first(), 'motivo' => null],
            $credencial?->estaEmitida() ?? false => ['estado' => self::ENTREGA_SIN_IMPRESION, 'impresion' => null,
                'motivo' => 'Figura como emitida, pero su impresión fue rectificada como fallida.'],
            $impresiones->isNotEmpty() => ['estado' => self::ENTREGA_SIN_IMPRESION, 'impresion' => null,
                'motivo' => 'Solo tiene intentos de impresión sin resultado efectivo.'],
            default => ['estado' => self::ENTREGA_SIN_IMPRESION, 'impresion' => null, 'motivo' => 'Aún no tiene impresión registrada.'],
        };
    }

    /**
     * Cada intento de impresión del elemento (una tarjeta física por registro), con su resultado vigente.
     * Requiere la tarjeta cargada con cargarTarjeta().
     *
     * @return Collection<int, array{id: int, numero: int, fecha: Carbon|null, tipo: string|null, registrado: string, vigente: string, rectificada: bool, extemporanea: bool, usuario: string|null, preparacion_id: int|null}>
     */
    public function intentos(SolicitudElemento $elemento): Collection
    {
        return ($elemento->credencialEmitida?->impresiones ?? collect())->map(static function (CredencialImpresion $i): array {
            $vigente = $i->resultadoVigente();

            return [
                'id' => $i->id,
                'numero' => (int) $i->numero_intento,
                'fecha' => $i->created_at,
                // null: impresión sin preparación vinculada (registros anteriores a la Fase 1B.2).
                'tipo' => $i->preparacion?->tipo_operacion,
                'registrado' => $i->resultado,
                'vigente' => $vigente,
                'rectificada' => $vigente !== $i->resultado,
                'extemporanea' => $i->modalidad_confirmacion === CredencialImpresion::MODALIDAD_EXTEMPORANEA,
                'usuario' => $i->usuario?->name,
                'preparacion_id' => $i->credencial_preparacion_id,
            ];
        })->values();
    }

    /**
     * Bandeja "Credenciales recibidas": un renglón por elemento con impresión efectiva (no por intento), conservando
     * todos sus intentos. No hay recepción física: la inclusión depende solo de la impresión confirmada vigente.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function credencialesRecibidas(SolicitudCredencial $tarjeta): Collection
    {
        $impresos = $tarjeta->elementos->filter(fn (SolicitudElemento $e): bool => $this->etapaElemento($e) === self::ETAPA_IMPRESA);
        $duplicadas = $this->preparacionesConPosibleDuplicado($impresos);

        return $impresos->map(function (SolicitudElemento $e) use ($duplicadas): array {
            $intentos = $this->intentos($e);
            $efectivos = $intentos->where('vigente', CredencialImpresion::RESULTADO_IMPRESA);

            return [
                'elemento' => $e,
                'intentos' => $intentos,
                'efectivas' => $efectivos->count(),
                'primera_efectiva' => $efectivos->min('fecha'),
                'ultima_efectiva' => $efectivos->max('fecha'),
                'con_reimpresion' => $efectivos->contains('tipo', 'reimpresion_historica'),
                'posible_duplicado' => $intentos->pluck('preparacion_id')->filter()->intersect($duplicadas)->isNotEmpty(),
            ];
        })->values();
    }

    /**
     * Bandeja "Pendientes": cada elemento pendiente cae en una sola categoría (derivada de etapaElemento) y la de LOC
     * solo contiene impresos, por eso puede coincidir con "Credenciales recibidas". Descartados y elementos sin
     * prefiltro no tienen acción pendiente en estas categorías y no se listan.
     *
     * @return Collection<int, array{elemento: SolicitudElemento, categoria: string, motivo: string, fecha: Carbon|null, fecha_etiqueta: string|null}>
     */
    public function pendientes(SolicitudCredencial $tarjeta): Collection
    {
        $enviada = $tarjeta->estado_general === 'enviada_impresion';

        return $tarjeta->elementos->map(function (SolicitudElemento $e) use ($enviada): ?array {
            $credencial = $e->credencialEmitida;
            $intentos = $this->intentos($e);

            [$categoria, $motivo, $fecha, $etiqueta] = match ($this->etapaElemento($e)) {
                self::ETAPA_PENDIENTE_BIOMETRICO => ['biometricos', 'Validación 4/4 incompleta (foto, firma o huellas). El detalle de faltantes se consulta en Impresión y biometría.', null, null],
                self::ETAPA_SIN_VALIDACION_BIOMETRICA => ['biometricos', $enviada ? 'Apto sin validación biométrica ejecutada.' : 'Apto; la tarjeta aún no se envía a impresión, por lo que no se ha validado su biometría.', null, null],
                self::ETAPA_PENDIENTE_IMPRESION => ['impresion', match (true) {
                    $credencial?->estado_emision === EstadoEmision::EMITIDA->value => 'Figura como emitida, pero su impresión fue rectificada como fallida: no cuenta como impresa y requiere revisión institucional.',
                    $intentos->isNotEmpty() => $intentos->count().' intento(s) registrados sin impresión efectiva.',
                    $credencial?->preparaciones->isNotEmpty() ?? false => 'Preparada, sin confirmación de impresión.',
                    default => 'Biometría 4/4 completa y folio asignado; en espera de preparación e impresión.',
                }, $credencial?->created_at, 'Folio asignado'],
                self::ETAPA_IMPRESA => ['loc', 'Impresa; la conciliación LOC aún no está implementada. No indica discrepancia, retención ni revisión del archivo LOC.', $intentos->where('vigente', CredencialImpresion::RESULTADO_IMPRESA)->min('fecha'), 'Impresión confirmada'],
                default => [null, null, null, null],
            };

            return $categoria === null ? null : ['elemento' => $e, 'categoria' => $categoria, 'motivo' => $motivo, 'fecha' => $fecha, 'fecha_etiqueta' => $etiqueta];
        })->filter()->values();
    }

    /**
     * Preparaciones con incidencia POSIBLE_TARJETA_DUPLICADA sin resolver (ver CredencialPreparacionService::confirmar).
     *
     * @param  Collection<int, SolicitudElemento>  $elementos
     * @return Collection<int, int>
     */
    public function preparacionesConPosibleDuplicado(Collection $elementos): Collection
    {
        $ids = $elementos->flatMap(fn (SolicitudElemento $e) => $e->credencialEmitida?->impresiones->pluck('credencial_preparacion_id') ?? [])->filter()->unique();

        return $ids->isEmpty() ? collect() : BiometricoIncidencia::query()
            ->where('codigo', 'POSIBLE_TARJETA_DUPLICADA')
            ->whereNull('resuelta_at')
            ->whereIn('credencial_preparacion_id', $ids)
            ->pluck('credencial_preparacion_id');
    }

    public function etapaElemento(SolicitudElemento $elemento): string
    {
        if ($this->impresionesEfectivas($elemento) > 0) {
            return self::ETAPA_IMPRESA;
        }

        return match (true) {
            $elemento->resultado_prefiltro === null => self::ETAPA_SIN_PREFILTRO,
            $elemento->resultado_prefiltro !== 'Apto' => self::ETAPA_DESCARTADO,
            $elemento->estado_tramite === 'pendiente_biometrico' => self::ETAPA_PENDIENTE_BIOMETRICO,
            $elemento->estado_tramite === 'listo_impresion' => self::ETAPA_PENDIENTE_IMPRESION,
            default => self::ETAPA_SIN_VALIDACION_BIOMETRICA,
        };
    }

    /** Impresiones del elemento cuyo resultado vigente (tras rectificaciones) es "impresa". */
    public function impresionesEfectivas(SolicitudElemento $elemento): int
    {
        return $elemento->credencialEmitida?->impresiones
            ->filter(static fn (CredencialImpresion $i): bool => $i->resultadoVigente() === CredencialImpresion::RESULTADO_IMPRESA)
            ->count() ?? 0;
    }

    /**
     * Conteo por elemento (nunca por intento de impresión).
     *
     * @param  Collection<int, SolicitudElemento>  $elementos
     */
    public function resumen(Collection $elementos): array
    {
        $etapas = $elementos->map(fn (SolicitudElemento $e): string => $this->etapaElemento($e))->countBy();
        $entrega = $elementos->map(fn (SolicitudElemento $e): string => $this->estadoEntrega($e)['estado'])->countBy();
        $emitidasSinImpresion = $elementos->filter(fn (SolicitudElemento $e): bool => $e->credencialEmitida?->estado_emision === EstadoEmision::EMITIDA->value
            && $this->impresionesEfectivas($e) === 0)->count();

        return [
            'total' => $elementos->count(),
            'aptos' => $elementos->where('resultado_prefiltro', 'Apto')->count(),
            'descartados' => $etapas[self::ETAPA_DESCARTADO] ?? 0,
            'sin_prefiltro' => $etapas[self::ETAPA_SIN_PREFILTRO] ?? 0,
            'sin_validacion_biometrica' => $etapas[self::ETAPA_SIN_VALIDACION_BIOMETRICA] ?? 0,
            'pendientes_biometrico' => $etapas[self::ETAPA_PENDIENTE_BIOMETRICO] ?? 0,
            'pendientes_impresion' => $etapas[self::ETAPA_PENDIENTE_IMPRESION] ?? 0,
            'impresas' => $etapas[self::ETAPA_IMPRESA] ?? 0,
            // Credencial "emitida" cuya impresión vigente fue rectificada como fallida: requiere revisión institucional.
            'emitidas_sin_impresion_vigente' => $emitidasSinImpresion,
            'con_folio' => $elementos->filter(static fn (SolicitudElemento $e): bool => filled($e->credencialEmitida?->folio))->count(),
            // Fuente: credencial_entrega_detalles (por elemento, no por tarjeta física).
            'pendientes_entrega' => $entrega[self::ENTREGA_DISPONIBLE] ?? 0,
            'entregadas' => $entrega[self::ENTREGA_ENTREGADA] ?? 0,
            'anomalias_entrega' => $entrega[self::ENTREGA_ANOMALIA] ?? 0,
            // Sin fuente persistida todavía: null = no disponible, nunca cero.
            'retenidas' => null,
        ];
    }

    /** Estado general derivado de los conteos; no es un estado de negocio persistido. */
    public function estadoDerivado(SolicitudCredencial $tarjeta, array $resumen): array
    {
        return match (true) {
            $resumen['total'] === 0 => ['Sin elementos', 'neutral'],
            $tarjeta->fecha_prefiltro === null => ['En prefiltro', 'warning'],
            $resumen['aptos'] === 0 => ['Sin elementos aptos', 'neutral'],
            $tarjeta->estado_general !== 'enviada_impresion' => ['Evaluada, sin enviar a impresión', 'info'],
            $resumen['impresas'] >= $resumen['aptos'] => ['Impresión concluida', 'success'],
            $resumen['impresas'] > 0 => ['Impresión en curso', 'process'],
            default => ['En impresión', 'process'],
        };
    }

    /**
     * Cronología con fechas persistidas. Estados: completada, en_curso, pendiente, sin_registro (ocurrió pero no se
     * conserva la fecha) y no_implementada.
     *
     * @return list<array{clave: string, etapa: string, estado: string, desde: Carbon|null, hasta: Carbon|null, detalle: string, fuente: string}>
     */
    public function cronologia(SolicitudCredencial $tarjeta, array $resumen): array
    {
        $credenciales = $tarjeta->elementos->pluck('credencialEmitida')->filter();
        $preparaciones = $credenciales->flatMap->preparaciones->where('tipo_operacion', 'emision');
        $preparadas = $preparaciones->pluck('credencial_emitida_id')->unique()->count();
        $impresas = $credenciales->flatMap->impresiones->filter(static fn (CredencialImpresion $i): bool => $i->resultadoVigente() === CredencialImpresion::RESULTADO_IMPRESA);
        $noVigentes = $credenciales->flatMap->impresiones->count() - $impresas->count();
        $autorizada = $tarjeta->estado_general === 'enviada_impresion';
        $prefiltrada = $tarjeta->fecha_prefiltro !== null;

        $avance = static fn (int $hechos, int $esperados): string => match (true) {
            $esperados > 0 && $hechos >= $esperados => 'completada',
            $hechos > 0 => 'en_curso',
            default => 'pendiente',
        };
        $rango = static fn (Collection $fechas): array => [$fechas->min(), $fechas->count() > 1 ? $fechas->max() : null];
        [$folioDesde, $folioHasta] = $rango($credenciales->pluck('created_at')->filter());
        [$prepDesde, $prepHasta] = $rango($preparaciones->pluck('preparada_at')->filter());
        [$impDesde, $impHasta] = $rango($impresas->pluck('created_at')->filter());
        [$entDesde, $entHasta] = $rango($credenciales->flatMap->impresiones->map(static fn (CredencialImpresion $i) => $i->entregaDetalle?->entrega?->entregada_at)->filter()->unique());

        return [
            [
                'clave' => 'registro', 'etapa' => 'Registro en Credencialización', 'estado' => 'completada',
                'desde' => $tarjeta->created_at, 'hasta' => null,
                'detalle' => $tarjeta->fecha_recepcion ? 'Oficio recibido el '.$tarjeta->fecha_recepcion->format('d/m/Y').' (fecha declarada en el registro).' : 'Sin fecha de recepción declarada.',
                'fuente' => 'solicitudes.created_at · fecha_recepcion',
            ],
            [
                'clave' => 'tarjeta', 'etapa' => 'Asignación del número de tarjeta', 'estado' => filled($tarjeta->numero_tarjeta) ? 'completada' : 'pendiente',
                'desde' => filled($tarjeta->numero_tarjeta) ? $tarjeta->created_at : null, 'hasta' => null,
                'detalle' => filled($tarjeta->numero_tarjeta) ? 'Asignado en la misma transacción que el registro.' : 'La solicitud no tiene número de tarjeta.',
                'fuente' => 'solicitudes.numero_tarjeta (SolicitudTarjetaSequenceService)',
            ],
            [
                'clave' => 'prefiltro', 'etapa' => 'Prefiltro de elementos', 'estado' => $prefiltrada ? 'completada' : 'pendiente',
                'desde' => $tarjeta->fecha_prefiltro, 'hasta' => null,
                'detalle' => $prefiltrada
                    ? "{$resumen['aptos']} aptos y {$resumen['descartados']} descartados".($tarjeta->usuarioPrefiltro ? ' · ejecutó '.$tarjeta->usuarioPrefiltro->name : '').'.'
                    : 'Aún no se ejecuta el prefiltro.',
                'fuente' => 'solicitudes.fecha_prefiltro · usuario_prefiltro_id',
            ],
            [
                'clave' => 'autorizacion', 'etapa' => 'Autorización para impresión', 'estado' => $autorizada ? 'sin_registro' : 'pendiente',
                'desde' => null, 'hasta' => null,
                'detalle' => $autorizada
                    ? 'La tarjeta está enviada a impresión; el sistema no conserva la fecha ni el usuario de la autorización.'
                    : 'La tarjeta no ha sido enviada a impresión.',
                'fuente' => 'solicitudes.estado_general (sin columna de fecha)',
            ],
            [
                'clave' => 'cola', 'etapa' => 'Ingreso a impresión (validación 4/4 y folio)', 'estado' => $avance($resumen['con_folio'], $resumen['aptos']),
                'desde' => $folioDesde, 'hasta' => $folioHasta,
                'detalle' => "{$resumen['con_folio']} de {$resumen['aptos']} elementos aptos con folio asignado; {$resumen['pendientes_biometrico']} pendientes de biométricos.",
                'fuente' => 'credenciales_emitidas.created_at (folio asignado)',
            ],
            [
                'clave' => 'preparacion', 'etapa' => 'Preparación de la credencial', 'estado' => $avance($preparadas, $resumen['con_folio']),
                'desde' => $prepDesde, 'hasta' => $prepHasta,
                'detalle' => "{$preparadas} de {$resumen['con_folio']} credenciales con al menos una preparación de emisión.",
                'fuente' => 'credencial_preparaciones.preparada_at',
            ],
            [
                'clave' => 'impresion', 'etapa' => 'Confirmación de impresión', 'estado' => $avance($resumen['impresas'], $resumen['aptos']),
                'desde' => $impDesde, 'hasta' => $impHasta,
                'detalle' => "{$resumen['impresas']} de {$resumen['aptos']} elementos aptos con impresión vigente"
                    .($noVigentes > 0 ? "; {$noVigentes} intentos fallidos o rectificados" : '').'.',
                'fuente' => 'credencial_impresiones (resultado vigente con rectificaciones)',
            ],
            ['clave' => 'seguimiento', 'etapa' => 'Incorporación al seguimiento de entrega', 'estado' => 'no_implementada', 'desde' => null, 'hasta' => null, 'detalle' => 'Se habilitará con la recepción y las entregas.', 'fuente' => '—'],
            ['clave' => 'loc', 'etapa' => 'Validación LOC', 'estado' => 'no_implementada', 'desde' => null, 'hasta' => null, 'detalle' => 'Pendiente de definición funcional (archivo LOC actualizado).', 'fuente' => '—'],
            [
                'clave' => 'entregas', 'etapa' => 'Entregas registradas', 'estado' => $avance($resumen['entregadas'], $resumen['impresas']),
                'desde' => $entDesde, 'hasta' => $entHasta,
                'detalle' => "{$resumen['entregadas']} de {$resumen['impresas']} elementos impresos con credencial entregada"
                    .($resumen['anomalias_entrega'] > 0 ? "; {$resumen['anomalias_entrega']} con varias impresiones efectivas en revisión" : '').'.',
                'fuente' => 'credencial_entregas.entregada_at',
            ],
        ];
    }
}
