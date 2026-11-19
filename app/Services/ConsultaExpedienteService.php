<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LocPersona;
use App\Models\LocVigenciaCredencial;
use App\Models\SolicitudElemento;
use Illuminate\Support\Collection;

/**
 * Consultas C2: expediente de elemento, de solo lectura y por sección (cada método consulta solo lo suyo).
 * La vigencia vigente se elige con LocCredentialsPolicyService; "asignación" de armas no existe en las fuentes.
 */
class ConsultaExpedienteService
{
    public const SECCIONES = ['resumen' => 'Resumen', 'vigencias' => 'Vigencias', 'armamento-vinculado' => 'Armamento vinculado', 'tramites' => 'Trámites de credencial'];

    private const COLUMNAS_VIGENCIA = ['id', 'persona_id', 'cuip', 'numero_credencial', 'estatus_credencial', 'periodo_credencial', 'fecha_solicitud',
        'fecha_elaboracion', 'fecha_vigencia', 'fecha_baja', 'numero_oficio_baja', 'motivo_situacion_especial', 'es_referencia_loc',
        'arma_id', 'arma_corta_id', 'arma_larga_id', 'matricula_corta', 'matricula_larga'];

    private const COLUMNAS_ARMA = 'id,matricula,id_armamento,tipo_arma,clase_arma,marca,calibre,modelo,estatus,situacion';

    public function __construct(
        private readonly LocCredentialsPolicyService $politica,
        private readonly SeguimientoTarjetaService $seguimiento,
    ) {}

    /**
     * Vigencias del elemento: verificadas (persona_id propio y es_referencia_loc) y, aparte, las que comparten CUIP o
     * persona_id sin conciliación. Una sola consulta: ninguna fila aparece dos veces.
     *
     * @return array{verificadas: Collection, ultima: ?LocVigenciaCredencial, determinable: bool, anteriores: Collection, sin_vinculo: Collection}
     */
    public function vigencias(LocPersona $persona, array $conArmas = []): array
    {
        $filas = LocVigenciaCredencial::query()->select(self::COLUMNAS_VIGENCIA)->with($conArmas)
            ->where(fn ($q) => $q->where('persona_id', $persona->id)->orWhere('cuip', $persona->cuip))
            ->orderBy('id')->get();
        [$verificadas, $sinVinculo] = $filas->partition(fn (LocVigenciaCredencial $v): bool => (int) $v->persona_id === (int) $persona->id && $v->es_referencia_loc);

        $ultima = $this->politica->determineChronologicallyLatestRecord($verificadas->values());
        $fecha = fn (LocVigenciaCredencial $v): ?int => $this->politica->extractComparableDate($v)?->getTimestamp();

        return [
            'verificadas' => $verificadas->values(),
            'ultima' => $ultima,
            'determinable' => $ultima !== null && $this->esDeterminable($verificadas, $ultima),
            'anteriores' => $verificadas->reject(fn ($v) => $ultima !== null && $v->is($ultima))
                ->sortByDesc(fn ($v) => $fecha($v) ?? PHP_INT_MIN)->values(),
            'sin_vinculo' => $sinVinculo->sortByDesc(fn ($v) => $fecha($v) ?? PHP_INT_MIN)->values(),
        ];
    }

    /**
     * La política devuelve siempre un registro, aun sin fechas o con empate (orden estable = orden de inserción).
     * Con las mismas primitivas (fecha comparable y desempate por BAJA) se detecta cuándo esa elección no es cronológica.
     */
    public function esDeterminable(Collection $verificadas, LocVigenciaCredencial $ultima): bool
    {
        if ($verificadas->count() <= 1) {
            return true;
        }
        $fechaUltima = $this->politica->extractComparableDate($ultima)?->getTimestamp();
        if ($fechaUltima === null) {
            return false;
        }
        $esBaja = static fn (LocVigenciaCredencial $v): bool => strtoupper((string) $v->estatus_credencial) === LocCredentialsPolicyService::STATUS_BAJA;

        return ! $verificadas->contains(fn (LocVigenciaCredencial $v): bool => ! $v->is($ultima)
            && $this->politica->extractComparableDate($v)?->getTimestamp() === $fechaUltima
            && $esBaja($v) === $esBaja($ultima));
    }

