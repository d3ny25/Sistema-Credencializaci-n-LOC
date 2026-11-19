<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\EvidenciaBiometricaException;
use App\Models\BiometricoEvidencia;
use App\Models\CredencialPreparacion;
use Illuminate\Support\Facades\Storage;

/**
 * Almacén privado de evidencia biométrica direccionado por SHA-256 (disco `biometricos_evidencia`).
 *
 * Publicación validada en la Fase 1B.1 (NTFS): temporal en la misma raíz → copia → fsync →
 * releer y calcular SHA-256 → validar formato → link() (nunca rename(), que reemplaza el destino en
 * Windows) → borrar el temporal → chmod 0444 (en ese orden: el atributo se comparte entre enlaces).
 * El sistema de archivos no participa en la transacción SQL: un archivo publicado sin fila es un
 * huérfano inocuo (contenido válido reutilizable) que la conciliación informa y nunca borra.
 */
class BiometricEvidenceStore
{
    public const DISCO = 'biometricos_evidencia';

    public const TAMANO_MAXIMO = 10 * 1024 * 1024;

    /** @var array<int, array{0: string, 1: string}> tipo de imagen => [extensión, MIME] */
    private const FORMATOS = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_BMP => ['bmp', 'image/bmp'],
    ];

    /**
     * Copia un contenido verificado al almacén sin sobrescribir nunca un archivo publicado.
     *
     * @param  resource  $origen  Flujo de lectura del archivo de origen.
     * @param  string  $etiqueta  Nombre del temporal sin datos personales: "<uuid-preparación>-<categoría>".
     * @return array{sha256: string, ruta_relativa: string, mime_real: string, formato: string, tamano_bytes: int, ancho_px: int|null, alto_px: int|null, duplicado: bool}
     */
    public function publicar($origen, string $shaEsperado, ?int $tamanoEsperado, string $etiqueta): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,80}$/', $etiqueta) !== 1 || preg_match('/^[0-9a-f]{64}$/', $shaEsperado) !== 1) {
            throw new \InvalidArgumentException('Etiqueta o hash de evidencia inválidos.');
        }

        $raiz = $this->raiz();
        $temporal = $this->asegurarDirectorio($raiz.'/_tmp').'/'.$etiqueta.'.part';
        $handle = @fopen($temporal, 'xb');
        if ($handle === false) {
            throw new EvidenciaBiometricaException('EVIDENCIA_TEMPORAL_NO_CREADO', 'No fue posible crear el archivo temporal de evidencia.', 'alta', ['etiqueta' => $etiqueta]);
        }

        $publicado = false;
        try {
            try {
                $bytes = stream_copy_to_stream($origen, $handle);
                // fsync: PHP 8.1+ en Windows usa _commit (FlushFileBuffers). Si falla, la copia no se publica.
                if ($bytes === false || ! fflush($handle) || ! fsync($handle)) {
                    throw new EvidenciaBiometricaException('EVIDENCIA_ESCRITURA_FALLIDA', 'No fue posible escribir la copia de evidencia.', 'alta', ['etiqueta' => $etiqueta]);
                }
            } finally {
                fclose($handle);
            }

            // Hash sobre los bytes ya escritos en disco, no sobre el flujo leído.
            $sha = hash_file('sha256', $temporal);
            $tamano = filesize($temporal);
            if ($sha !== $shaEsperado || ($tamanoEsperado !== null && $tamano !== $tamanoEsperado)) {
                throw new EvidenciaBiometricaException('EVIDENCIA_ORIGEN_DISCREPANTE', 'El archivo de origen no coincide con el hash registrado.', 'critica', ['sha256_esperado' => $shaEsperado, 'sha256_copia' => $sha, 'tamano_copia' => $tamano]);
            }

            [$formato, $mime, $ancho, $alto] = $this->validarImagen($temporal, (int) $tamano);
            $relativa = sprintf('sha256/%s/%s/%s.%s', substr($sha, 0, 2), substr($sha, 2, 2), $sha, $formato);
            $destino = $this->asegurarDirectorio(dirname($raiz.'/'.$relativa)).'/'.basename($relativa);

            $duplicado = false;
            if (! @link($temporal, $destino)) {
                if (! is_file($destino)) {
                    throw new EvidenciaBiometricaException('EVIDENCIA_PUBLICACION_FALLIDA', 'No fue posible publicar la evidencia.', 'alta', ['sha256' => $sha]);
                }
                if (hash_file('sha256', $destino) !== $sha) {
                    // No se sobrescribe la copia existente: la nueva queda en cuarentena para revisión.
                    $cuarentena = $this->asegurarDirectorio($raiz.'/_cuarentena').'/'.$sha.'-'.$etiqueta.'.bin';
                    @link($temporal, $cuarentena);
                    throw new EvidenciaBiometricaException('EVIDENCIA_EXISTENTE_ALTERADA', 'La evidencia publicada no coincide con su hash; se envió a cuarentena.', 'critica', ['sha256' => $sha, 'ruta_relativa' => $relativa]);
                }
                $duplicado = true;
            }
            $publicado = true;
        } finally {
            if (is_file($temporal)) {
                @unlink($temporal);
            }
        }

        if ($publicado && ! $duplicado) {
            @chmod($destino, 0444);
        }

        return [
            'sha256' => $sha,
            'ruta_relativa' => $relativa,
            'mime_real' => $mime,
            'formato' => $formato,
            'tamano_bytes' => (int) $tamano,
            'ancho_px' => $ancho,
            'alto_px' => $alto,
            'duplicado' => $duplicado,
        ];
    }

    /**
     * Registra (o recupera) la fila del catálogo para un contenido ya publicado. Llamar dentro de la transacción.
     *
     * @param  array{sha256: string, ruta_relativa: string, mime_real: string, formato: string, tamano_bytes: int, ancho_px: int|null, alto_px: int|null}  $publicacion
     */
    public function registrar(array $publicacion): BiometricoEvidencia
    {
        BiometricoEvidencia::query()->insertOrIgnore([
            'sha256' => $publicacion['sha256'],
            'ruta_relativa' => $publicacion['ruta_relativa'],
            'mime_real' => $publicacion['mime_real'],
            'formato' => $publicacion['formato'],
            'tamano_bytes' => $publicacion['tamano_bytes'],
            'ancho_px' => $publicacion['ancho_px'],
            'alto_px' => $publicacion['alto_px'],
            'created_at' => now(),
        ]);

        return BiometricoEvidencia::query()->where('sha256', $publicacion['sha256'])->firstOrFail();
    }

    /**
     * Lee una evidencia completa y verifica que su contenido siga correspondiendo a su hash.
     */
    public function leerVerificado(BiometricoEvidencia $evidencia): string
    {
        $ruta = $this->raiz().'/'.$evidencia->ruta_relativa;
        if (! is_file($ruta)) {
            throw new EvidenciaBiometricaException('EVIDENCIA_FALTANTE', 'El archivo de evidencia no existe en el almacén.', 'critica', ['evidencia_id' => $evidencia->id, 'sha256' => $evidencia->sha256]);
        }
        $bytes = (string) file_get_contents($ruta);
        if (hash('sha256', $bytes) !== $evidencia->sha256) {
            throw new EvidenciaBiometricaException('EVIDENCIA_HASH_DISCREPANTE', 'El archivo de evidencia no coincide con su hash registrado.', 'critica', ['evidencia_id' => $evidencia->id, 'sha256' => $evidencia->sha256]);
        }

        return $bytes;
    }

    /**
     * Conciliación (por defecto solo informe). Únicamente borra temporales .part con más de $horas
     * que no pertenecen a una preparación vigente; los archivos publicados nunca se borran.
     *
     * Modo normal: existencia, tamaño y huérfanos (sin leer contenido). Es una verificación estructural,
     * no criptográfica. Modo $integridad: además recalcula el SHA-256 de cada archivo y lo compara.
     * Registros y archivos se recorren por lotes, sin cargar el almacén completo en memoria.
     *
     * @return array{temporales_abandonados: list<string>, temporales_eliminados: int, publicados_sin_registro: list<string>, registros_sin_archivo: list<int>, registros_tamano_discrepante: list<int>, registros_ilegibles: list<int>, registros_con_hash_discrepante: list<int>, integridad_verificada: bool}
     */
    public function conciliar(bool $aplicar = false, int $horas = 24, bool $integridad = false): array
    {
        $raiz = $this->raiz();
        $limite = time() - ($horas * 3600);
        $abandonados = [];
        $eliminados = 0;

        foreach (glob($raiz.'/_tmp/*.part') ?: [] as $temporal) {
            if (filemtime($temporal) > $limite) {
                continue; // puede pertenecer a una operación en curso
            }
            $uuid = substr(basename($temporal, '.part'), 0, 36);
            $vigente = CredencialPreparacion::query()->where('uuid', $uuid)->where('estado', CredencialPreparacion::PREPARADA)->exists();
            if ($vigente) {
                continue;
            }
            $abandonados[] = basename($temporal);
            if ($aplicar && @unlink($temporal)) {
                $eliminados++;
            }
        }

        // Huérfanos: archivos publicados sin fila, consultados por lotes. Las rutas solo contienen hashes.
        $sinRegistro = [];
        $lote = [];
        $revisarLote = static function (array $rutas) use (&$sinRegistro): void {
            $existentes = BiometricoEvidencia::query()->whereIn('ruta_relativa', $rutas)->pluck('ruta_relativa')->all();
            array_push($sinRegistro, ...array_values(array_diff($rutas, $existentes)));
        };
        if (is_dir($raiz.'/sha256')) {
            $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz.'/sha256', \FilesystemIterator::SKIP_DOTS));
            foreach ($iterador as $archivo) {
                if ($archivo->isFile()) {
                    $lote[] = substr(str_replace('\\', '/', $archivo->getPathname()), strlen($raiz) + 1);
                    if (count($lote) >= 500) {
                        $revisarLote($lote);
                        $lote = [];
                    }
                }
            }
        }
        if ($lote !== []) {
            $revisarLote($lote);
        }
        sort($sinRegistro, SORT_STRING);

        $sinArchivo = [];
        $tamanoDiscrepante = [];
        $ilegibles = [];
        $discrepantes = [];
        foreach (BiometricoEvidencia::query()->lazyById(500) as $evidencia) {
            $ruta = $raiz.'/'.$evidencia->ruta_relativa;
            clearstatcache(true, $ruta);
            if (! is_file($ruta)) {
                $sinArchivo[] = $evidencia->id;
                continue;
            }
            $tamano = @filesize($ruta);
            if ($tamano === false) {
                $ilegibles[] = $evidencia->id;
                continue;
            }
            if ($tamano !== $evidencia->tamano_bytes) {
                $tamanoDiscrepante[] = $evidencia->id;
                continue;
            }
            if ($integridad) {
                $hash = @hash_file('sha256', $ruta);
                if ($hash === false) {
                    $ilegibles[] = $evidencia->id;
                } elseif (! hash_equals($evidencia->sha256, $hash)) {
                    $discrepantes[] = $evidencia->id;
                }
            }
        }

        return [
            'temporales_abandonados' => $abandonados,
            'temporales_eliminados' => $eliminados,
            'publicados_sin_registro' => $sinRegistro,
            'registros_sin_archivo' => $sinArchivo,
            'registros_tamano_discrepante' => $tamanoDiscrepante,
            'registros_ilegibles' => $ilegibles,
            'registros_con_hash_discrepante' => $discrepantes,
            'integridad_verificada' => $integridad,
        ];
    }

    public function raiz(): string
    {
        if (config('filesystems.disks.'.self::DISCO.'.driver') !== 'local') {
            throw new \LogicException('El almacén de evidencia requiere un disco local (link() dentro del mismo volumen).');
        }

        return rtrim(str_replace('\\', '/', Storage::disk(self::DISCO)->path('')), '/');
    }

    /**
     * @return array{0: string, 1: string, 2: int|null, 3: int|null}
     */
    private function validarImagen(string $ruta, int $tamano): array
    {
        if ($tamano <= 0 || $tamano > self::TAMANO_MAXIMO) {
            throw new EvidenciaBiometricaException('EVIDENCIA_TAMANO_INVALIDO', 'El tamaño del archivo biométrico no es válido para evidencia.', 'alta', ['tamano' => $tamano]);
        }
        $info = @getimagesize($ruta);
        if ($info === false || ! isset(self::FORMATOS[$info[2]])) {
            throw new EvidenciaBiometricaException('EVIDENCIA_FORMATO_INVALIDO', 'El archivo biométrico no es una imagen JPEG, PNG o BMP válida.', 'alta');
        }
        [$formato, $mime] = self::FORMATOS[$info[2]];
        if (function_exists('finfo_open')) {
            $real = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($ruta);
            if (! in_array($real, [$mime, 'image/x-ms-bmp'], true)) {
                throw new EvidenciaBiometricaException('EVIDENCIA_MIME_DISCREPANTE', 'El tipo MIME real no coincide con el formato de la imagen.', 'alta', ['mime_real' => $real]);
            }
        }

        return [$formato, $mime, (int) $info[0] ?: null, (int) $info[1] ?: null];
    }

    private function asegurarDirectorio(string $directorio): string
    {
        if (! is_dir($directorio) && ! @mkdir($directorio, 0775, true) && ! is_dir($directorio)) {
            throw new EvidenciaBiometricaException('EVIDENCIA_DIRECTORIO_NO_CREADO', 'No fue posible preparar el directorio de evidencia.', 'alta');
        }

        return $directorio;
    }
}
