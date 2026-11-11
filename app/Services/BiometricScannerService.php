<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\BiometricScanEntry;
use Generator;
use InvalidArgumentException;

/**
 * Servicio de exploración incremental recursiva de directorios biométricos.
 *
 * Responsable de recorrer de forma controlada y segura un repositorio biométrico autorizado,
 * validando que ninguna ruta escape de la raíz canónica, detectando enlaces simbólicos,
 * previniendo bucles infinitos en junctions de Windows mediante registro de rutas visitadas,
 * y produciendo instancias de BiometricScanEntry ordenadas deterministamente mediante generadores.
 */
class BiometricScannerService
{
    /**
     * Nombres conocidos de archivos de sistema operativo a identificar explícitamente.
     */
    public const ARCHIVOS_SISTEMA = ['desktop.ini', 'thumbs.db', '.ds_store'];

    /**
     * Extensiones accesorias o de sistema que no constituyen archivos de imagen biométrica.
     */
    public const EXTENSIONES_SISTEMA = ['ini', 'db', 'tmp', 'bak', 'txt'];

    /**
     * Indicador de si ocurrieron anomalías, accesos denegados o errores de recorrido durante la última exploración.
     */
    protected bool $huboErroresDeRecorrido = false;

    /**
     * Retorna si la última exploración tuvo directorios inaccesibles, enlaces rechazados o fallos de lectura.
     */
    public function huboErroresDeRecorrido(): bool
    {
        return $this->huboErroresDeRecorrido;
    }

    /**
     * Comparador canónico uniforme para rutas relativas.
     * Combina ordenamiento natural insensible a mayúsculas con desempate determinista exacto.
     * Utilizado tanto en el ordenamiento lexicográfico del árbol como en el filtrado de checkpoints.
     */
    public static function compararRutas(string $rutaA, string $rutaB): int
    {
        $normA = str_replace('\\', '/', trim($rutaA));
        $normB = str_replace('\\', '/', trim($rutaB));

        // 1. Comparación natural insensible a mayúsculas
        $cmp = strnatcasecmp($normA, $normB);
        if ($cmp !== 0) {
            return $cmp;
        }

        // 2. Desempate determinista estricto en caso de que difieran únicamente en casing
        return strcmp($normA, $normB);
    }

    /**
     * Valida exhaustivamente y de forma fail-closed que la ruta raíz sea válida, absoluta,
     * existente en disco, sea un directorio regular y coincida con su ruta canónica resuelta.
     *
     * @param  string  $location Ruta raíz propuesta para el escaneo
     * @return string Ruta canónica normalizada con barras diagonales ('/')
     *
     * @throws InvalidArgumentException Si la ruta no cumple estrictamente las reglas de seguridad
     */
    public function validarRutaRaiz(string $location): string
    {
        // 1. Ruta vacía o compuesta únicamente por espacios
        if (trim($location) === '') {
            throw new InvalidArgumentException('La ruta raíz del repositorio biométrico no puede estar vacía.');
        }

        // 2. Detección de bytes nulos
        if (str_contains($location, "\0")) {
            throw new InvalidArgumentException('La ruta raíz contiene caracteres nulos no permitidos.');
        }

        // 3. Segmentos de navegación relativa (. o ..)
        $normalizedSlashes = str_replace('\\', '/', $location);
        $segments = explode('/', $normalizedSlashes);
        if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
            throw new InvalidArgumentException("La ruta raíz no puede contener segmentos relativos ('.' o '..'): {$location}");
        }

        // 4. Verificación de ruta absoluta (Windows con letra de unidad, UNC o estilo Unix)
        $isWindowsAbsolute = (bool) preg_match('/^[a-zA-Z]:[\\\\\/]/', $location);
        $isUnc = (bool) preg_match('/^(\\\\\\\\|[\/]{2})[^\\\\\/]+[\\\\\/][^\\\\\/]+/', $location);
        $isPosixAbsolute = str_starts_with($location, '/');

