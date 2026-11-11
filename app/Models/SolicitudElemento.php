<?php

namespace App\Models;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoClasificacionEstado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SolicitudElemento extends Model
{
    protected $table = 'solicitud_elementos';

    protected $fillable = [
        'solicitud_id',
        'numero_fila',
        'persona_id',
        'biometrico_expediente_id',
        'cuip',
        'matricula_corta',
        'matricula_larga',
        'arma_corta_id',
        'arma_larga_id',
        'tipo_elemento',
        'datos_solicitados',
        'resultado_prefiltro',
        'motivos_descarte',
        'codigos_descarte',
        'datos_originales_fila',
        'estado_tramite',
        'motivo_rechazo',
        'motivo_retencion',
        'observaciones',
        'ambito_biometrico',
        'estado_clasificacion_ambito',
        'clave_organizacional_origen',
        'catalogo_version_id',
        'catalogo_version_numero',
        'criterio_resolucion_ambito',
        'tiene_discrepancia_oficio',
        'fecha_resolucion_ambito',
    ];

    protected $casts = [
        'numero_fila' => 'integer',
        'biometrico_expediente_id' => 'integer',
        'catalogo_version_id' => 'integer',
        'catalogo_version_numero' => 'integer',
        'tiene_discrepancia_oficio' => 'boolean',
        'fecha_resolucion_ambito' => 'datetime',
        'ambito_biometrico' => BiometricoAmbito::class,
        'estado_clasificacion_ambito' => BiometricoClasificacionEstado::class,
        'motivos_descarte' => 'array',
        'codigos_descarte' => 'array',
        'datos_originales_fila' => 'array',
        'datos_solicitados' => 'array',
    ];

    /**
     * Retorna las etiquetas institucionales correspondientes a los códigos de descarte asignados.
     *
     * @return array<int, string>
     */
    public function motivosLabels(): array
    {
        $codes = $this->codigos_descarte ?? [];

        return array_map(fn (string $code): string => \App\Support\MotivoDescarte::label($code), $codes);
    }

    /**
     * Resuelve el estado institucional del agrupamiento para este elemento.
     *
     * @param  \Illuminate\Support\Collection|null  $catalogo
     * @return array{
     *     estado: string,
     *     clave: string|null,
     *     denominacion: string|null,
     *     solicitado: string|null,
     *     persona: string|null,
     *     advertencia: string|null,
     *     fuente: string
     * }
     */
    public function agrupamientoInfo(?\Illuminate\Support\Collection $catalogo = null): array
    {
        return \App\Support\AgrupamientoInstitucional::resolver($this, $catalogo);
    }

