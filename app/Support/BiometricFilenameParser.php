<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\BiometricoCategoria;
use App\Enums\BiometricoTipoIdentificacion;

class BiometricFilenameParser
{
    /**
     * Extensiones biométricas de imagen oficialmente reconocidas.
     */
    public const EXTENSIONES_PERMITIDAS = ['jpg', 'jpeg', 'png', 'wsq'];

    /**
     * Extensiones y nombres de archivos de sistema o texto a excluir.
     */
    public const ARCHIVOS_SISTEMA = ['desktop.ini', 'thumbs.db', '.ds_store'];
    public const EXTENSIONES_SISTEMA = ['txt', 'ini', 'db', 'tmp', 'bak'];

    /**
     * Expresión regular para CUIP Estándar Nacional (16 a 20 caracteres):
     * 4 letras iniciales + 6 dígitos fecha nacimiento (AAMMDD) + 1 sexo (H/M) + 2 entidad federativa + 3 a 7 dígitos/alfanuméricos.
     */
    public const REGEX_CUIP_ESTANDAR = '/^[A-Z]{4}\d{6}[HM][A-Z0-9]{2}\d{3,7}$/i';

    /**
     * Expresión regular para CUIP con estructura alfanumérica estándar amplia (16 a 20 caracteres).
     */
    public const REGEX_CUIP_ESTANDAR_AMPLIO = '/^[A-Z]{4}\d{6}[A-Z0-9]{6,10}$/i';

    /**
     * Expresión regular para Identificador Corto / Prefijo RFC (10 caracteres exactos: 4 letras + 6 dígitos fecha).
     */
    public const REGEX_CORTO_RFC = '/^[A-Z]{4}\d{6}$/i';

    /**
     * Expresión regular para UUID/GUID de hardware (32 o 36 caracteres hexadecimales con o sin llaves).
     */
    public const REGEX_GUID_HARDWARE = '/^\{?[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\}?$/i';