    /**
     * Trámites del elemento: por persona_id, o por CUIP exacto solo si el trámite no apunta a otra persona.
     * Nunca por nombre. Una sola consulta: un trámite no se repite aunque coincida por ambos criterios.
     */
    public function tramites(LocPersona $persona, array $con = []): Collection
    {
        return SolicitudElemento::query()
            ->select(['id', 'solicitud_id', 'persona_id', 'cuip', 'numero_fila', 'resultado_prefiltro', 'estado_tramite', 'arma_corta_id', 'arma_larga_id', 'matricula_corta', 'matricula_larga'])
            ->where(fn ($q) => $q->where('persona_id', $persona->id)->orWhere(fn ($c) => $c->whereNull('persona_id')->where('cuip', $persona->cuip)))
            ->with(['solicitud:id,numero_tarjeta,numero_oficio,area_solicitante,fecha_recepcion,estado_general,created_at', ...$con])
            ->orderByDesc('id')->get();
    }

    /** Trámites con lo necesario para resumir emisión, impresión y entrega (mismas relaciones que Entrega y Seguimiento). */
    public function tramitesDetallados(LocPersona $persona): Collection
    {
        return $this->tramites($persona, [
            'credencialEmitida:id,solicitud_elemento_id,folio,estado_emision,fecha_emision',
            'credencialEmitida.impresiones:id,credencial_emitida_id,resultado,numero_intento,created_at',
            'credencialEmitida.impresiones.rectificaciones:id,credencial_impresion_id,resultado_rectificado',
            'credencialEmitida.impresiones.entregaDetalle:id,credencial_impresion_id,credencial_entrega_id',
            'credencialEmitida.impresiones.entregaDetalle.entrega:id,entregada_at',
        ])->map(fn (SolicitudElemento $e): array => [
            'elemento' => $e,
            'vinculo' => (int) $e->persona_id === (int) $persona->id ? 'padron' : 'cuip',
            'etapa' => $this->seguimiento->etapaElemento($e),
            'entrega' => $this->seguimiento->estadoEntrega($e),
        ]);
    }

    /**
     * Armas referidas en vigencias verificadas y en trámites del elemento: una ficha por arma con todas sus fuentes.
     * Matrículas sin registro de armamento se listan aparte, sin inventar la ficha.
     *
     * @return array{armas: Collection, sin_registro: Collection}
     */
    public function armamento(LocPersona $persona): array
    {
        $relaciones = ['arma:'.self::COLUMNAS_ARMA, 'armaCorta:'.self::COLUMNAS_ARMA, 'armaLarga:'.self::COLUMNAS_ARMA];
        $referencias = collect();
        foreach ($this->vigencias($persona, $relaciones)['verificadas'] as $v) {
            $fuente = 'Vigencia LOC '.($v->numero_credencial ?: 'sin número');
            $referencias->push(...$this->referencias($v, $fuente, ['arma' => null, 'armaCorta' => 'matricula_corta', 'armaLarga' => 'matricula_larga']));
        }
        foreach ($this->tramites($persona, ['armaCorta:'.self::COLUMNAS_ARMA, 'armaLarga:'.self::COLUMNAS_ARMA]) as $e) {
            $fuente = 'Trámite de credencial '.($e->solicitud?->numero_tarjeta ?: 'sin número de tarjeta');
            $referencias->push(...$this->referencias($e, $fuente, ['armaCorta' => 'matricula_corta', 'armaLarga' => 'matricula_larga']));
        }
        [$conArma, $sinArma] = $referencias->partition(fn (array $r): bool => $r['arma'] !== null);

        return [
            'armas' => $conArma->groupBy(fn (array $r) => $r['arma']->id)
                ->map(fn (Collection $g): array => ['arma' => $g->first()['arma'], 'fuentes' => $g->pluck('fuente')->unique()->values()])->values(),
            'sin_registro' => $sinArma->groupBy('matricula')
                ->map(fn (Collection $g, string $m): array => ['matricula' => $m, 'fuentes' => $g->pluck('fuente')->unique()->values()])->values(),
        ];
    }

    /** @return list<array{arma: mixed, matricula: ?string, fuente: string}> */
    private function referencias(object $registro, string $fuente, array $relaciones): array
    {
        $salida = [];
        foreach ($relaciones as $relacion => $columnaMatricula) {
            $arma = $registro->{$relacion};
            $matricula = $columnaMatricula ? trim((string) $registro->{$columnaMatricula}) : '';
            if ($arma !== null || $matricula !== '') {
                $salida[] = ['arma' => $arma, 'matricula' => $arma?->matricula ?? $matricula, 'fuente' => $fuente];
            }
        }

        return $salida;
    }

    /** Indicadores del resumen: solo conteos con fuente verificable. */
    public function resumen(LocPersona $persona): array
    {
        $vigencias = $this->vigencias($persona);

        return [
            ...$vigencias,
            'tramites' => $this->tramites($persona)->count(),
            'biometrico' => $persona->expedientesBiometricos()->exists(),
        ];
    }
}