        if (! $isWindowsAbsolute && ! $isUnc && ! $isPosixAbsolute) {
            throw new InvalidArgumentException("La ruta raíz del repositorio biométrico debe ser una ruta absoluta: {$location}");
        }

        // 5. Existencia física y tipo directorio
        if (! file_exists($location)) {
            throw new InvalidArgumentException("La ruta raíz especificada no existe en el sistema de archivos: {$location}");
        }

        if (! is_dir($location)) {
            throw new InvalidArgumentException("La ruta raíz especificada no es un directorio: {$location}");
        }

        // 6. Rechazo explícito de enlaces simbólicos o reparse points en la raíz
        if (is_link($location)) {
            throw new InvalidArgumentException("La ruta raíz no puede ser un enlace simbólico o junction: {$location}");
        }

        // 7. Resolución canónica
        $resolved = realpath($location);
        if ($resolved === false) {
            throw new InvalidArgumentException("No fue posible resolver la ruta canónica del repositorio biométrico: {$location}");
        }

        $canonicalInput = rtrim(str_replace('\\', '/', $location), '/');
        $canonicalResolved = rtrim(str_replace('\\', '/', $resolved), '/');

        if (strcasecmp($canonicalInput, $canonicalResolved) !== 0) {
            throw new InvalidArgumentException(
                "La ruta raíz no coincide con su ruta canónica resuelta (posible redirección, junction, enlace o nombre corto 8.3): '{$location}' resuelta como '{$resolved}'"
            );
        }

