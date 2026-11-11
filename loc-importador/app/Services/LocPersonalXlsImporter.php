<?php

namespace App\Services;

use App\Models\LocArma;
use App\Models\LocImportBatch;
use App\Models\LocImportStagingRow;
use App\Models\LocPersona;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Throwable;

class LocXlsChunkReadFilter implements IReadFilter
{
    public function __construct(private int $startRow, private int $endRow) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row === 1 || ($row >= $this->startRow && $row <= $this->endRow);
    }
}

class LocPersonalXlsImporter
{
    private const CHUNK_SIZE = 500;

    private const FIELD_MAP = [
        'ID_PERSONAL' => 'personal_id',
        'NOMBRE' => 'nombre',
        'RFC' => 'rfc',
        'CUIP' => 'cuip',
        'CURP' => 'curp',
        'CATEGORIA' => 'categoria',
        'SUBDIRECCION' => 'subdireccion',
        'MUNICIPIO' => 'municipio',
        'REGION' => 'region',
        'AGRUPAMIENTO' => 'agrupamiento',
        'CLAVE' => 'clave_seguridad',
        'CUERPO DE SEGURIDAD' => 'cuerpo_seguridad',
        'OBSERVACIONES' => 'observaciones',
        'ESTATUS' => 'estatus',
    ];