    /**
     * Aplica los filtros combinables de la Fase 2E al conjunto de elementos.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array<string, mixed>  $filters
     * @param  \Illuminate\Support\Collection|null  $catalogo
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFilterBy($query, array $filters, ?\Illuminate\Support\Collection $catalogo = null)
    {
        // 1. Filtro A: Resultado del prefiltro
        if (! empty($filters['prefiltro'])) {
            $prefiltro = strtolower(trim((string) $filters['prefiltro']));
            if ($prefiltro === 'aptos' || $prefiltro === 'apto') {
                $query->where('solicitud_elementos.resultado_prefiltro', 'Apto');
            } elseif ($prefiltro === 'descartados' || $prefiltro === 'descartado') {
                $query->where('solicitud_elementos.resultado_prefiltro', 'Descartado');
            } elseif ($prefiltro === 'sin_evaluar' || $prefiltro === 'sin evaluar') {
                $query->whereNull('solicitud_elementos.resultado_prefiltro');
            }
        }

        // 2. Filtro B: Motivo de descarte (14 sustantivos + 1 técnico MOT_SIN_CLASIFICAR + historico)
        if (! empty($filters['motivo'])) {
            $motivo = trim((string) $filters['motivo']);
            if ($motivo === 'historico') {
                $query->where('solicitud_elementos.resultado_prefiltro', 'Descartado')
                    ->where(function ($sub) {
                        $sub->whereNull('solicitud_elementos.codigos_descarte')
                            ->orWhereJsonContains('solicitud_elementos.codigos_descarte', \App\Support\MotivoDescarte::MOT_SIN_CLASIFICAR);
                    });
            } elseif ($motivo !== 'todos' && \App\Support\MotivoDescarte::isValid($motivo)) {
                $query->whereJsonContains('solicitud_elementos.codigos_descarte', $motivo);
            }
        }

        // 3. Filtro C: Agrupamiento Institucional (Equivalencia exacta con AgrupamientoInstitucional::resolver)
        $agrupamientoEstado = ! empty($filters['agrupamiento']) ? strtolower(trim((string) $filters['agrupamiento'])) : null;
        $claveFiltro = ! empty($filters['clave']) ? trim((string) $filters['clave']) : null;

        if (($agrupamientoEstado && $agrupamientoEstado !== 'todos') || $claveFiltro) {
            $catalogoActivo = $catalogo ?? \App\Support\AgrupamientoInstitucional::obtenerCatalogo();

            $candidatos = (clone $query)
                ->with(['persona:id,agrupamiento'])
                ->get(['solicitud_elementos.id', 'solicitud_elementos.solicitud_id', 'solicitud_elementos.persona_id', 'solicitud_elementos.datos_solicitados']);

            $matchingIds = [];
            foreach ($candidatos as $candidato) {
                $info = \App\Support\AgrupamientoInstitucional::resolver($candidato, $catalogoActivo);

                $matchEstado = true;
                if ($agrupamientoEstado && $agrupamientoEstado !== 'todos') {
                    $normEstado = str_replace([' ', 'ó'], ['_', 'o'], $agrupamientoEstado);
                    $matchEstado = ($info['estado'] === $normEstado);
                }

                $matchClave = true;
                if ($claveFiltro) {
                    $matchClave = ($info['clave'] !== null && strtoupper((string) $info['clave']) === strtoupper($claveFiltro));
                }

                if ($matchEstado && $matchClave) {
                    $matchingIds[] = $candidato->id;
                }
            }

            $query->whereIn('solicitud_elementos.id', $matchingIds);
        }

        // 4. Filtro D: Búsqueda general 'q' (CUIP, persona, matrículas, adscripción, armas)
        if (! empty($filters['q'])) {
            $term = trim((string) $filters['q']);
            if ($term !== '') {
                $escaped = addcslashes($term, '%_\\');
                $like = "%{$escaped}%";
                $driver = $query->getConnection()->getDriverName();
                $isSqlite = ($driver === 'sqlite');

                $matchLike = function ($subQuery, string $column, bool $isOr = false) use ($like, $isSqlite): void {
                    if ($isSqlite) {
                        if ($isOr) {
                            $subQuery->orWhereRaw("{$column} LIKE ? ESCAPE '\\'", [$like]);
                        } else {
                            $subQuery->whereRaw("{$column} LIKE ? ESCAPE '\\'", [$like]);
                        }
                    } else {
                        if ($isOr) {
                            $subQuery->orWhere($column, 'like', $like);
                        } else {
                            $subQuery->where($column, 'like', $like);
                        }
                    }
                };

                $query->where(function ($sub) use ($matchLike, $isSqlite, $like) {
                    $matchLike($sub, 'solicitud_elementos.cuip', false);
                    $matchLike($sub, 'solicitud_elementos.matricula_corta', true);
                    $matchLike($sub, 'solicitud_elementos.matricula_larga', true);

                    if ($isSqlite) {
                        $matchLike($sub, "json_extract(solicitud_elementos.datos_solicitados, '$.nombre')", true);
                        $matchLike($sub, "json_extract(solicitud_elementos.datos_solicitados, '$.adscripcion')", true);
                    } else {
                        $sub->orWhere('solicitud_elementos.datos_solicitados->nombre', 'like', $like);
                        $sub->orWhere('solicitud_elementos.datos_solicitados->adscripcion', 'like', $like);
                    }

                    $sub->orWhereHas('persona', function ($p) use ($matchLike) {
                        $p->where(function ($sq) use ($matchLike) {
                            $matchLike($sq, 'nombre', false);
                            $matchLike($sq, 'agrupamiento', true);
                        });
                    })
                    ->orWhereHas('armaCorta', function ($a) use ($matchLike) {
                        $a->where(function ($sq) use ($matchLike) {
                            $matchLike($sq, 'matricula', false);
                            $matchLike($sq, 'marca', true);
                            $matchLike($sq, 'modelo', true);
                        });
                    })
                    ->orWhereHas('armaLarga', function ($a) use ($matchLike) {
                        $a->where(function ($sq) use ($matchLike) {
                            $matchLike($sq, 'matricula', false);
                            $matchLike($sq, 'marca', true);
                            $matchLike($sq, 'modelo', true);
                        });
                    });
                });
            }
        }

        // 5. Filtro E: Armamento (arma corta y arma larga, independiente solicitada vs oficial)
        if (! empty($filters['arma_corta'])) {
            $armaCorta = strtolower(trim((string) $filters['arma_corta']));
            if ($armaCorta === 'con_arma' || $armaCorta === 'solicitada') {
                $query->whereNotNull('solicitud_elementos.matricula_corta')
                      ->where('solicitud_elementos.matricula_corta', '!=', '');
            } elseif ($armaCorta === 'sin_arma') {
                $query->where(function ($sub) {
                    $sub->whereNull('solicitud_elementos.matricula_corta')
                        ->orWhere('solicitud_elementos.matricula_corta', '');
                });
            } elseif ($armaCorta === 'vinculada') {
                $query->whereNotNull('solicitud_elementos.arma_corta_id');
            } elseif ($armaCorta === 'no_vinculada') {
                $query->whereNotNull('solicitud_elementos.matricula_corta')
                      ->where('solicitud_elementos.matricula_corta', '!=', '')
                      ->whereNull('solicitud_elementos.arma_corta_id');
            }
        }

        if (! empty($filters['arma_larga'])) {
            $armaLarga = strtolower(trim((string) $filters['arma_larga']));
            if ($armaLarga === 'con_arma' || $armaLarga === 'solicitada') {
                $query->whereNotNull('solicitud_elementos.matricula_larga')
                      ->where('solicitud_elementos.matricula_larga', '!=', '');
            } elseif ($armaLarga === 'sin_arma') {
                $query->where(function ($sub) {
                    $sub->whereNull('solicitud_elementos.matricula_larga')
                        ->orWhere('solicitud_elementos.matricula_larga', '');
                });
            } elseif ($armaLarga === 'vinculada') {
                $query->whereNotNull('solicitud_elementos.arma_larga_id');
            } elseif ($armaLarga === 'no_vinculada') {
                $query->whereNotNull('solicitud_elementos.matricula_larga')
                      ->where('solicitud_elementos.matricula_larga', '!=', '')
                      ->whereNull('solicitud_elementos.arma_larga_id');
            }
        }

        return $query;
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudCredencial::class, 'solicitud_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(LocPersona::class, 'persona_id');
    }

    public function armaCorta(): BelongsTo
    {
        return $this->belongsTo(LocArma::class, 'arma_corta_id');
    }

    public function armaLarga(): BelongsTo
    {
        return $this->belongsTo(LocArma::class, 'arma_larga_id');
    }

    public function credencialEmitida(): HasOne
    {
        return $this->hasOne(CredencialEmitida::class, 'solicitud_elemento_id');
    }

    /**
     * Expediente biométrico institucional asociado convencionalmente mediante clave foránea física.
     */
    public function expedienteBiometrico(): BelongsTo
    {
        return $this->belongsTo(BiometricoExpediente::class, 'biometrico_expediente_id');
    }