        return $canonicalResolved;
    }

    /**
     * Explora de manera recursiva e incremental el directorio raíz autorizado, produciendo
     * entradas estructuradas de archivo (BiometricScanEntry) mediante un Generator de PHP.
     *
     * @param  string  $rootPath Ruta raíz del repositorio biométrico a explorar
     * @param  string|null  $startAfterRelativePath Ruta relativa a partir de la cual reanudar la exploración
     * @return Generator<int, BiometricScanEntry>
     *
     * @throws InvalidArgumentException Si la ruta raíz es inválida
     */
    public function scan(string $rootPath, ?string $startAfterRelativePath = null): Generator
    {
        $this->huboErroresDeRecorrido = false;
        $canonicalRoot = $this->validarRutaRaiz($rootPath);
        $startAfterNormalized = $startAfterRelativePath !== null
            ? str_replace('\\', '/', trim($startAfterRelativePath))
            : null;

        // Registro de rutas canónicas de directorios visitados para prevención de bucles infinitos en junctions (AUD-243-004)
        $visitedDirs = [strtoupper($canonicalRoot) => true];

        // Pila para recorrido recursivo seguro
        $dirStack = [$canonicalRoot];
        $rootPrefixUpper = strtoupper($canonicalRoot) . '/';

        /** @var array<int, BiometricScanEntry> $discoveredEntries */
        $discoveredEntries = [];

        while (! empty($dirStack)) {
            $currentDir = array_shift($dirStack);

            // Intentar abrir el directorio actual
            try {
                $dirIterator = new \DirectoryIterator($currentDir);
            } catch (\Throwable $e) {
                // Directorio inaccesible (permisos insuficientes o I/O dañado) -> AUD-243-002
                $this->huboErroresDeRecorrido = true;
                $relDir = ltrim(substr(str_replace('\\', '/', $currentDir), strlen($canonicalRoot)), '/');
                $discoveredEntries[] = new BiometricScanEntry(
                    rutaRelativa: $relDir ?: '.',
                    rutaAbsoluta: $currentDir,
                    nombreArchivo: basename($currentDir),
                    extension: '',
                    tamanoBytes: 0,
                    mtime: null,
                    esLegible: false,
                    esEnlace: is_link($currentDir),
                    esDirectorio: true,
                    esArchivoRegular: false,
                    esSistema: false,
                    esOculto: str_starts_with(basename($currentDir), '.'),
                    esValido: false,
                    tipoEntrada: 'directorio_inaccesible',
                    motivoRechazo: 'No fue posible abrir el directorio para lectura: ' . $e->getMessage(),
                    codigoError: 'DIR_INACCESIBLE'
                );

                continue;
            }

            // Recolectar entradas del directorio actual
            $subDirs = [];
            $files = [];

            foreach ($dirIterator as $item) {
                if ($item->isDot()) {
                    continue;
                }

                $itemName = $item->getFilename();

                if ($item->isDir()) {
                    $subDirs[$itemName] = clone $item;
                } else {
                    $files[$itemName] = clone $item;
                }
            }

            // Ordenamiento uniforme canónico utilizando el comparador determinista
            uksort($subDirs, [self::class, 'compararRutas']);
            uksort($files, [self::class, 'compararRutas']);

            // Procesar subdirectorios: encolar directorios seguros y reportar anomalías
            $safeSubDirsToPush = [];
            foreach ($subDirs as $name => $subDirItem) {
                $subDirPathname = $subDirItem->getPathname();

                // Rechazo estricto de enlaces simbólicos o junctions de directorio
                if ($subDirItem->isLink() || is_link($subDirPathname)) {
                    $this->huboErroresDeRecorrido = true;
                    $relDir = ltrim(substr(str_replace('\\', '/', $subDirPathname), strlen($canonicalRoot)), '/');
                    $discoveredEntries[] = new BiometricScanEntry(
                        rutaRelativa: $relDir,
                        rutaAbsoluta: $subDirPathname,
                        nombreArchivo: $name,
                        extension: '',
                        tamanoBytes: 0,
                        mtime: null,
                        esLegible: false,
                        esEnlace: true,
                        esDirectorio: true,
                        esArchivoRegular: false,
                        esSistema: false,
                        esOculto: str_starts_with($name, '.'),
                        esValido: false,
                        tipoEntrada: 'enlace_no_permitido',
                        motivoRechazo: 'Enlace simbólico o junction de directorio no permitido',
                        codigoError: 'SYMLINK_DIRECTORIO'
                    );

                    continue;
                }

                // Verificación de contención estricta dentro de la raíz autorizada
                $resolvedSubDir = realpath($subDirPathname);
                if ($resolvedSubDir === false) {
                    $this->huboErroresDeRecorrido = true;
                    $relDir = ltrim(substr(str_replace('\\', '/', $subDirPathname), strlen($canonicalRoot)), '/');
                    $discoveredEntries[] = new BiometricScanEntry(
                        rutaRelativa: $relDir,
                        rutaAbsoluta: $subDirPathname,
                        nombreArchivo: $name,
                        extension: '',
                        tamanoBytes: 0,
                        mtime: null,
                        esLegible: false,
                        esEnlace: false,
                        esDirectorio: true,
                        esArchivoRegular: false,
                        esSistema: false,
                        esOculto: str_starts_with($name, '.'),
                        esValido: false,
                        tipoEntrada: 'directorio_inaccesible',
                        motivoRechazo: 'No fue posible resolver la ruta canónica del subdirectorio',
                        codigoError: 'DIR_UNRESOLVED'
                    );

                    continue;
                }

                $canonicalSubDir = rtrim(str_replace('\\', '/', $resolvedSubDir), '/');
                $visitedKey = strtoupper($canonicalSubDir);

                // Detección de ciclos de directorios o junctions recursivas (AUD-243-004)
                if (isset($visitedDirs[$visitedKey])) {
                    $this->huboErroresDeRecorrido = true;
                    $relDir = ltrim(substr($canonicalSubDir, strlen($canonicalRoot)), '/');
                    $discoveredEntries[] = new BiometricScanEntry(
                        rutaRelativa: $relDir ?: '.',
                        rutaAbsoluta: $canonicalSubDir,
                        nombreArchivo: $name,
                        extension: '',
                        tamanoBytes: 0,
                        mtime: null,
                        esLegible: false,
                        esEnlace: true,
                        esDirectorio: true,
                        esArchivoRegular: false,
                        esSistema: false,
                        esOculto: str_starts_with($name, '.'),
                        esValido: false,
                        tipoEntrada: 'ciclo_detectado',
                        motivoRechazo: 'Ciclo de directorio o junction recursiva detectada',
                        codigoError: 'DIR_CYCLE_DETECTED'
                    );

                    continue;
                }

                // Validación de contención estricta (insensible a mayúsculas para Windows)
                if (! str_starts_with(strtoupper($canonicalSubDir) . '/', $rootPrefixUpper)) {
                    $this->huboErroresDeRecorrido = true;
                    $relDir = ltrim(substr($canonicalSubDir, strlen($canonicalRoot)), '/');
                    $discoveredEntries[] = new BiometricScanEntry(
                        rutaRelativa: $relDir,
                        rutaAbsoluta: $canonicalSubDir,
                        nombreArchivo: $name,
                        extension: '',
                        tamanoBytes: 0,
                        mtime: null,
                        esLegible: false,
                        esEnlace: true,
                        esDirectorio: true,
                        esArchivoRegular: false,
                        esSistema: false,
                        esOculto: str_starts_with($name, '.'),
                        esValido: false,
                        tipoEntrada: 'ruta_fuera_de_raiz',
                        motivoRechazo: 'Subdirectorio fuera de la raíz canónica autorizada',
                        codigoError: 'DIR_ESCAPED_ROOT'
                    );

                    continue;
                }

                $visitedDirs[$visitedKey] = true;
                $safeSubDirsToPush[] = $canonicalSubDir;
            }

            // Procesar archivos en el directorio actual
            foreach ($files as $name => $fileItem) {
                $filePathname = $fileItem->getPathname();
                $isLink = $fileItem->isLink() || is_link($filePathname);

                // Si es un enlace simbólico de archivo, reportarlo como no admitido
                if ($isLink) {
                    $this->huboErroresDeRecorrido = true;
                    $relPath = ltrim(substr(str_replace('\\', '/', $filePathname), strlen($canonicalRoot)), '/');
                    $discoveredEntries[] = new BiometricScanEntry(
                        rutaRelativa: $relPath,
                        rutaAbsoluta: $filePathname,
                        nombreArchivo: $name,
                        extension: strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                        tamanoBytes: 0,
                        mtime: null,
                        esLegible: false,
                        esEnlace: true,
                        esDirectorio: false,
                        esArchivoRegular: false,
                        esSistema: false,
                        esOculto: str_starts_with($name, '.'),
                        esValido: false,
                        tipoEntrada: 'enlace_no_permitido',
                        motivoRechazo: 'Enlace simbólico de archivo no permitido en repositorio biométrico',
                        codigoError: 'SYMLINK_ARCHIVO'
                    );

                    continue;
                }

                // Resolución canónica del archivo
                $resolvedFile = realpath($filePathname);
                if ($resolvedFile === false) {
                    $this->huboErroresDeRecorrido = true;
                    $relPath = ltrim(substr(str_replace('\\', '/', $filePathname), strlen($canonicalRoot)), '/');
                    $discoveredEntries[] = new BiometricScanEntry(
                        rutaRelativa: $relPath,
                        rutaAbsoluta: $filePathname,
                        nombreArchivo: $name,
                        extension: strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                        tamanoBytes: 0,
                        mtime: null,
                        esLegible: false,
                        esEnlace: false,
                        esDirectorio: false,
                        esArchivoRegular: false,
                        esSistema: false,
                        esOculto: str_starts_with($name, '.'),
                        esValido: false,
                        tipoEntrada: 'error_lectura',
                        motivoRechazo: 'No fue posible resolver la ruta canónica del archivo físico',
                        codigoError: 'FILE_UNRESOLVED'
                    );

                    continue;
                }

                $canonicalFile = str_replace('\\', '/', $resolvedFile);
                if (! str_starts_with(strtoupper($canonicalFile), $rootPrefixUpper)) {
                    $this->huboErroresDeRecorrido = true;
                    $relPath = ltrim(substr($canonicalFile, strlen($canonicalRoot)), '/');
                    $discoveredEntries[] = new BiometricScanEntry(
                        rutaRelativa: $relPath,
                        rutaAbsoluta: $canonicalFile,
                        nombreArchivo: $name,
                        extension: strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                        tamanoBytes: 0,
                        mtime: null,
                        esLegible: false,
                        esEnlace: true,
                        esDirectorio: false,
                        esArchivoRegular: false,
                        esSistema: false,
                        esOculto: str_starts_with($name, '.'),
                        esValido: false,
                        tipoEntrada: 'ruta_fuera_de_raiz',
                        motivoRechazo: 'Archivo resuelto fuera de la raíz canónica autorizada',
                        codigoError: 'FILE_ESCAPED_ROOT'
                    );

                    continue;
                }

                $relPath = ltrim(substr($canonicalFile, strlen($canonicalRoot)), '/');

                // Extracción de metadatos técnicos de archivo
                $isReadable = $fileItem->isReadable();
                if (! $isReadable) {
                    $this->huboErroresDeRecorrido = true;
                }

                $size = 0;
                $mtime = null;

                try {
                    $size = $fileItem->getSize();
                } catch (\Throwable) {
                    $size = @filesize($filePathname) ?: 0;
                }

                try {
                    $mtime = $fileItem->getMTime();
                } catch (\Throwable) {
                    $mtime = @filemtime($filePathname) ?: null;
                }

                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $isSystemName = in_array(strtolower($name), self::ARCHIVOS_SISTEMA, true);
                $isSystemExt = in_array($extension, self::EXTENSIONES_SISTEMA, true);
                $isSystem = $isSystemName || $isSystemExt;
                $isOculto = str_starts_with($name, '.');

                $discoveredEntries[] = new BiometricScanEntry(
                    rutaRelativa: $relPath,
                    rutaAbsoluta: $canonicalFile,
                    nombreArchivo: $name,
                    extension: $extension,
                    tamanoBytes: (int) $size,
                    mtime: $mtime,
                    esLegible: $isReadable,
                    esEnlace: false,
                    esDirectorio: false,
                    esArchivoRegular: $fileItem->isFile(),
                    esSistema: $isSystem,
                    esOculto: $isOculto,
                    esValido: $isReadable,
                    tipoEntrada: $isReadable ? 'archivo' : 'error_lectura',
                    motivoRechazo: $isReadable ? null : 'Archivo no legible por permisos insuficientes',
                    codigoError: $isReadable ? null : 'FILE_NO_LEGIBLE'
                );
            }

            // Agregar los subdirectorios a la pila de exploración al frente
            if (! empty($safeSubDirsToPush)) {
                array_unshift($dirStack, ...$safeSubDirsToPush);
            }
        }

        // AUD-243-003 y NUEVO-243-001: Ordenamiento canónico global determinista
        // Ordena absolutamente todas las entradas descubiertas bajo compararRutas()
        // garantizando que la emisión sea estrictamente monótona e inmune a discrepancias
        // jerárquicas entre archivos sueltos y subdirectorios.
        usort($discoveredEntries, static function (BiometricScanEntry $a, BiometricScanEntry $b): int {
            return self::compararRutas($a->rutaRelativa, $b->rutaRelativa);
        });

        // Emisión controlada con filtrado determinista de checkpoints
        foreach ($discoveredEntries as $entry) {
            if ($startAfterNormalized !== null && self::compararRutas($entry->rutaRelativa, $startAfterNormalized) <= 0) {
                continue;
            }

            yield $entry;
        }
    }
}
