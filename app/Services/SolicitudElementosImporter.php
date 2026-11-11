<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

class SolicitudElementosImporter
{
    /**
     * Importa los elementos de una solicitud desde un archivo Excel (.xlsx o .xls).
     *
     * @return array{total: int, solicitud_id: int}
     */
    public function import(SolicitudCredencial $solicitud, string $filePath, ?int $userId = null): array
    {
        // 1. Prevención estricta de doble carga accidental
        if ($solicitud->elementos()->exists()) {
            $conteo = $solicitud->elementos()->count();
            throw new RuntimeException(
                "La solicitud ya cuenta con elementos importados ({$conteo}). No se permite realizar una segunda carga sobre un expediente que ya tiene elementos cargados."
            );
        }

        // 2. Lectura del archivo mediante PhpSpreadsheet
        try {
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $reader = match ($extension) {
                'xlsx' => IOFactory::createReader('Xlsx'),
                'xls' => IOFactory::createReader('Xls'),
                default => IOFactory::createReaderForFile($filePath),
            };
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rawRows = $worksheet->toArray(null, false, false, false);
        } catch (Throwable $e) {
            throw new RuntimeException('El archivo no corresponde a un libro Excel legible o está dañado: ' . $e->getMessage(), 0, $e);
        }

        if (empty($rawRows)) {
            throw new RuntimeException('El archivo Excel está completamente vacío.');
        }

        // 3. Identificación de la fila de encabezados y mapeo de columnas
        [$headerIndex, $mapping, $rawHeaders] = $this->detectHeaders($rawRows);

        // 4. Procesamiento atómico de filas de datos
        return DB::transaction(function () use ($solicitud, $rawRows, $headerIndex, $mapping, $rawHeaders): array {
            $elementosToInsert = [];
            $totalFilas = count($rawRows);

            for ($i = $headerIndex + 1; $i < $totalFilas; $i++) {
                $physicalRowNumber = $i + 1; // Fila real física en Excel (1-based)
                $row = $rawRows[$i];

                // Si toda la fila está completamente vacía (sin texto en ninguna celda), se omite
                if ($this->isRowBlank($row)) {
                    continue;
                }

                // Construcción de datos originales íntegros de la fila
                $datosOriginales = [];
                foreach ($row as $colIdx => $cellVal) {
                    $headerLabel = $rawHeaders[$colIdx] ?? 'COLUMNA_' . ($colIdx + 1);
                    $datosOriginales[$headerLabel] = $cellVal !== null ? (string) $cellVal : null;
                }

                // Extracción normalizada de CUIP
                $rawCuip = isset($mapping['cuip']) && isset($row[$mapping['cuip']])
                    ? trim(preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{FEFF}]/u', ' ', (string) $row[$mapping['cuip']]))
                    : null;
                $cuip = null;
                if ($rawCuip !== null && $rawCuip !== '' && strtolower($rawCuip) !== 'null' && strtolower($rawCuip) !== 's/n') {
                    $cuip = strtoupper(preg_replace('/\s+/', '', $rawCuip));
                }

                // Extracción de matrículas
                $matriculaCorta = null;
                if (isset($mapping['corta']) && isset($row[$mapping['corta']])) {
                    $valCorta = trim(preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{FEFF}]/u', ' ', (string) $row[$mapping['corta']]));
                    if ($valCorta !== '' && strtolower($valCorta) !== 'null' && strtolower($valCorta) !== 's/n' && strtolower($valCorta) !== 's/m' && $valCorta !== '0') {
                        $matriculaCorta = strtoupper($valCorta);
                    }
                }

                $matriculaLarga = null;
                if (isset($mapping['larga']) && isset($row[$mapping['larga']])) {
                    $valLarga = trim(preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{FEFF}]/u', ' ', (string) $row[$mapping['larga']]));
                    if ($valLarga !== '' && strtolower($valLarga) !== 'null' && strtolower($valLarga) !== 's/n' && strtolower($valLarga) !== 's/m' && $valLarga !== '0') {
                        $matriculaLarga = strtoupper($valLarga);
                    }
                }

                // Extracción de metadatos solicitados (nombre, adscripción, etc.)
                $datosSolicitados = [];
                if (isset($mapping['nombre']) && isset($row[$mapping['nombre']])) {
                    $datosSolicitados['nombre'] = trim(preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{FEFF}]/u', ' ', (string) $row[$mapping['nombre']]));
                }
                if (isset($mapping['categoria']) && isset($row[$mapping['categoria']])) {
                    $datosSolicitados['categoria'] = trim(preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{FEFF}]/u', ' ', (string) $row[$mapping['categoria']]));
                }
                if (isset($mapping['municipio']) && isset($row[$mapping['municipio']])) {
                    $datosSolicitados['municipio'] = trim(preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{FEFF}]/u', ' ', (string) $row[$mapping['municipio']]));
                }
                if (isset($mapping['adscripcion']) && isset($row[$mapping['adscripcion']])) {
                    $datosSolicitados['adscripcion'] = trim(preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{FEFF}]/u', ' ', (string) $row[$mapping['adscripcion']]));
                }

                $elementosToInsert[] = [
                    'solicitud_id' => $solicitud->id,
                    'numero_fila' => $physicalRowNumber,
                    'persona_id' => null,
                    'cuip' => $cuip,
                    'matricula_corta' => $matriculaCorta,
                    'matricula_larga' => $matriculaLarga,
                    'arma_corta_id' => null,
                    'arma_larga_id' => null,
                    'tipo_elemento' => 'persona',
                    'datos_solicitados' => $datosSolicitados,
                    'resultado_prefiltro' => null,
                    'motivos_descarte' => null,
                    'datos_originales_fila' => $datosOriginales,
                    'estado_tramite' => 'recibido',
                    'motivo_rechazo' => null,
                    'motivo_retencion' => null,
                    'observaciones' => null,
                ];
            }

            if (empty($elementosToInsert)) {
                throw new RuntimeException('El archivo no contiene filas de elementos para importar.');
            }

            // Inserción de cada elemento mediante el modelo Eloquent para garantizar casts y consistencia
            foreach ($elementosToInsert as $data) {
                SolicitudElemento::create($data);
            }

            $totalImportados = count($elementosToInsert);

            // Actualización de la solicitud con el total real de elementos importados
            $solicitud->update([
                'total_elementos' => $totalImportados,
                'estado_general' => 'recibida',
            ]);

            return [
                'total' => $totalImportados,
                'solicitud_id' => $solicitud->id,
            ];
        });
    }

