<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoCategoria;
use App\Enums\BiometricoClasificacionEstado;
use App\Enums\BiometricoEstadoArchivo;
use App\Enums\BiometricoEstadoRevision;
use App\Enums\BiometricoTipoIdentificacion;
use App\Enums\EstadoEmision;
use App\Models\BiometricoArchivo;
use App\Models\BiometricoExpediente;
use App\Models\CredencialEmitida;
use App\Models\CredencialPreparacion;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Fase 1B.2 — Elementos imprimibles con cuatro biométricos sintéticos (PNG generados, sin datos reales)
 * y discos falsos para ingesta y evidencia.
 */
trait PreparaImpresionReal
{
    protected function fakeDiscosEvidencia(): void
    {
        // La evidencia publicada queda de solo lectura (Windows impide borrarla): se libera antes de que
        // Storage::fake limpie el disco de prueba de la ejecución anterior.
        $raiz = storage_path('framework/testing/disks/biometricos_evidencia');
        if (is_dir($raiz)) {
            $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterador as $archivo) {
                if ($archivo->isFile()) {
                    @chmod($archivo->getPathname(), 0666);
                }
            }
        }

        Storage::fake('biometricos');
        Storage::fake('biometricos_ingesta');
        Storage::fake('biometricos_evidencia');
    }

    /** PNG sintético válido; la semilla cambia el color y por tanto el SHA-256. */
    protected function pngSintetico(int $semilla, int $ancho = 64, int $alto = 64): string
    {
        $imagen = imagecreatetruecolor($ancho, $alto);
        imagefill($imagen, 0, 0, imagecolorallocate($imagen, $semilla % 256, (int) ($semilla / 256) % 256, 120));
        ob_start();
        imagepng($imagen);
        $bytes = (string) ob_get_clean();
        imagedestroy($imagen);

        return $bytes;
    }

    protected function elementoImprimible(int $n = 1, string $estadoSolicitud = 'enviada_impresion', string $estadoTramite = 'listo_impresion', ?string $folio = 'FOL-2026-00001', bool $conBiometria = true): SolicitudElemento
    {
        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TJ-EMISION-'.$n,
            'consecutivo_tarjeta' => $n,
            'numero_oficio' => 'OF-EMISION-'.$n,
            'fecha_recepcion' => now(),
            'estado_general' => $estadoSolicitud,
            'total_elementos' => 1,
            'total_aptos' => 1,
            'fecha_prefiltro' => now(),
        ]);

        $cuip = sprintf('CUIPEMISION%07d', $n);
        $elemento = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => $cuip,
            'resultado_prefiltro' => 'Apto',
            'estado_tramite' => $estadoTramite,
            'ambito_biometrico' => BiometricoAmbito::ESTATALES,
            'estado_clasificacion_ambito' => BiometricoClasificacionEstado::VALIDADA,
        ]);

        if ($folio !== null) {
            CredencialEmitida::create([
                'solicitud_id' => $solicitud->id,
                'solicitud_elemento_id' => $elemento->id,
                'folio' => $folio,
                'numero_tarjeta' => $solicitud->numero_tarjeta,
                'estado_emision' => EstadoEmision::PENDIENTE->value,
            ]);
        }

        if ($conBiometria) {
            $expediente = $this->expedienteConArchivos($cuip, $n * 10);
            $elemento->forceFill(['biometrico_expediente_id' => $expediente->id])->save();
        }

        return $elemento->refresh();
    }

    protected function expedienteConArchivos(string $cuip, int $semilla): BiometricoExpediente
    {
        $archivos = [];
        foreach (BiometricoCategoria::requeridas() as $i => $categoria) {
            $bytes = $this->pngSintetico($semilla + $i);
            $ruta = "INGESTA/ESTATALES/".strtoupper($categoria->value)."/{$cuip}_{$categoria->value}_{$semilla}.png";
            Storage::disk('biometricos_ingesta')->put($ruta, $bytes);
            $archivos[$categoria->value] = BiometricoArchivo::create([
                'ambito' => BiometricoAmbito::ESTATALES,
                'categoria' => $categoria,
                'ruta_relativa' => $ruta,
                'nombre_archivo' => basename($ruta),
                'extension' => 'png',
                'tamano_bytes' => strlen($bytes),
                'formato_real' => 'png',
                'mime_type' => 'image/png',
                'sha256' => hash('sha256', $bytes),
                'cuip_detectado' => $cuip,
                'cuip_normalizado' => $cuip,
                'tipo_identificacion' => BiometricoTipoIdentificacion::ESTANDAR,
                'estado_archivo' => BiometricoEstadoArchivo::VALIDO,
                'es_vigente' => true,
                'version_secuencia' => 1,
            ]);
        }

        return BiometricoExpediente::create([
            'cuip' => $cuip,
            'ambito' => BiometricoAmbito::ESTATALES,
            'foto_archivo_id' => $archivos['foto']->id,
            'firma_archivo_id' => $archivos['firma']->id,
            'pulgar_derecho_archivo_id' => $archivos['pulgar_derecho']->id,
            'pulgar_izquierdo_archivo_id' => $archivos['pulgar_izquierdo']->id,
            'total_biometricos' => 4,
            'es_completo' => true,
            'faltantes' => [],
            'estado_revision' => BiometricoEstadoRevision::APROBADO_TECNICO,
        ]);
    }

    /** Escribe PNG reales para archivos ya registrados y alinea su hash y tamaño (pruebas que crean filas sin archivo). */
    protected function materializarArchivos(BiometricoExpediente $expediente, int $semilla = 500): void
    {
        foreach (BiometricoCategoria::requeridas() as $i => $categoria) {
            $archivo = BiometricoArchivo::findOrFail($expediente->getAttribute($categoria->columnaExpediente()));
            $bytes = $this->pngSintetico($semilla + $i);
            Storage::disk('biometricos_ingesta')->put($archivo->ruta_relativa, $bytes);
            DB::table('biometrico_archivos')->where('id', $archivo->id)->update(['sha256' => hash('sha256', $bytes), 'tamano_bytes' => strlen($bytes)]);
        }
    }

    protected function prepararImpresion(User $usuario, SolicitudElemento $elemento, string $tipo = 'emision', array $extra = []): CredencialPreparacion
    {
        $respuesta = $this->actingAs($usuario)
            ->postJson(route('impresion.elemento.preparar', $elemento), ['tipo' => $tipo, ...$extra])
            ->assertOk()
            ->assertJson(['success' => true]);

        return CredencialPreparacion::where('uuid', $respuesta->json('uuid'))->firstOrFail();
    }

    /** Sirve la foto del snapshot (registra la primera visualización). */
    protected function verFoto(User $usuario, CredencialPreparacion $preparacion): void
    {
        $this->actingAs($usuario)
            ->get(route('impresion.preparacion.biometrico', ['preparacion' => $preparacion, 'categoria' => 'foto']))
            ->assertOk();
    }

    /** Sirve las cuatro evidencias del snapshot por la ruta real de la vista (registra sus entregas). */
    protected function verEvidencias(User $usuario, CredencialPreparacion $preparacion, array $categorias = ['foto', 'firma', 'pulgar_derecho', 'pulgar_izquierdo']): void
    {
        foreach ($categorias as $categoria) {
            $this->actingAs($usuario)
                ->get(route('impresion.preparacion.biometrico', ['preparacion' => $preparacion, 'categoria' => $categoria]))
                ->assertOk();
        }
    }

    protected function emitir(User $usuario, SolicitudElemento $elemento): CredencialPreparacion
    {
        $preparacion = $this->prepararImpresion($usuario, $elemento);
        $this->verEvidencias($usuario, $preparacion);
        $this->actingAs($usuario)
            ->postJson(route('impresion.elemento.emision', $elemento), ['preparacion_uuid' => $preparacion->uuid, 'resultado' => 'impresa'])
            ->assertOk();

        return $preparacion->refresh();
    }
}