    /**
     * Versión histórica del catálogo organizacional utilizada para sellar la resolución del ámbito.
     */
    public function catalogoVersion(): BelongsTo
    {
        return $this->belongsTo(LocCatalogoVersion::class, 'catalogo_version_id');
    }

    /**
     * Bitácora de auditorías asociadas a las consultas biométricas de este elemento.
     */
    public function auditoriasBiometricas(): HasMany
    {
        return $this->hasMany(BiometricoAuditoria::class, 'solicitud_elemento_id');
    }

    /**
     * Determina si el ámbito biométrico ya fue resuelto válidamente (ESTATALES o PREVENCION).
     */
    public function isAmbitoResuelto(): bool
    {
        return $this->ambito_biometrico !== null && $this->ambito_biometrico->isResuelto();
    }

    /**
     * Valida si el elemento cumple los criterios institucionales para asociar formalmente un expediente.
     */
    public function canAssociateExpediente(): bool
    {
        if (! $this->isAmbitoResuelto()) {
            return false;
        }

        if ($this->estado_clasificacion_ambito === null || ! $this->estado_clasificacion_ambito->permiteAsociacionDefinitiva()) {
            return false;
        }

        return true;
    }

    /**
     * Asocia formalmente un expediente biométrico verificando estrictamente todas las invariantes de identidad.
     *
     * @throws \InvalidArgumentException Si el CUIP, ámbito o estado de clasificación no cumplen las reglas institucionales.
     * @throws \RuntimeException Si el elemento ya cuenta con credencial emitida (inmutable).
     */
    public function asociarExpediente(BiometricoExpediente $expediente): void
    {
        if ($this->credencialEmitida()->exists()) {
            throw new \RuntimeException("El elemento ya cuenta con credencial emitida; la asociación biométrica es legalmente inmutable.");
        }

        if ($this->cuip === null || strtoupper(trim((string) $this->cuip)) !== strtoupper(trim((string) $expediente->cuip))) {
            throw new \InvalidArgumentException(
                "Discrepancia de identidad: El CUIP del elemento '{$this->cuip}' no coincide con el CUIP del expediente '{$expediente->cuip}'."
            );
        }

        if (! $this->isAmbitoResuelto()) {
            throw new \InvalidArgumentException(
                "No se puede asociar un expediente a un elemento con ámbito biométrico NO_RESUELTO."
            );
        }

        if ($this->ambito_biometrico?->value !== $expediente->ambito?->value) {
            throw new \InvalidArgumentException(
                "Discrepancia de ámbito: El elemento tiene ámbito '{$this->ambito_biometrico?->value}' pero el expediente pertenece a '{$expediente->ambito?->value}'."
            );
        }

        if ($this->estado_clasificacion_ambito === null || ! $this->estado_clasificacion_ambito->permiteAsociacionDefinitiva()) {
            throw new \InvalidArgumentException(
                "No se permite asociar definitivamente un expediente a un elemento con clasificación provisional."
            );
        }

        $this->biometrico_expediente_id = $expediente->id;
    }

