<?php

declare(strict_types=1);

namespace App\Support\Storage;

use App\Exceptions\BiometricoReadOnlyException;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\VisibilityConverter;
use League\MimeTypeDetection\MimeTypeDetector;

class SafeReadOnlyLocalAdapter extends LocalFilesystemAdapter
{
    protected string $canonicalRoot;

    public function __construct(
        string $location,
        ?VisibilityConverter $visibility = null,
        int $writeFlags = 2,
        int $linkHandling = self::DISALLOW_LINKS,
        ?MimeTypeDetector $mimeTypeDetector = null,
        bool $lazyRootCreation = false,
        bool $useInconclusiveMimeTypeFallback = false
    ) {
        $this->validarRutaRaiz($location);

        $resolved = realpath($location);
        if ($resolved === false) {
            throw new \InvalidArgumentException("No fue posible resolver la ruta canónica del repositorio biométrico: {$location}");
        }

        $canonicalInput = rtrim(str_replace('\\', '/', $location), '/');
        $canonicalResolved = rtrim(str_replace('\\', '/', $resolved), '/');

        if (strcasecmp($canonicalInput, $canonicalResolved) !== 0) {
            throw new \InvalidArgumentException(
                "La ruta raíz no coincide con su ruta canónica resuelta (posible redirección, junction, enlace o nombre corto 8.3): '{$location}' resuelta como '{$resolved}'"
            );
        }

        $this->canonicalRoot = $canonicalResolved;

        // Forzar siempre DISALLOW_LINKS y lazyRootCreation = true para neutralizar
        // enlaces simbólicos y evitar cualquier intento de mkdir() sobre la raíz
        parent::__construct(
            $this->canonicalRoot,
            $visibility,
            $writeFlags,
            self::DISALLOW_LINKS,
            $mimeTypeDetector,
            true, // lazyRootCreation siempre activo en adaptador de solo lectura
            $useInconclusiveMimeTypeFallback
        );
    }

    /**
     * Valida de manera exhaustiva y fail-closed que la ruta raíz sea válida, absoluta, existente y segura.
     *
     * @throws \InvalidArgumentException
     */
    protected function validarRutaRaiz(string $location): void
    {
        // 1. Ruta vacía o compuesta únicamente por espacios
        if (trim($location) === '') {
            throw new \InvalidArgumentException('La ruta raíz del repositorio biométrico no puede estar vacía.');
        }

        // 2. Detección de bytes nulos
        if (str_contains($location, "\0")) {
            throw new \InvalidArgumentException('La ruta raíz contiene caracteres nulos no permitidos.');
        }

        // 3. Segmentos de navegación relativa (. o ..)
        $normalizedSlashes = str_replace('\\', '/', $location);
        $segments = explode('/', $normalizedSlashes);
        if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
            throw new \InvalidArgumentException("La ruta raíz no puede contener segmentos relativos ('.' o '..'): {$location}");
        }

        // 4. Verificación de ruta absoluta (Windows con letra de unidad, UNC o estilo Unix)
        $isWindowsAbsolute = (bool) preg_match('/^[a-zA-Z]:[\\\\\/]/', $location);
        $isUnc = (bool) preg_match('/^(\\\\\\\\|[\/]{2})[^\\\\\/]+[\\\\\/][^\\\\\/]+/', $location);
        $isPosixAbsolute = str_starts_with($location, '/');

        if (! $isWindowsAbsolute && ! $isUnc && ! $isPosixAbsolute) {
            throw new \InvalidArgumentException("La ruta raíz del repositorio biométrico debe ser una ruta absoluta: {$location}");
        }

        // 5. Existencia física y tipo directorio
        if (! file_exists($location)) {
            throw new \InvalidArgumentException("La ruta raíz del repositorio biométrico no existe físicamente: {$location}");
        }

        if (! is_dir($location)) {
            throw new \InvalidArgumentException("La ruta raíz del repositorio biométrico no es un directorio: {$location}");
        }

        // 6. Detección de enlaces simbólicos, junctions y reparse points
        if (is_link($location)) {
            throw new \InvalidArgumentException("La ruta raíz no puede ser un enlace simbólico o junction: {$location}");
        }