    /**
     * Interpreta un nombre o ruta relativa de archivo biométrico y retorna un resultado estructurado.
     *
     * @param  string  $filenameOrPath Nombre del archivo o ruta relativa (ej. 'ESTATALES/FOTOS/AAAA690916H15136329.JPG')
     * @param  BiometricoCategoria|null  $categoriaContext Categoría contextual conocida (opcional)
     * @return BiometricParseResult
     */
    public function parse(string $filenameOrPath, ?BiometricoCategoria $categoriaContext = null): BiometricParseResult
    {
        $normalizedPath = str_replace('\\', '/', trim($filenameOrPath));
        $basename = basename($normalizedPath);

        // 1. Detección de archivos de sistema conocidos (ej. desktop.ini)
        if (in_array(strtolower($basename), self::ARCHIVOS_SISTEMA, true)) {
            return new BiometricParseResult(
                nombreArchivo: $basename,
                rutaRelativa: $normalizedPath,
                extension: strtolower(pathinfo($basename, PATHINFO_EXTENSION)),
                cuipDetectado: null,
                cuipNormalizado: null,
                categoria: null,
                tipoIdentificacion: BiometricoTipoIdentificacion::SISTEMA,
                esValido: false,
                esCuipValido: false,
                esExtensionAdmitida: false,
                motivoInvalidez: 'Archivo de sistema o metadato operativo de Windows',
                codigoIncidencia: 'ARCHIVO_SISTEMA'
            );
        }

        // 2. Extracción y validación de extensión
        $extensionRaw = pathinfo($basename, PATHINFO_EXTENSION);
        $extension = strtolower(trim($extensionRaw));
        $filenameWithoutExt = pathinfo($basename, PATHINFO_FILENAME);

        // Comprobación de dot-files (archivos ocultos sin nombre como '.png' o '.jpg')
        if ($filenameWithoutExt === '' || str_starts_with($basename, '.')) {
            return new BiometricParseResult(
                nombreArchivo: $basename,
                rutaRelativa: $normalizedPath,
                extension: $extension ?: strtolower(ltrim($basename, '.')),
                cuipDetectado: null,
                cuipNormalizado: null,
                categoria: $categoriaContext ?? $this->inferirCategoriaDesdeRuta($normalizedPath),
                tipoIdentificacion: BiometricoTipoIdentificacion::SIN_NOMBRE,
                esValido: false,
                esCuipValido: false,
                esExtensionAdmitida: in_array($extension, self::EXTENSIONES_PERMITIDAS, true),
                motivoInvalidez: 'Archivo oculto sin nombre de base (dot-file)',
                codigoIncidencia: 'NOMBRE_ANOMALO'
            );
        }

        $esExtensionAdmitida = in_array($extension, self::EXTENSIONES_PERMITIDAS, true);
        if (! $esExtensionAdmitida && in_array($extension, self::EXTENSIONES_SISTEMA, true)) {
            return new BiometricParseResult(
                nombreArchivo: $basename,
                rutaRelativa: $normalizedPath,
                extension: $extension,
                cuipDetectado: null,
                cuipNormalizado: null,
                categoria: null,
                tipoIdentificacion: BiometricoTipoIdentificacion::SISTEMA,
                esValido: false,
                esCuipValido: false,
                esExtensionAdmitida: false,
                motivoInvalidez: "Archivo no biométrico con extensión '{$extension}'",
                codigoIncidencia: 'NO_IMAGEN'
            );
        }

        // 3. Resolución de categoría biométrica
        $categoria = $categoriaContext ?? $this->inferirCategoriaDesdeRuta($normalizedPath) ?? $this->inferirCategoriaDesdeNombre($filenameWithoutExt);

        // 4. Identificación y clasificación del CUIP / Nombre de base
        $cuipRaw = trim($filenameWithoutExt);

        // Caso A: GUID de hardware de lector biométrico
        if ((bool) preg_match(self::REGEX_GUID_HARDWARE, $cuipRaw)) {
            return new BiometricParseResult(
                nombreArchivo: $basename,
                rutaRelativa: $normalizedPath,
                extension: $extension,
                cuipDetectado: null,
                cuipNormalizado: null,
                categoria: $categoria,
                tipoIdentificacion: BiometricoTipoIdentificacion::GUID_HARDWARE,
                esValido: false,
                esCuipValido: false,
                esExtensionAdmitida: $esExtensionAdmitida,
                motivoInvalidez: 'Identificador hexadecimal de hardware sin CUIP institucional',
                codigoIncidencia: 'NOMBRE_GUID_SIN_CUIP'
            );
        }

        // Caso B: CUIP Corto / Prefijo RFC (10 caracteres)
        if ((bool) preg_match(self::REGEX_CORTO_RFC, $cuipRaw)) {
            return new BiometricParseResult(
                nombreArchivo: $basename,
                rutaRelativa: $normalizedPath,
                extension: $extension,
                cuipDetectado: strtoupper($cuipRaw),
                cuipNormalizado: null, // No normalizado porque requiere cruce secundario con loc_personas
                categoria: $categoria,
                tipoIdentificacion: BiometricoTipoIdentificacion::CORTO_RFC,
                esValido: false, // No es válido de forma autónoma hasta ser homologado
                esCuipValido: false,
                esExtensionAdmitida: $esExtensionAdmitida,
                motivoInvalidez: 'Nombre corto correspondiente a prefijo RFC de 10 caracteres; requiere homologación con padrón',
                codigoIncidencia: 'PATRON_CUIP_CORTO_RFC'
            );
        }

        // Caso C: CUIP Estándar Nacional (16 a 20 caracteres)
        $matchesEstandar = (bool) preg_match(self::REGEX_CUIP_ESTANDAR, $cuipRaw)
            || (bool) preg_match(self::REGEX_CUIP_ESTANDAR_AMPLIO, $cuipRaw);

        if ($matchesEstandar) {
            $cuipUpper = strtoupper($cuipRaw);
            $esValido = $esExtensionAdmitida && $categoria !== null;
            $motivo = null;
            $codigo = null;

            if (! $esExtensionAdmitida) {
                $motivo = "Extensión '{$extension}' no admitida para archivos biométricos";
                $codigo = 'EXTENSION_NO_ADMITIDA';
            } elseif ($categoria === null) {
                $motivo = 'No fue posible determinar la categoría biométrica institucional del archivo';
                $codigo = 'CATEGORIA_DESCONOCIDA';
            }

            return new BiometricParseResult(
                nombreArchivo: $basename,
                rutaRelativa: $normalizedPath,
                extension: $extension,
                cuipDetectado: $cuipUpper,
                cuipNormalizado: $cuipUpper,
                categoria: $categoria,
                tipoIdentificacion: BiometricoTipoIdentificacion::ESTANDAR,
                esValido: $esValido,
                esCuipValido: true,
                esExtensionAdmitida: $esExtensionAdmitida,
                motivoInvalidez: $motivo,
                codigoIncidencia: $codigo
            );
        }

        // Caso D: Patrón No Estándar (caracteres anómalos, sufijos, espacios o longitud fuera de rango)
        return new BiometricParseResult(
            nombreArchivo: $basename,
            rutaRelativa: $normalizedPath,
            extension: $extension,
            cuipDetectado: $cuipRaw !== '' ? $cuipRaw : null,
            cuipNormalizado: null,
            categoria: $categoria,
            tipoIdentificacion: BiometricoTipoIdentificacion::NO_ESTANDAR,
            esValido: false,
            esCuipValido: false,
            esExtensionAdmitida: $esExtensionAdmitida,
            motivoInvalidez: 'Identificador no estándar con longitud o caracteres fuera del patrón nacional CUIP',
            codigoIncidencia: 'PATRON_CUIP_NO_ESTANDAR'
        );
    }