    public function stage(string $path, LocImportBatch $batch): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new LocXlsChunkReadFilter(1, 1));
        $headerBook = $reader->load($path);
        $sheet = $headerBook->getActiveSheet();
        $highestColumn = $sheet->getHighestDataColumn();
        $highestRow = $sheet->getHighestDataRow();
        $rawHeaders = $sheet->rangeToArray("A1:{$highestColumn}1", null, true, false)[0] ?? [];
        $headerBook->disconnectWorksheets();
        unset($headerBook);

        if (!$rawHeaders || $highestRow < 2) {
            throw new \RuntimeException('El archivo no contiene encabezados y filas de datos.');
        }

        $headers = $this->uniqueHeaders($rawHeaders);
        $seenCuips = [];
        $totalRead = 0;
        $accepted = 0;
        $rejected = 0;

        for ($start = 2; $start <= $highestRow; $start += self::CHUNK_SIZE) {
            $end = min($start + self::CHUNK_SIZE - 1, $highestRow);
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $reader->setReadFilter(new LocXlsChunkReadFilter($start, $end));
            $book = $reader->load($path);
            $rows = $book->getActiveSheet()->rangeToArray("A{$start}:{$highestColumn}{$end}", null, true, false);
            $book->disconnectWorksheets();
            unset($book);

            $staging = [];
            foreach ($rows as $offset => $cells) {
                $rowNumber = $start + $offset;
                if ($this->isEmptyRow($cells)) {
                    continue;
                }

                $totalRead++;
                $raw = [];
                foreach ($headers as $index => $header) {
                    $raw[$header] = $this->stringValue($cells[$index] ?? null);
                }

                $canonical = $this->mapCanonical($raw);
                $errors = $this->validateRow($canonical);
                $cuip = $canonical['cuip'] ?? null;
                if ($cuip) {
                    $normalized = mb_strtoupper(trim($cuip));
                    if (isset($seenCuips[$normalized])) {
                        $errors[] = "CUIP duplicado dentro del archivo; aparece también en la fila {$seenCuips[$normalized]}.";
                    } else {
                        $seenCuips[$normalized] = $rowNumber;
                    }
                }

                $action = $errors ? 'rechazado' : 'pendiente';
                $staging[] = [
                    'import_batch_id' => $batch->id,
                    'numero_fila' => $rowNumber,
                    'cuip' => $cuip,
                    'datos' => json_encode(['campos' => $canonical, 'origen' => $raw], JSON_UNESCAPED_UNICODE),
                    'errores' => json_encode($errors, JSON_UNESCAPED_UNICODE),
                    'accion' => $action,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $errors ? $rejected++ : $accepted++;
            }

            foreach (array_chunk($staging, 250) as $insertChunk) {
                DB::table('loc_import_staging_rows')->insert($insertChunk);
            }
        }

        // Clasificar las filas válidas contra el padrón existente en lotes de CUIP.
        LocImportStagingRow::where('import_batch_id', $batch->id)
            ->where('accion', 'pendiente')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                $cuips = $rows->pluck('cuip')->filter()->unique()->values()->all();
                $existing = LocPersona::whereIn('cuip', $cuips)->get()->keyBy('cuip');

                foreach ($rows as $row) {
                    $fields = $row->datos['campos'] ?? [];
                    $person = $existing->get($row->cuip);
                    if (!$person) {
                        $row->accion = 'nuevo';
                    } else {
                        $candidate = $this->personAttributes($fields, $row->datos['origen'] ?? []);
                        $same = true;
                        foreach ($candidate as $key => $value) {
                            if ($key === 'datos_loc') {
                                continue;
                            }
                            $old = $person->{$key};
                            if (in_array($key, ['fecha_baja', 'fecha_ingreso'], true)) {
                                $old = $old ? Carbon::parse($old)->format('Y-m-d') : null;
                            }
                            if ((string) ($old ?? '') !== (string) ($value ?? '')) {
                                $same = false;
                                break;
                            }
                        }
                        $row->accion = $same ? 'sin_cambios' : 'actualizar';
                    }
                    $row->save();
                }
            });

        $counts = LocImportStagingRow::where('import_batch_id', $batch->id)
            ->selectRaw('accion, COUNT(*) as total')
            ->groupBy('accion')->pluck('total', 'accion')->all();

        $batch->update([
            'estado' => 'en_revision',
            'total_leidas' => $totalRead,
            'total_aceptadas' => ($counts['nuevo'] ?? 0) + ($counts['actualizar'] ?? 0) + ($counts['sin_cambios'] ?? 0),
            'total_rechazadas' => $counts['rechazado'] ?? 0,
            'total_actualizadas' => $counts['actualizar'] ?? 0,
            'resumen_errores' => json_encode(['conteos' => $counts], JSON_UNESCAPED_UNICODE),
        ]);

        return $counts;
    }

    public function commit(LocImportBatch $batch): array
    {
        if ($batch->estado !== 'en_revision') {
            throw new \RuntimeException('Esta importación no está en estado de revisión.');
        }

        $inserted = 0;
        $updated = 0;
        $unchanged = 0;

        LocImportStagingRow::where('import_batch_id', $batch->id)
            ->whereIn('accion', ['nuevo', 'actualizar', 'sin_cambios'])
            ->orderBy('id')
            ->chunkById(250, function ($rows) use (&$inserted, &$updated, &$unchanged, $batch) {
                DB::transaction(function () use ($rows, &$inserted, &$updated, &$unchanged, $batch) {
                    foreach ($rows as $row) {
                        $fields = $row->datos['campos'] ?? [];
                        $raw = $row->datos['origen'] ?? [];
                        $attributes = $this->personAttributes($fields, $raw);

                        if ($row->accion === 'sin_cambios') {
                            $unchanged++;
                            continue;
                        }

                        $person = LocPersona::where('cuip', $row->cuip)->lockForUpdate()->first();
                        if ($person) {
                            $person->fill($attributes);
                            $person->import_batch_id = $batch->id;
                            $person->save();
                            $updated++;
                        } else {
                            LocPersona::create($attributes + [
                                'import_batch_id' => $batch->id,
                                'activo' => empty($attributes['fecha_baja']),
                            ]);
                            $inserted++;
                        }
                    }
                });
            });

        $batch->update([
            'estado' => 'completado',
            'total_aceptadas' => $inserted + $updated + $unchanged,
            'total_actualizadas' => $updated,
            'resumen_errores' => json_encode([
                'insertadas' => $inserted,
                'actualizadas' => $updated,
                'sin_cambios' => $unchanged,
                'rechazadas' => $batch->total_rechazadas,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return compact('inserted', 'updated', 'unchanged');
    }

    private function personAttributes(array $fields, array $raw): array
    {
        $attributes = [];
        foreach (self::FIELD_MAP as $source => $destination) {
            if (array_key_exists($destination, $fields)) {
                $attributes[$destination] = $fields[$destination] === '' ? null : $fields[$destination];
            }
        }

        $attributes['cuip'] = mb_strtoupper(trim((string) ($fields['cuip'] ?? '')));
        $attributes['fecha_baja'] = $this->dateValue($raw['FECHA DE BAJA'] ?? null);
        $attributes['datos_loc'] = $raw;

        // No se infiere una baja sólo por el texto de estatus: la fecha de baja manda.
        if (array_key_exists('fecha_baja', $attributes) && $attributes['fecha_baja']) {
            $attributes['activo'] = false;
        }

        return $attributes;
    }

    private function mapCanonical(array $raw): array
    {
        $mapped = [];
        foreach (self::FIELD_MAP as $source => $destination) {
            if (array_key_exists($source, $raw)) {
                $mapped[$destination] = trim((string) ($raw[$source] ?? ''));
            }
        }
        return $mapped;
    }

    private function validateRow(array $fields): array
    {
        $errors = [];
        $cuip = trim((string) ($fields['cuip'] ?? ''));
        if ($cuip === '') {
            $errors[] = 'CUIP obligatorio: no se puede identificar de forma segura a la persona.';
        } elseif (mb_strlen($cuip) > 100) {
            $errors[] = 'CUIP excede la capacidad de almacenamiento de 100 caracteres.';
        }

        if (trim((string) ($fields['nombre'] ?? '')) === '') {
            $errors[] = 'Nombre vacío.';
        } elseif (mb_strlen((string) $fields['nombre']) > 255) {
            $errors[] = 'Nombre excede los 255 caracteres permitidos.';
        }

        $rfc = trim((string) ($fields['rfc'] ?? ''));
        if ($rfc !== '' && mb_strlen($rfc) > 20) {
            $errors[] = 'RFC excede los 20 caracteres permitidos.';
        }

        return $errors;
    }

    private function uniqueHeaders(array $headers): array
    {
        $seen = [];
        $result = [];
        foreach ($headers as $index => $value) {
            $header = $this->normalizeHeader((string) $value);
            if ($header === '') {
                $header = 'COLUMNA_SIN_NOMBRE_' . ($index + 1);
            }
            $seen[$header] = ($seen[$header] ?? 0) + 1;
            $result[] = $seen[$header] > 1 ? $header . '__' . $seen[$header] : $header;
        }
        return $result;
    }

    private function normalizeHeader(string $value): string
    {
        $value = trim(mb_strtoupper($value));
        $value = strtr($value, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null) return '';
        if (is_bool($value)) return $value ? '1' : '0';
        return trim((string) $value);
    }

    private function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) ($cell ?? '')) !== '') return false;
        }
        return true;
    }

    private function dateValue(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') return null;

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat('!' . $format, $value);
                if ($date && $date->format($format) === $value) {
                    return $date->format('Y-m-d');
                }
            } catch (Throwable) {
                // Probar siguiente formato.
            }
        }

        // PhpSpreadsheet puede devolver seriales numéricos en algunas celdas de fecha.
        if (is_numeric($value) && (float) $value > 10000) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