        $target = @readlink($location);
        if ($target !== false) {
            $normalizedTarget = rtrim(str_replace('\\', '/', $target), '/');
            $normalizedLocation = rtrim(str_replace('\\', '/', $location), '/');

            $matches = PHP_OS_FAMILY === 'Windows'
                ? (strcasecmp($normalizedTarget, $normalizedLocation) === 0)
                : ($normalizedTarget === $normalizedLocation);

            if (! $matches) {
                throw new \InvalidArgumentException("La ruta raíz es un enlace o punto de reanálisis hacia otra ubicación: {$location}");
            }
        }
    }

    public function getCanonicalRoot(): string
    {
        return $this->canonicalRoot;
    }

    /**
     * Valida de manera exhaustiva que una ruta relativa no intente escapar de la raíz
     * mediante Path Traversal, bytes nulos, rutas absolutas, separadores anómalos o enlaces.
     */
    public function assertSafePath(string $path): string
    {
        // 1. Detección inmediata de bytes nulos
        if (str_contains($path, "\0")) {
            throw BiometricoReadOnlyException::pathTraversalAttempt($path, 'Byte nulo detectado');
        }

        // 2. Normalización de separadores a '/'
        $normalized = str_replace('\\', '/', trim($path));

        // Permitir ruta vacía o punto único únicamente para escaneo de raíz
        if ($normalized === '' || $normalized === '.') {
            return '';
        }

        // 3. Rechazo de esquemas o prefijos URI (php://, file://, etc.)
        if (preg_match('#^[a-zA-Z0-9]+://#', $normalized)) {
            throw BiometricoReadOnlyException::pathTraversalAttempt($path, 'Esquema o flujo no permitido');
        }

        // 4. Rechazo de rutas absolutas de Windows (C:, D:, etc.) y estilo Unix (/...)
        if (preg_match('#^[a-zA-Z]:#', $normalized) || str_starts_with($normalized, '/')) {
            throw BiometricoReadOnlyException::pathTraversalAttempt($path, 'Ruta absoluta no permitida');
        }

        // 5. Rechazo de segmentos de retroceso '..'
        $segments = explode('/', $normalized);
        foreach ($segments as $segment) {
            if ($segment === '..') {
                throw BiometricoReadOnlyException::pathTraversalAttempt($path, 'Segmento de retroceso ".." detectado');
            }
        }

        // 6. Validación de resolución canónica
        $targetPhysical = $this->canonicalRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($normalized, '/'));
        if (file_exists($targetPhysical)) {
            $realTarget = realpath($targetPhysical);
            if ($realTarget === false || ! $this->isWithinRoot($realTarget)) {
                throw BiometricoReadOnlyException::pathTraversalAttempt($path, 'Escape de raíz detectado mediante realpath');
            }
        } else {
            // Si el archivo no existe, verificar que el directorio antecesor existente resida dentro de la raíz
            $cursor = dirname($targetPhysical);
            while (! file_exists($cursor) && strlen($cursor) >= strlen($this->canonicalRoot)) {
                $parent = dirname($cursor);
                if ($parent === $cursor) {
                    break;
                }
                $cursor = $parent;
            }

            if (file_exists($cursor)) {
                $realAncestor = realpath($cursor);
                if ($realAncestor === false || ! $this->isWithinRoot($realAncestor)) {
                    throw BiometricoReadOnlyException::pathTraversalAttempt($path, 'Directorio antecesor fuera de la raíz autorizada');
                }
            }
        }

        return $normalized;
    }

    /**
     * Verifica si una ruta absoluta física se encuentra estrictamente dentro de la raíz autorizada.
     */
    protected function isWithinRoot(string $target): bool
    {
        $normTarget = strtolower(rtrim(str_replace('\\', '/', $target), '/'));
        $normRoot = strtolower(rtrim(str_replace('\\', '/', $this->canonicalRoot), '/'));

        return $normTarget === $normRoot || str_starts_with($normTarget, $normRoot . '/');
    }

    // =========================================================================
    // OPERACIONES MUTADORAS — BLOQUEADAS INCONDICIONALMENTE
    // =========================================================================

    public function write(string $path, string $contents, Config $config): void
    {
        throw BiometricoReadOnlyException::writeAttempt('write', $path);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        throw BiometricoReadOnlyException::writeAttempt('writeStream', $path);
    }

    public function delete(string $path): void
    {
        throw BiometricoReadOnlyException::writeAttempt('delete', $path);
    }

    public function deleteDirectory(string $prefix): void
    {
        throw BiometricoReadOnlyException::writeAttempt('deleteDirectory', $prefix);
    }

    public function createDirectory(string $path, Config $config): void
    {
        throw BiometricoReadOnlyException::writeAttempt('createDirectory', $path);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw BiometricoReadOnlyException::writeAttempt('setVisibility', $path);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        throw BiometricoReadOnlyException::writeAttempt('move', "{$source} -> {$destination}");
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        throw BiometricoReadOnlyException::writeAttempt('copy', "{$source} -> {$destination}");
    }

    // =========================================================================
    // OPERACIONES DE LECTURA Y CONSULTA — PERMITIDAS BAJO VALIDACIÓN DE RUTA
    // =========================================================================

    public function read(string $path): string
    {
        $safePath = $this->assertSafePath($path);
        return parent::read($safePath);
    }

    public function readStream(string $path)
    {
        $safePath = $this->assertSafePath($path);
        return parent::readStream($safePath);
    }

    public function fileExists(string $location): bool
    {
        $safePath = $this->assertSafePath($location);
        return parent::fileExists($safePath);
    }

    public function directoryExists(string $location): bool
    {
        $safePath = $this->assertSafePath($location);
        return parent::directoryExists($safePath);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $safePath = $this->assertSafePath($path);
        return parent::listContents($safePath, $deep);
    }

    public function mimeType(string $path): FileAttributes
    {
        $safePath = $this->assertSafePath($path);
        return parent::mimeType($safePath);
    }

    public function fileSize(string $path): FileAttributes
    {
        $safePath = $this->assertSafePath($path);
        return parent::fileSize($safePath);
    }

    public function lastModified(string $path): FileAttributes
    {
        $safePath = $this->assertSafePath($path);
        return parent::lastModified($safePath);
    }

    public function visibility(string $path): FileAttributes
    {
        $safePath = $this->assertSafePath($path);
        return parent::visibility($safePath);
    }

    public function checksum(string $path, Config $config): string
    {
        $safePath = $this->assertSafePath($path);
        return parent::checksum($safePath, $config);
    }
}
