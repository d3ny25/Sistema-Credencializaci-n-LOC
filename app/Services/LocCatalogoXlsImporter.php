<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LocCatalogoOrganizacional;
use App\Models\LocCatalogoVersion;
use App\Models\LocImportBatch;
use App\Models\LocImportStagingRow;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use RuntimeException;
use Throwable;

class LocCatalogoXlsImporter
{
    public const SOURCE_TYPE = 'catalogo_organizacional';

    public const COL_DIRECCION_GENERAL = 0; // A
    public const COL_COORDINACION = 1;      // B
    public const COL_SUBDIRECCION = 2;      // C
    public const COL_REGION = 3;            // D
    public const COL_AGRUPAMIENTO = 4;       // E
    public const COL_MUNICIPIO = 5;          // F
    public const COL_COORDINACION_DUP = 6;  // G
    public const COL_CLAVE = 7;             // H
    public const COL_ZONA_MILITAR = 8;      // I
    public const COL_BATALLON = 9;          // J

    /**
     * Procesa un archivo Excel y prepara las filas en la tabla de staging.
     */
    public function stage(string $filePath, LocImportBatch $batch): void
    {
        if ($batch->tipo_fuente !== self::SOURCE_TYPE) {
            throw new RuntimeException('El lote no corresponde a la fuente de catálogo organizacional.');
        }

        $batch->estado = 'procesando';
        $batch->save();

        try {
            $reader = $this->createReader($filePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray(null, true, false, false);
        } catch (Throwable $e) {
            $batch->estado = 'fallido';
            $batch->resumen_errores = json_encode([
                'error' => 'No fue posible leer el archivo Excel: ' . $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
            $batch->save();

            throw new RuntimeException('Error al abrir el archivo de catálogo: ' . $e->getMessage(), 0, $e);
        }

        $this->stageFromRows($rows, $batch);
    }

    /**
     * Procesa una matriz de filas (útil para pruebas y datasets en memoria).
     */
    public function stageFromRows(array $rows, LocImportBatch $batch): array
    {
        if ($batch->tipo_fuente !== self::SOURCE_TYPE) {
            throw new RuntimeException('El lote no corresponde a la fuente de catálogo organizacional.');
        }

        if (empty($rows)) {
            $batch->estado = 'fallido';
            $batch->resumen_errores = json_encode(['error' => 'El archivo está vacío.'], JSON_UNESCAPED_UNICODE);
            $batch->save();

            throw new RuntimeException('El archivo está vacío.');
        }

        // 1. Validar encabezados en la fila 1
        $headerRow = array_shift($rows);
        $this->validateHeaders($headerRow, $batch);

        // 2. Obtener la versión activa actual para comparar cambios
        $activeVersion = LocCatalogoVersion::query()->where('es_vigente', true)->first();
        $activeItems = $activeVersion
            ? LocCatalogoOrganizacional::query()
                ->where('import_batch_id', $activeVersion->import_batch_id)
                ->get()
                ->keyBy('clave_origen')
            : collect();

        $seenKeys = [];
        $counts = [
            'nuevo' => 0,
            'actualizar' => 0,
            'sin_cambios' => 0,
            'conflicto' => 0,
            'rechazado' => 0,
        ];

        $stagingBuffer = [];
        $rowNumber = 1; // Inicia en 1 porque la fila 1 fueron los encabezados
        $totalDataRows = 0;

        foreach ($rows as $rawCells) {
            $rowNumber++;

            if ($this->isEmptyRow($rawCells)) {
                continue;
            }

            $totalDataRows++;
            $errors = [];
            $action = 'nuevo';

            // Mapeo posicional exacto de campos
            $rawDirGeneral = trim((string) ($rawCells[self::COL_DIRECCION_GENERAL] ?? ''));
            $rawCoord = trim((string) ($rawCells[self::COL_COORDINACION] ?? ''));
            $rawSubdir = trim((string) ($rawCells[self::COL_SUBDIRECCION] ?? ''));
            $rawRegion = trim((string) ($rawCells[self::COL_REGION] ?? ''));
            $rawAgrupamiento = trim((string) ($rawCells[self::COL_AGRUPAMIENTO] ?? ''));
            $rawMunicipio = trim((string) ($rawCells[self::COL_MUNICIPIO] ?? ''));
            $rawCoordDup = trim((string) ($rawCells[self::COL_COORDINACION_DUP] ?? ''));
            $rawClave = trim((string) ($rawCells[self::COL_CLAVE] ?? ''));
            $rawZonaMilitar = trim((string) ($rawCells[self::COL_ZONA_MILITAR] ?? ''));
            $rawBatallon = trim((string) ($rawCells[self::COL_BATALLON] ?? ''));

            // Normalización de opcionales
            $batallon = $rawBatallon !== '' ? $rawBatallon : null;

            // Validación de discrepancia Col B vs Col G (Coordinación duplicada)
            if ($rawCoordDup !== '' && $rawCoord !== '' && mb_strtoupper($rawCoordDup) !== mb_strtoupper($rawCoord)) {
                $errors[] = "Divergencia estructural: La columna G (Coordinación) no coincide con la columna B ('{$rawCoordDup}' vs '{$rawCoord}').";
                $action = 'conflicto';
            }

            // Validación estricta de CLAVE (Columna H)
            if ($rawClave === '') {
                $errors[] = 'La columna CLAVE es obligatoria y no puede estar vacía.';
                $action = 'rechazado';
            } else {
                $upperKey = mb_strtoupper($rawClave);
                if (isset($seenKeys[$upperKey])) {
                    $firstRow = $seenKeys[$upperKey];
                    $errors[] = "Clave '{$rawClave}' duplicada dentro del archivo (ya registrada en fila {$firstRow}).";
                    $action = 'conflicto';
                } else {
                    $seenKeys[$upperKey] = $rowNumber;
                }
            }

            // Validación de obligatoriedad de campos descriptivos canónicos
            if ($rawDirGeneral === '') {
                $errors[] = 'El campo DIRECCION GENERAL es obligatorio.';
                $action = 'rechazado';
            }
            if ($rawCoord === '') {
                $errors[] = 'El campo COORDINACION es obligatorio.';
                $action = 'rechazado';
            }
            if ($rawSubdir === '') {
                $errors[] = 'El campo SUBDIRECCION es obligatorio.';
                $action = 'rechazado';
            }
            if ($rawRegion === '') {
                $errors[] = 'El campo REGION es obligatorio.';
                $action = 'rechazado';
            }
            if ($rawAgrupamiento === '') {
                $errors[] = 'El campo AGRUPAMIENTO es obligatorio.';
                $action = 'rechazado';
            }
            if ($rawMunicipio === '') {
                $errors[] = 'El campo MUNICIPIO es obligatorio.';
                $action = 'rechazado';
            }
            if ($rawZonaMilitar === '') {
                $errors[] = 'El campo ZONA MILITAR es obligatorio.';
                $action = 'rechazado';
            }

            $payload = [
                'clave_origen' => $rawClave,
                'direccion_general' => $rawDirGeneral,
                'coordinacion' => $rawCoord,
                'subdireccion' => $rawSubdir,
                'region' => $rawRegion,
                'agrupamiento' => $rawAgrupamiento,
                'municipio' => $rawMunicipio,
                'zona_militar' => $rawZonaMilitar,
                'batallon' => $batallon,
            ];

            // Si no tiene errores ni conflictos, clasificar respecto a la versión vigente
            if (empty($errors)) {
                if ($activeItems->has($rawClave)) {
                    $current = $activeItems->get($rawClave);
                    if ($this->isIdentical($current, $payload)) {
                        $action = 'sin_cambios';
                    } else {
                        $action = 'actualizar';
                    }
                } else {
                    $action = 'nuevo';
                }
            }

            $counts[$action]++;

            $stagingBuffer[] = [
                'import_batch_id' => $batch->id,
                'numero_fila' => $rowNumber,
                'clave_origen' => $rawClave !== '' ? $rawClave : null,
                'cuip' => null,
                'matricula' => null,
                'id_armamento' => null,
                'datos' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'errores' => ! empty($errors) ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null,
                'accion' => $action,
                'estado_aplicacion' => 'pendiente',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($stagingBuffer) >= 200) {
                LocImportStagingRow::insert($stagingBuffer);
                $stagingBuffer = [];
            }
        }

        if (! empty($stagingBuffer)) {
            LocImportStagingRow::insert($stagingBuffer);
        }

        // Calcular huella de revisión
        $revisionSha = $this->reviewFingerprint($batch);

        $batch->total_leidas = $totalDataRows;
        $batch->total_aceptadas = $counts['nuevo'] + $counts['actualizar'] + $counts['sin_cambios'];
        $batch->total_rechazadas = $counts['rechazado'] + $counts['conflicto'];
        $batch->total_insertadas = $counts['nuevo'];
        $batch->total_actualizadas = $counts['actualizar'];
        $batch->total_sin_cambios = $counts['sin_cambios'];
        $batch->resumen_errores = json_encode($counts, JSON_THROW_ON_ERROR);
        $batch->revision_sha256 = $revisionSha;
        $batch->estado = 'en_revision';
        $batch->save();

        return [
            'total_read' => $totalDataRows,
            'counts' => $counts,
            'revision_sha256' => $revisionSha,
        ];
    }

    /**
     * Confirma un lote en revisión y publica atómicamente la nueva versión.
     */
    public function commit(LocImportBatch $batch, int $userId): array
    {
        try {
            return Cache::lock('loc_catalogo_confirm_lock', 60)
                ->block(10, function () use ($batch, $userId): array {
                    return DB::transaction(function () use ($batch, $userId): array {
                        $lockedBatch = LocImportBatch::query()
                            ->where('id', $batch->id)
                            ->lockForUpdate()
                            ->firstOrFail();

                        if ($lockedBatch->tipo_fuente !== self::SOURCE_TYPE) {
                            throw new RuntimeException('El lote no corresponde a la fuente de catálogo organizacional.');
                        }

                        // Idempotencia: si el lote ya fue confirmado previamente
                        if ($lockedBatch->estado === 'completado') {
                            $existingVersion = LocCatalogoVersion::query()
                                ->where('import_batch_id', $lockedBatch->id)
                                ->first();

                            return [
                                'already_confirmed' => true,
                                'version' => $existingVersion?->version_numero,
                                'inserted' => (int) $lockedBatch->total_insertadas,
                                'updated' => (int) $lockedBatch->total_actualizadas,
                                'unchanged' => (int) $lockedBatch->total_sin_cambios,
                                'rejected' => (int) $lockedBatch->total_rechazadas,
                            ];
                        }

                        if ($lockedBatch->estado !== 'en_revision') {
                            throw new RuntimeException('El lote no está en estado de revisión para confirmación.');
                        }

                        // Validar huella de revisión contra staging
                        $currentFingerprint = $this->reviewFingerprint($lockedBatch);
                        if (! hash_equals($lockedBatch->revision_sha256 ?? '', $currentFingerprint)) {
                            throw new RuntimeException('La revisión del lote cambió después del análisis inicial. Vuelve a analizar el archivo antes de confirmar.');
                        }

                        // Validar que no existan conflictos o rechazos
                        $blockingCount = LocImportStagingRow::query()
                            ->where('import_batch_id', $lockedBatch->id)
                            ->whereIn('accion', ['conflicto', 'rechazado'])
                            ->count();

                        if ($blockingCount > 0) {
                            throw new RuntimeException("El lote contiene {$blockingCount} filas rechazadas o en conflicto. No se aplicó ningún cambio.");
                        }

                        // Determinar el número correlativo de la nueva versión
                        $lastVersionNum = (int) (LocCatalogoVersion::query()->max('version_numero') ?? 0);
                        $newVersionNum = $lastVersionNum + 1;

                        $insertedCount = 0;
                        $now = now();

                        // PASO 1: Insertar todas las filas válidas en loc_catalogo_organizacional con activo = false
                        LocImportStagingRow::query()
                            ->where('import_batch_id', $lockedBatch->id)
                            ->whereIn('accion', ['nuevo', 'actualizar', 'sin_cambios'])
                            ->chunkById(250, function ($rows) use ($lockedBatch, $now, &$insertedCount): void {
                                $records = [];
                                foreach ($rows as $row) {
                                    $d = is_array($row->datos) ? $row->datos : json_decode((string) $row->datos, true);
                                    $records[] = [
                                        'import_batch_id' => $lockedBatch->id,
                                        'clave_origen' => $d['clave_origen'],
                                        'nivel_jerarquico' => null,
                                        'nombre' => $d['agrupamiento'], // Duplicado intencional de agrupamiento para compatibilidad con vistas existentes
                                        'direccion_general' => $d['direccion_general'],
                                        'coordinacion' => $d['coordinacion'],
                                        'subdireccion' => $d['subdireccion'],
                                        'region' => $d['region'],
                                        'agrupamiento' => $d['agrupamiento'],
                                        'municipio' => $d['municipio'],
                                        'zona_militar' => $d['zona_militar'],
                                        'batallon' => $d['batallon'] ?? null,
                                        'padre_id' => null,
                                        'activo' => false, // Inactivo durante la inserción
                                        'created_at' => $now,
                                        'updated_at' => $now,
                                    ];
                                    $insertedCount++;
                                }
                                LocCatalogoOrganizacional::insert($records);
                            });

                        // PASO 2: Activación atómica en la misma transacción
                        // 2.1. Desactivar versión anterior en tabla de control
                        LocCatalogoVersion::query()
                            ->where('es_vigente', true)
                            ->update(['es_vigente' => false]);

                        // 2.2. Registrar nueva versión autoritativa activa
                        $versionRecord = LocCatalogoVersion::create([
                            'import_batch_id' => $lockedBatch->id,
                            'version_numero' => $newVersionNum,
                            'es_vigente' => true,
                            'total_registros' => $insertedCount,
                            'archivo_nombre' => $lockedBatch->nombre_archivo,
                            'archivo_sha256' => $lockedBatch->archivo_sha256,
                            'usuario_id' => $userId,
                            'fecha_activacion' => $now,
                        ]);

                        // 2.3. Desactivar registros anteriores en loc_catalogo_organizacional
                        LocCatalogoOrganizacional::query()
                            ->where('activo', true)
                            ->update(['activo' => false]);

                        // 2.4. Activar registros del nuevo lote
                        LocCatalogoOrganizacional::query()
                            ->where('import_batch_id', $lockedBatch->id)
                            ->update(['activo' => true]);

                        // 2.5. Marcar staging como aplicado
                        LocImportStagingRow::query()
                            ->where('import_batch_id', $lockedBatch->id)
                            ->update(['estado_aplicacion' => 'aplicado']);

                        // 2.6. Completar lote
                        $lockedBatch->estado = 'completado';
                        $lockedBatch->fecha_confirmacion = $now;
                        $lockedBatch->save();

                        // 2.7. Actualizar puntero de caché
                        Cache::forever('loc_catalogo_vigente_batch_id', $lockedBatch->id);

                        Log::info('Versión de catálogo organizacional publicada exitosamente.', [
                            'batch_id' => $lockedBatch->id,
                            'version_numero' => $newVersionNum,
                            'total_registros' => $insertedCount,
                            'user_id' => $userId,
                        ]);

                        return [
                            'already_confirmed' => false,
                            'version' => $newVersionNum,
                            'inserted' => (int) $lockedBatch->total_insertadas,
                            'updated' => (int) $lockedBatch->total_actualizadas,
                            'unchanged' => (int) $lockedBatch->total_sin_cambios,
                            'rejected' => 0,
                        ];
                    });
                });
        } catch (LockTimeoutException) {
            throw new RuntimeException('Otra confirmación del catálogo organizacional se encuentra en ejecución. Espera unos segundos e inténtalo nuevamente.');
        }
    }

    /**
     * Calcula la huella SHA-256 de las filas en staging para asegurar integridad.
     */
    public function reviewFingerprint(LocImportBatch $batch): string
    {
        $hash = hash_init('sha256');

        LocImportStagingRow::query()
            ->where('import_batch_id', $batch->id)
            ->orderBy('numero_fila')
            ->select(['numero_fila', 'clave_origen', 'accion'])
            ->chunk(1000, function ($rows) use ($hash): void {
                foreach ($rows as $row) {
                    hash_update($hash, "{$row->numero_fila}|{$row->clave_origen}|{$row->accion}\n");
                }
            });

        return hash_final($hash);
    }

    private function validateHeaders(array $headers, LocImportBatch $batch): void
    {
        $normalized = array_map(fn ($h) => mb_strtoupper(trim((string) $h)), $headers);

        $requiredHeaders = [
            'DIRECCION GENERAL',
            'COORDINACION',
            'SUBDIRECCION',
            'REGION',
            'AGRUPAMIENTO',
            'MUNICIPIO',
            'CLAVE',
            'ZONA MILITAR',
        ];

        foreach ($requiredHeaders as $req) {
            if (! in_array($req, $normalized, true)) {
                $batch->estado = 'fallido';
                $batch->resumen_errores = json_encode([
                    'error' => "Estructura inválida: Falta la columna obligatoria '{$req}' en el archivo.",
                ], JSON_UNESCAPED_UNICODE);
                $batch->save();

                throw new RuntimeException("Estructura inválida: Falta la columna obligatoria '{$req}' en el archivo.");
            }
        }

        // Validar que la columna H sea efectivamente CLAVE
        if (! isset($normalized[self::COL_CLAVE]) || $normalized[self::COL_CLAVE] !== 'CLAVE') {
            $batch->estado = 'fallido';
            $batch->resumen_errores = json_encode([
                'error' => "Estructura inválida: La columna H debe corresponder a 'CLAVE'.",
            ], JSON_UNESCAPED_UNICODE);
            $batch->save();

            throw new RuntimeException("Estructura inválida: La columna H debe corresponder a 'CLAVE'.");
        }
    }

    private function isIdentical(LocCatalogoOrganizacional $current, array $payload): bool
    {
        return $current->direccion_general === $payload['direccion_general']
            && $current->coordinacion === $payload['coordinacion']
            && $current->subdireccion === $payload['subdireccion']
            && $current->region === $payload['region']
            && $current->agrupamiento === $payload['agrupamiento']
            && $current->municipio === $payload['municipio']
            && $current->zona_militar === $payload['zona_militar']
            && ($current->batallon ?? null) === ($payload['batallon'] ?? null);
    }

    private function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function createReader(string $filePath): IReader
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'xlsx' => IOFactory::createReader('Xlsx'),
            'xls' => IOFactory::createReader('Xls'),
            default => IOFactory::createReaderForFile($filePath),
        };
    }
}