    /**
     * Desasocia el expediente biométrico garantizando que no se alteren registros con credencial emitida.
     *
     * @throws \RuntimeException Si el elemento ya tiene credencial emitida.
     */
    public function desasociarExpediente(): void
    {
        if ($this->credencialEmitida()->exists()) {
            throw new \RuntimeException("El elemento ya cuenta con credencial emitida; la asociación biométrica es legalmente inmutable.");
        }

        $this->biometrico_expediente_id = null;
    }

    /**
     * Candado de ciclo de vida en el modelo para evitar asociaciones directas que evadan las reglas de integridad.
     */
    protected static function booted(): void
    {
        static::saving(function (SolicitudElemento $elemento): void {
            if ($elemento->isDirty('biometrico_expediente_id') && $elemento->biometrico_expediente_id !== null) {
                // Si la relación ya está cargada o buscamos en BD
                $expediente = $elemento->relationLoaded('expedienteBiometrico')
                    ? $elemento->expedienteBiometrico
                    : BiometricoExpediente::find($elemento->biometrico_expediente_id);

                if (! $expediente) {
                    throw new \InvalidArgumentException("No se puede asociar: el expediente biométrico ID {$elemento->biometrico_expediente_id} no existe.");
                }

                if ($elemento->cuip === null || strtoupper(trim((string) $elemento->cuip)) !== strtoupper(trim((string) $expediente->cuip))) {
                    throw new \InvalidArgumentException(
                        "Discrepancia de identidad: El CUIP del elemento '{$elemento->cuip}' no coincide con el CUIP del expediente '{$expediente->cuip}'."
                    );
                }

                if (! $elemento->isAmbitoResuelto()) {
                    throw new \InvalidArgumentException(
                        "No se puede asociar un expediente a un elemento con ámbito biométrico NO_RESUELTO."
                    );
                }

                if ($elemento->ambito_biometrico?->value !== $expediente->ambito?->value) {
                    throw new \InvalidArgumentException(
                        "Discrepancia de ámbito: El elemento tiene ámbito '{$elemento->ambito_biometrico?->value}' pero el expediente es '{$expediente->ambito?->value}'."
                    );
                }

                if ($elemento->estado_clasificacion_ambito === null || ! $elemento->estado_clasificacion_ambito->permiteAsociacionDefinitiva()) {
                    throw new \InvalidArgumentException(
                        "No se permite asociar definitivamente un expediente a un elemento con clasificación provisional."
                    );
                }
            }
        });
    }
}
