<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LocArma;
use App\Models\LocVigenciaCredencial;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LocCredentialsWeaponsReconciler
{
    /**
     * Matrículas de arma corta identificadas históricamente sin correspondencia en loc_armas.
     */
    public const KNOWN_UNMATCHED_CORTA_COUNT = 10;

    /**
     * Ejecuta el proceso de conciliación por lotes acotados.
     *
     * @param int $chunkSize Tamaño de bloque por transacción.
     * @param bool $dryRun Si es true, simula sin escribir en base de datos.
     * @param (callable(int $processed, int $total): void)|null $progressCallback Callback opcional de progreso.
     * @return array Resumen estructurado de conteos y resultados.
     */
    public function run(
        int $chunkSize = 500,
        bool $dryRun = true,
        ?callable $progressCallback = null
    ): array {
        if ($chunkSize < 1 || $chunkSize > 5000) {
            throw new InvalidArgumentException("El tamaño de bloque debe estar entre 1 y 5000 (recibido: {$chunkSize}).");
        }

        $metrics = [
            'modo' => $dryRun ? 'simulacion' : 'escritura',
            'vigencias_totales_evaluadas' => 0,
            'vigencias_con_actualizacion' => 0,
            'arma_corta' => [
                'enlace_nuevo' => 0,
                'ya_enlazada_correcta' => 0,
                'conflicto_no_modificado' => 0,
                'sin_coincidencia_conservada' => 0,
                'vacia_o_null' => 0,
            ],
            'arma_larga' => [
                'enlace_nuevo' => 0,
                'ya_enlazada_correcta' => 0,
                'conflicto_no_modificado' => 0,
                'sin_coincidencia_conservada' => 0,
                'vacia_o_null' => 0,
            ],
            'relaciones_nuevas_totales' => 0,
            'filas_conflicto' => [],
            'bloques_procesados' => 0,
            'transacciones_completadas' => 0,
        ];

        // Total de vigencias que tienen al menos una matrícula informada
        $baseQuery = DB::table('loc_vigencias_credenciales')
            ->select([
                'id',
                'matricula_corta',
                'matricula_larga',
                'arma_corta_id',
                'arma_larga_id',
                'arma_id',
            ])
            ->orderBy('id');

        $totalVigencias = $baseQuery->count();
        $processed = 0;

        $baseQuery->chunkById($chunkSize, function (Collection $rows) use (&$metrics, &$processed, $totalVigencias, $dryRun, $progressCallback): void {
            $metrics['bloques_procesados']++;
            $updatesForChunk = [];

            // 1. Recolectar matrículas no vacías del bloque
            $cortaSerials = $rows->pluck('matricula_corta')
                ->map(fn ($s) => $this->normalizeSerial($s))
                ->filter()
                ->unique()
                ->values()
                ->all();

            $largaSerials = $rows->pluck('matricula_larga')
                ->map(fn ($s) => $this->normalizeSerial($s))
                ->filter()
                ->unique()
                ->values()
                ->all();

            $allSerials = array_values(array_unique(array_merge($cortaSerials, $largaSerials)));

            // 2. Consultar armas existentes por matrícula exacta (O(1) por arma via keyBy)
            $armas = empty($allSerials)
                ? collect()
                : DB::table('loc_armas')
                    ->whereIn('matricula', $allSerials)
                    ->select(['id', 'matricula'])
                    ->get()
                    ->keyBy('matricula');

            // 3. Evaluar cada vigencia del bloque de forma independiente
            foreach ($rows as $v) {
                $metrics['vigencias_totales_evaluadas']++;
                $rowUpdates = [];

                // --- Evaluación de Arma Corta ---
                $matCorta = $this->normalizeSerial($v->matricula_corta);
                if ($matCorta === null) {
                    $metrics['arma_corta']['vacia_o_null']++;
                    if ($v->matricula_corta !== null && trim((string) $v->matricula_corta) === '') {
                        $rowUpdates['matricula_corta'] = null;
                    }
                } else {
                    if ($v->matricula_corta !== $matCorta) {
                        $rowUpdates['matricula_corta'] = $matCorta;
                    }
                    $candidataCorta = $armas->get($matCorta);
                    if ($candidataCorta === null) {
                        $metrics['arma_corta']['sin_coincidencia_conservada']++;
                    } else {
                        if ($v->arma_corta_id === null) {
                            $rowUpdates['arma_corta_id'] = $candidataCorta->id;
                            $metrics['arma_corta']['enlace_nuevo']++;
                        } elseif ((int) $v->arma_corta_id === (int) $candidataCorta->id) {
                            $metrics['arma_corta']['ya_enlazada_correcta']++;
                        } else {
                            $metrics['arma_corta']['conflicto_no_modificado']++;
                            $metrics['filas_conflicto'][] = [
                                'vigencia_id' => $v->id,
                                'campo' => 'arma_corta_id',
                                'id_actual' => $v->arma_corta_id,
                                'id_candidato' => $candidataCorta->id,
                                'matricula' => $matCorta,
                            ];
                        }
                    }
                }

                // --- Evaluación de Arma Larga ---
                $matLarga = $this->normalizeSerial($v->matricula_larga);
                if ($matLarga === null) {
                    $metrics['arma_larga']['vacia_o_null']++;
                    if ($v->matricula_larga !== null && trim((string) $v->matricula_larga) === '') {
                        $rowUpdates['matricula_larga'] = null;
                    }
                } else {
                    if ($v->matricula_larga !== $matLarga) {
                        $rowUpdates['matricula_larga'] = $matLarga;
                    }
                    $candidataLarga = $armas->get($matLarga);
                    if ($candidataLarga === null) {
                        $metrics['arma_larga']['sin_coincidencia_conservada']++;
                    } else {
                        if ($v->arma_larga_id === null) {
                            $rowUpdates['arma_larga_id'] = $candidataLarga->id;
                            $metrics['arma_larga']['enlace_nuevo']++;
                        } elseif ((int) $v->arma_larga_id === (int) $candidataLarga->id) {
                            $metrics['arma_larga']['ya_enlazada_correcta']++;
                        } else {
                            $metrics['arma_larga']['conflicto_no_modificado']++;
                            $metrics['filas_conflicto'][] = [
                                'vigencia_id' => $v->id,
                                'campo' => 'arma_larga_id',
                                'id_actual' => $v->arma_larga_id,
                                'id_candidato' => $candidataLarga->id,
                                'matricula' => $matLarga,
                            ];
                        }
                    }
                }

                $newLinksInRow = 0;
                if (isset($rowUpdates['arma_corta_id'])) {
                    $newLinksInRow++;
                }
                if (isset($rowUpdates['arma_larga_id'])) {
                    $newLinksInRow++;
                }

                // Si hay enlaces nuevos o normalizaciones que aplicar
                if (! empty($rowUpdates)) {
                    $updatesForChunk[$v->id] = $rowUpdates;
                    $metrics['vigencias_con_actualizacion']++;
                    $metrics['relaciones_nuevas_totales'] += $newLinksInRow;
                }
            }

            // 4. Aplicar escrituras del bloque si no es simulación
            if (! $dryRun && ! empty($updatesForChunk)) {
                DB::transaction(function () use ($updatesForChunk): void {
                    $now = now();
                    foreach ($updatesForChunk as $id => $payload) {
                        $payload['updated_at'] = $now;
                        DB::table('loc_vigencias_credenciales')
                            ->where('id', $id)
                            ->update($payload);
                    }
                });
                $metrics['transacciones_completadas']++;
            }

            $processed += $rows->count();
            if ($progressCallback !== null) {
                $progressCallback($processed, $totalVigencias);
            }
        });

        return $metrics;
    }

    /**
     * Ejecuta una verificación rápida de solo lectura para obtener conteos previstos.
     */
    public function preview(): array
    {
        return $this->run(chunkSize: 1000, dryRun: true);
    }

    /**
     * Valida el estado posterior de la base de datos tras una ejecución real.
     * Retorna array con los conteos reales y si satisfacen la expectativa.
     */
    public function verifyPostConditions(array $expectedMetrics): array
    {
        $cortaEnlazadas = DB::table('loc_vigencias_credenciales')->whereNotNull('arma_corta_id')->count();
        $largaEnlazadas = DB::table('loc_vigencias_credenciales')->whereNotNull('arma_larga_id')->count();
        $armaIdModificadas = DB::table('loc_vigencias_credenciales')->whereNotNull('arma_id')->count();

        // Verificar que las 10 no encontradas sigan en NULL
        $unmatchedCortaWithId = DB::table('loc_vigencias_credenciales as v')
            ->leftJoin('loc_armas as a', 'v.matricula_corta', '=', 'a.matricula')
            ->whereNotNull('v.matricula_corta')
            ->whereNull('a.id')
            ->whereNotNull('v.arma_corta_id')
            ->count();

        $esValido = ($unmatchedCortaWithId === 0)
            && ($armaIdModificadas === 0)
            && ($cortaEnlazadas >= $expectedMetrics['arma_corta']['enlace_nuevo'])
            && ($largaEnlazadas >= $expectedMetrics['arma_larga']['enlace_nuevo']);

        return [
            'es_valido' => $esValido,
            'corta_enlazadas_reales' => $cortaEnlazadas,
            'larga_enlazadas_reales' => $largaEnlazadas,
            'arma_id_no_nulas' => $armaIdModificadas,
            'inconsistencias_sin_coincidencia' => $unmatchedCortaWithId,
        ];
    }

    /**
     * Normaliza una matrícula eliminando espacios en blanco externos, caracteres invisibles o nulos.
     */
    public function normalizeSerial(mixed $val): ?string
    {
        if ($val === null) {
            return null;
        }
        $str = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{FEFF}]/u', ' ', (string) $val) ?? (string) $val;
        $s = strtoupper(trim($str));
        if ($s === '' || $s === '0' || $s === 'S/N' || $s === 'S/A' || $s === 'SIN ASIGNAR' || $s === 'N/A' || $s === 'NULL') {
            return null;
        }
        return $s;
    }
}