    /**
     * Infiere la categoría biométrica a partir de las carpetas canónicas en la ruta relativa.
     */
    protected function inferirCategoriaDesdeRuta(string $path): ?BiometricoCategoria
    {
        $normalized = strtoupper(str_replace('\\', '/', $path));
        $segments = explode('/', $normalized);

        // Se descarta el último segmento (nombre del archivo)
        array_pop($segments);

        foreach ($segments as $segment) {
            $clean = trim($segment);

            if ($clean === 'FOTOS' || $clean === 'FOTO') {
                return BiometricoCategoria::FOTO;
            }

            if ($clean === 'FIRMAS' || $clean === 'FIRMA') {
                return BiometricoCategoria::FIRMA;
            }

            if ($clean === 'PULGAR DERECHO' || $clean === 'PULGAR_DERECHO' || $clean === 'PULGARDERECHO') {
                return BiometricoCategoria::PULGAR_DERECHO;
            }

            if ($clean === 'PULGAR IZQUIERDO' || $clean === 'PULGAR_IZQUIERDO' || $clean === 'PULGARIZQUIERDO') {
                return BiometricoCategoria::PULGAR_IZQUIERDO;
            }
        }

        return null;
    }

    /**
     * Infiere la categoría si el nombre del archivo contiene un sufijo explícito.
     */
    protected function inferirCategoriaDesdeNombre(string $filenameWithoutExt): ?BiometricoCategoria
    {
        $upper = strtoupper(trim($filenameWithoutExt));

        if (str_ends_with($upper, '_FOTO') || str_ends_with($upper, '-FOTO')) {
            return BiometricoCategoria::FOTO;
        }

        if (str_ends_with($upper, '_FIRMA') || str_ends_with($upper, '-FIRMA')) {
            return BiometricoCategoria::FIRMA;
        }

        if (str_ends_with($upper, '_PULGAR_DER') || str_ends_with($upper, '_PD') || str_ends_with($upper, '-PD')) {
            return BiometricoCategoria::PULGAR_DERECHO;
        }

        if (str_ends_with($upper, '_PULGAR_IZQ') || str_ends_with($upper, '_PI') || str_ends_with($upper, '-PI')) {
            return BiometricoCategoria::PULGAR_IZQUIERDO;
        }

        return null;
    }
}