    /**
     * Detecta la fila de encabezados y mapea los índices de las columnas requeridas.
     *
     * @return array{0: int, 1: array<string, int>, 2: array<int, string>}
     */
    private function detectHeaders(array $rows): array
    {
        $maxScanRows = min(100, count($rows));
        $cuipWithoutArmsFound = false;

        for ($r = 0; $r < $maxScanRows; $r++) {
            $row = $rows[$r];
            $normalizedRow = array_map(fn ($cell) => $this->normalizeString((string) $cell), $row);

            $cuipIndex = null;
            $cortaIndex = null;
            $largaIndex = null;
            $nombreIndex = null;
            $categoriaIndex = null;
            $municipioIndex = null;
            $adscripcionIndex = null;

            foreach ($normalizedRow as $colIdx => $text) {
                if ($text === '') {
                    continue;
                }

                // Detección de CUIP
                if ($cuipIndex === null && (
                    $text === 'cuip' ||
                    str_contains($text, 'cuip') ||
                    $text === 'claveunicadeidentificacionpersonal'
                )) {
                    $cuipIndex = $colIdx;
                    continue;
                }

                // Detección de Arma Corta
                if ($cortaIndex === null && (
                    str_contains($text, 'corta') ||
                    $text === 'pistola' ||
                    $text === 'revolver' ||
                    str_contains($text, 'armacorta') ||
                    str_contains($text, 'matriculacorta')
                )) {
                    $cortaIndex = $colIdx;
                    continue;
                }

                // Detección de Arma Larga
                if ($largaIndex === null && (
                    str_contains($text, 'larga') ||
                    $text === 'fusil' ||
                    $text === 'carabina' ||
                    str_contains($text, 'armalarga') ||
                    str_contains($text, 'matriculalarga')
                )) {
                    $largaIndex = $colIdx;
                    continue;
                }

                // Detección de Nombre
                if ($nombreIndex === null && (
                    str_contains($text, 'nombre') ||
                    $text === 'elemento' ||
                    $text === 'personal'
                )) {
                    $nombreIndex = $colIdx;
                    continue;
                }

                // Detección de Categoría
                if ($categoriaIndex === null && (
                    str_contains($text, 'categoria') ||
                    str_contains($text, 'grado') ||
                    str_contains($text, 'rango') ||
                    str_contains($text, 'puesto')
                )) {
                    $categoriaIndex = $colIdx;
                    continue;
                }

                // Detección de Municipio
                if ($municipioIndex === null && (
                    str_contains($text, 'municipio') ||
                    str_contains($text, 'alcaldia')
                )) {
                    $municipioIndex = $colIdx;
                    continue;
                }

                // Detección de Adscripción
                if ($adscripcionIndex === null && (
                    str_contains($text, 'adscripcion') ||
                    str_contains($text, 'unidad') ||
                    str_contains($text, 'coordinacion') ||
                    str_contains($text, 'agrupamiento') ||
                    str_contains($text, 'area')
                )) {
                    $adscripcionIndex = $colIdx;
                    continue;
                }
            }

            // Si encontramos la columna de CUIP en esta fila
            if ($cuipIndex !== null) {
                // Si además existe al menos una columna de armamento, es la cabecera válida
                if ($cortaIndex !== null || $largaIndex !== null) {
                    $mapping = ['cuip' => $cuipIndex];
                    if ($cortaIndex !== null) {
                        $mapping['corta'] = $cortaIndex;
                    }
                    if ($largaIndex !== null) {
                        $mapping['larga'] = $largaIndex;
                    }
                    if ($nombreIndex !== null) {
                        $mapping['nombre'] = $nombreIndex;
                    }
                    if ($categoriaIndex !== null) {
                        $mapping['categoria'] = $categoriaIndex;
                    }
                    if ($municipioIndex !== null) {
                        $mapping['municipio'] = $municipioIndex;
                    }
                    if ($adscripcionIndex !== null) {
                        $mapping['adscripcion'] = $adscripcionIndex;
                    }

                    // Guardar nombres originales de las cabeceras
                    $rawHeaders = [];
                    foreach ($row as $colIdx => $val) {
                        $rawHeaders[$colIdx] = trim((string) $val);
                    }

                    return [$r, $mapping, $rawHeaders];
                }

                // Se encontró CUIP pero sin armas en esta fila; puede ser un título o bloque superior
                $cuipWithoutArmsFound = true;
            }
        }

        if ($cuipWithoutArmsFound) {
            throw new RuntimeException(
                'Estructura ambigua o incompleta: se detectó la columna CUIP pero no fue posible identificar las columnas de armamento (se requiere al menos arma corta o arma larga en los encabezados).'
            );
        }

        throw new RuntimeException(
            'No fue posible identificar la fila de encabezados con la columna obligatoria de CUIP en el archivo.'
        );
    }

    /**
     * Normaliza un texto para comparación de cabeceras (minúsculas, sin acentos ni espacios ni signos).
     */
    private function normalizeString(string $value): string
    {
        $cleanSpaces = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{FEFF}]/u', ' ', $value) ?? $value;
        $ascii = Str::ascii($cleanSpaces);
        $clean = preg_replace('/[^a-zA-Z0-9]/', '', $ascii) ?? '';
        return strtolower(trim($clean));
    }

    /**
     * Determina si una fila está completamente en blanco.
     */
    private function isRowBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }
        return true;
    }
}

