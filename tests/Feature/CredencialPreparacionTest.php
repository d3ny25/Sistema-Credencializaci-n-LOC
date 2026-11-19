<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BiometricoArchivo;
use App\Models\BiometricoAuditoria;
use App\Models\BiometricoEvidencia;
use App\Models\BiometricoIncidencia;
use App\Models\CredencialImpresion;
use App\Models\CredencialPreparacion;
use App\Models\User;
use App\Services\CredencialPreparacionService;
use App\Support\CanonicalJson;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PreparaImpresionReal;
use Tests\TestCase;

/**
 * Fase 1B.2 — Preparación de impresión con snapshot: idempotencia, cancelación, vigencias,
 * confirmación normal y extemporánea, duplicados, reimpresión histórica, cifrado e inmutabilidad.
 * Todos los datos y archivos son sintéticos.
 */
class CredencialPreparacionTest extends TestCase
{
    use PreparaImpresionReal;
    use RefreshDatabase;

    private User $impresor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDiscosEvidencia();
        $this->impresor = User::factory()->create(['role' => 'impresion']);
    }

    private function confirmar(CredencialPreparacion $preparacion, array $datos, bool $reimpresion = false)
    {
        $ruta = $reimpresion ? 'impresion.elemento.reimpresion' : 'impresion.elemento.emision';

        return $this->actingAs($this->impresor)->postJson(route($ruta, $preparacion->solicitud_elemento_id), ['preparacion_uuid' => $preparacion->uuid, ...$datos]);
    }

    private function cancelar(CredencialPreparacion $preparacion): void
    {
        $this->actingAs($this->impresor)->postJson(route('impresion.preparacion.cancelar', $preparacion), ['motivo' => 'Prueba de cancelación'])->assertOk();
    }

    // ---- Snapshot -----------------------------------------------------------------------

    public function test_preparacion_conserva_cuatro_evidencias_verificadas_y_snapshot_documental(): void
    {
        $elemento = $this->elementoImprimible();
        $preparacion = $this->prepararImpresion($this->impresor, $elemento);

        $this->assertSame('preparada', $preparacion->estado);
        $this->assertCount(4, $preparacion->biometricos);
        foreach ($preparacion->biometricos as $componente) {
            $archivo = BiometricoArchivo::find($componente->biometrico_archivo_id);
            $this->assertSame('verificada', $componente->clasificacion);
            $this->assertSame($archivo->sha256, $componente->sha256);
            $this->assertSame($archivo->sha256, $componente->evidencia->sha256);
            $this->assertFileExists(Storage::disk('biometricos_evidencia')->path($componente->evidencia->ruta_relativa));
        }

        // Manifiesto: hash de los bytes guardados, con los cuatro hashes de los componentes.
        $manifiesto = json_decode($preparacion->manifiesto_canonico, true);
        $this->assertSame(CanonicalJson::hash($preparacion->manifiesto_canonico), $preparacion->manifiesto_sha256);
        $this->assertSame($preparacion->biometricos->pluck('sha256', 'categoria.value')->sortKeys()->all(), $manifiesto['biometricos']);
        $this->assertSame(CanonicalJson::hash($preparacion->plantilla_config_canonica), $preparacion->plantilla_config_sha256);

        // Datos variables cifrados: el texto cifrado no contiene datos legibles y el hash es del texto claro.
        $this->assertStringNotContainsString($elemento->cuip, $preparacion->datos_variables_cifrados);
        $claro = Crypt::decryptString($preparacion->datos_variables_cifrados);
        $this->assertSame($preparacion->datos_variables_sha256, CanonicalJson::hash($claro));
        $this->assertSame($elemento->cuip, json_decode($claro, true)['cuip']);

        // Sin persona registrada, los campos tomados de valores por defecto quedan identificados.
        $this->assertContains('subdireccion', $preparacion->campos_por_defecto);
        $this->assertArrayHasKey('vista_sha256', $preparacion->render_huella);
    }

    public function test_preparacion_exige_los_cuatro_biometricos_validos(): void
    {
        $elemento = $this->elementoImprimible();
        $elemento->expedienteBiometrico->forceFill(['pulgar_izquierdo_archivo_id' => null])->save();

        $this->actingAs($this->impresor)->postJson(route('impresion.elemento.preparar', $elemento), ['tipo' => 'emision'])
            ->assertStatus(409)->assertJsonFragment(['message' => 'No es posible preparar la impresión: biométrico no válido (pulgar_izquierdo).']);
        $this->assertDatabaseCount('credencial_preparaciones', 0);
    }

    public function test_archivo_de_origen_alterado_genera_incidencia_sin_datos_personales(): void
    {
        $elemento = $this->elementoImprimible();
        $foto = BiometricoArchivo::find($elemento->expedienteBiometrico->foto_archivo_id);
        Storage::disk('biometricos_ingesta')->put($foto->ruta_relativa, $this->pngSintetico(9999));

        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logs): void {
            $logs[] = $e->message.' '.json_encode($e->context);
        });

        $respuesta = $this->actingAs($this->impresor)->postJson(route('impresion.elemento.preparar', $elemento), ['tipo' => 'emision'])->assertStatus(409);

        $this->assertDatabaseCount('credencial_preparaciones', 0);
        $this->assertDatabaseCount('biometrico_evidencias', 0);
        $incidencia = BiometricoIncidencia::where('codigo', 'EVIDENCIA_ORIGEN_DISCREPANTE')->firstOrFail();
        $this->assertNull($incidencia->biometrico_index_run_id);
        $this->assertStringStartsWith('preparacion/', $incidencia->ruta_relativa);
        foreach ([$respuesta->getContent(), $incidencia->ruta_relativa, $incidencia->descripcion, json_encode($incidencia->detalles), implode("\n", $logs)] as $texto) {
            $this->assertStringNotContainsString($elemento->cuip, $texto);
            $this->assertStringNotContainsString('INGESTA', $texto);
        }
    }

    // ---- Idempotencia, vigencias y expiración -------------------------------------------

    public function test_preparar_es_idempotente_y_ver_la_vista_no_crea_preparaciones(): void
    {
        $elemento = $this->elementoImprimible();
        $primera = $this->prepararImpresion($this->impresor, $elemento);
        $segunda = $this->prepararImpresion($this->impresor, $elemento);

        $this->assertSame($primera->uuid, $segunda->uuid);
        $this->actingAs($this->impresor)->get(route('impresion.preparacion', $primera))->assertOk();
        $this->actingAs($this->impresor)->get(route('impresion.preparacion', $primera))->assertOk();
        $this->actingAs($this->impresor)->get(route('impresion.plantilla.imprimir', ['elemento_id' => $elemento->id]))->assertOk();
        $this->assertDatabaseCount('credencial_preparaciones', 1);
    }

    public function test_la_base_de_datos_impide_dos_preparaciones_activas_por_credencial(): void
    {
        $preparacion = $this->prepararImpresion($this->impresor, $this->elementoImprimible());
        $fila = (array) DB::table('credencial_preparaciones')->where('id', $preparacion->id)->first();
        unset($fila['id'], $fila['clave_activa']);
        $fila['uuid'] = '99999999-9999-4999-8999-999999999999';

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('credencial_preparaciones')->insert($fila);
    }

    public function test_vista_y_preparacion_expiran_por_separado(): void
    {
        $elemento = $this->elementoImprimible();
        $preparacion = $this->prepararImpresion($this->impresor, $elemento);
        $this->verEvidencias($this->impresor, $preparacion);
        $this->assertNotNull($preparacion->refresh()->primera_vista_at);

        // A los 31 min la vista ya no sirve imágenes, pero la preparación sigue vigente y se confirma con normalidad.
        $this->travel(31)->minutes();
        $this->actingAs($this->impresor)->get(route('impresion.preparacion.biometrico', ['preparacion' => $preparacion, 'categoria' => 'foto']))->assertStatus(409);
        $this->assertSame('preparada', $preparacion->refresh()->estado);
        $this->confirmar($preparacion, ['resultado' => 'impresa'])->assertOk()->assertJson(['modalidad' => 'normal', 'estado_emision' => 'emitida']);
    }

    public function test_preparacion_vencida_expira_y_se_reemplaza(): void
    {
        $elemento = $this->elementoImprimible();
        $vieja = $this->prepararImpresion($this->impresor, $elemento);

        $this->travel(25)->hours();
        $this->assertSame(1, app(CredencialPreparacionService::class)->expirarVencidas());
        $this->assertSame('expirada', $vieja->refresh()->estado);
        $this->confirmar($vieja, ['resultado' => 'impresa'])->assertStatus(409);

        $nueva = $this->prepararImpresion($this->impresor, $elemento);
        $this->assertNotSame($vieja->uuid, $nueva->uuid);
        // Mismo contenido: las evidencias se reutilizan (deduplicación por hash), no se copian de nuevo.
        $this->assertSame(4, BiometricoEvidencia::count());
    }

    // ---- Cancelación automática por actualización biométrica ---------------------------

    public function test_actualizar_un_biometrico_cancela_preparaciones_activas_sin_tocar_su_snapshot(): void
    {
        $elemento = $this->elementoImprimible();
        $preparacion = $this->prepararImpresion($this->impresor, $elemento);
        $antes = $preparacion->biometricos()->orderBy('categoria')->get(['categoria', 'biometrico_evidencia_id', 'sha256'])->toArray();

        $this->actingAs($this->impresor)->post(route('impresion.elemento.actualizar-biometrico', $elemento), [
            'tipo_biometrico' => 'foto',
            'archivo' => UploadedFile::fake()->createWithContent('foto.png', $this->pngSintetico(4242)),
            'motivo_cambio' => 'Actualización de prueba',
        ], ['Accept' => 'application/json'])->assertOk();

        $preparacion->refresh();
        $this->assertSame('cancelada', $preparacion->estado);
        $this->assertSame('actualizacion_biometrica', $preparacion->motivo_cancelacion);
        $this->assertSame($antes, $preparacion->biometricos()->orderBy('categoria')->get(['categoria', 'biometrico_evidencia_id', 'sha256'])->toArray());
        $this->assertSame(4, BiometricoEvidencia::count());

        // La nueva preparación toma la foto actualizada; la anterior conserva la suya.
        $nueva = $this->prepararImpresion($this->impresor, $elemento);
        $this->assertNotSame(
            $preparacion->biometricos()->where('categoria', 'foto')->value('sha256'),
            $nueva->biometricos()->where('categoria', 'foto')->value('sha256'),
        );
    }

    // ---- Confirmación extemporánea -------------------------------------------------------

    public function test_confirmacion_extemporanea_conserva_el_estado_de_la_preparacion(): void
    {
        $elemento = $this->elementoImprimible();
        $preparacion = $this->prepararImpresion($this->impresor, $elemento);
        $this->verEvidencias($this->impresor, $preparacion);
        $this->cancelar($preparacion);

        $this->confirmar($preparacion, ['resultado' => 'impresa'])->assertStatus(409);
        $this->confirmar($preparacion, ['resultado' => 'impresa', 'extemporanea' => true, 'justificacion_extemporanea' => 'La tarjeta se imprimió antes de la cancelación.'])
            ->assertOk()->assertJson(['modalidad' => 'extemporanea', 'estado_emision' => 'emitida']);

        $this->assertSame('cancelada', $preparacion->refresh()->estado);
        $this->assertDatabaseHas('credencial_impresiones', ['credencial_preparacion_id' => $preparacion->id, 'modalidad_confirmacion' => 'extemporanea']);
    }

    public function test_extemporanea_rechazada_sin_primera_visualizacion_fuera_de_ventana_o_sin_justificacion(): void
    {
        $sinVista = $this->prepararImpresion($this->impresor, $this->elementoImprimible(1));
        $this->cancelar($sinVista);
        $this->confirmar($sinVista, ['resultado' => 'impresa', 'extemporanea' => true, 'justificacion_extemporanea' => 'Justificación suficiente.'])->assertStatus(409);

        $sinJustificacion = $this->prepararImpresion($this->impresor, $this->elementoImprimible(2, folio: 'FOL-2026-00002'));
        $this->verEvidencias($this->impresor, $sinJustificacion);
        $this->cancelar($sinJustificacion);
        $this->confirmar($sinJustificacion, ['resultado' => 'impresa', 'extemporanea' => true])->assertUnprocessable()->assertJsonValidationErrors('justificacion_extemporanea');

        $tardia = $this->prepararImpresion($this->impresor, $this->elementoImprimible(3, folio: 'FOL-2026-00003'));
        $this->verEvidencias($this->impresor, $tardia);
        $this->cancelar($tardia);
        $this->travel(73)->hours();
        $this->confirmar($tardia, ['resultado' => 'impresa', 'extemporanea' => true, 'justificacion_extemporanea' => 'Justificación suficiente.'])->assertStatus(409);

        $this->assertDatabaseCount('credencial_impresiones', 0);
    }

    public function test_doble_confirmacion_es_idempotente_y_un_resultado_distinto_se_rechaza(): void
    {
        $preparacion = $this->prepararImpresion($this->impresor, $this->elementoImprimible());
        $this->confirmar($preparacion, ['resultado' => 'fallida'])->assertOk()->assertJson(['idempotente' => false]);
        $this->confirmar($preparacion, ['resultado' => 'fallida'])->assertOk()->assertJson(['idempotente' => true]);
        $this->confirmar($preparacion, ['resultado' => 'impresa'])->assertStatus(409);
        $this->assertDatabaseCount('credencial_impresiones', 1);
    }

    public function test_posible_tarjeta_duplicada_genera_incidencia_y_su_resolucion_requiere_autorizacion(): void
    {
        $elemento = $this->elementoImprimible();
        $primera = $this->prepararImpresion($this->impresor, $elemento);
        $this->verEvidencias($this->impresor, $primera);
        $this->cancelar($primera);

        $segunda = $this->prepararImpresion($this->impresor, $elemento);
        $this->verEvidencias($this->impresor, $segunda);
        $this->confirmar($segunda, ['resultado' => 'impresa'])->assertOk();
        $fechaEmision = $elemento->credencialEmitida()->value('fecha_emision');

        $this->confirmar($primera, ['resultado' => 'impresa', 'extemporanea' => true, 'justificacion_extemporanea' => 'Se encontró una tarjeta impresa con esta preparación.'])
            ->assertOk()->assertJson(['posible_duplicado' => true]);

        $this->assertSame('emitida', $elemento->credencialEmitida()->value('estado_emision'));
        $this->assertEquals($fechaEmision, $elemento->credencialEmitida()->value('fecha_emision'));
        $incidencia = BiometricoIncidencia::where('codigo', 'POSIBLE_TARJETA_DUPLICADA')->firstOrFail();
        $this->assertSame($primera->id, $incidencia->credencial_preparacion_id);

        $ruta = route('impresion.incidencia.resolver', $incidencia);
        $this->actingAs($this->impresor)->postJson($ruta, ['resolucion' => 'Tarjeta destruida ante testigos.'])->assertForbidden();
        $supervisor = User::factory()->create(['role' => 'superadmin']);
        $this->actingAs($supervisor)->postJson($ruta, ['resolucion' => 'Tarjeta destruida ante testigos.'])->assertOk();
        $this->actingAs($supervisor)->postJson($ruta, ['resolucion' => 'Segundo intento de resolución.'])->assertStatus(409);
        $this->assertNotNull($incidencia->refresh()->resuelta_at);
    }

    public function test_rectificacion_auditable_no_reescribe_el_registro_original(): void
    {
        $preparacion = $this->prepararImpresion($this->impresor, $this->elementoImprimible());
        $this->verEvidencias($this->impresor, $preparacion);
        $this->confirmar($preparacion, ['resultado' => 'impresa'])->assertOk();
        $impresion = CredencialImpresion::where('credencial_preparacion_id', $preparacion->id)->firstOrFail();
        $ruta = route('impresion.impresion.rectificar', $impresion);

        $this->actingAs($this->impresor)->postJson($ruta, ['resultado' => 'fallida', 'motivo' => 'Se confirmó por error.'])->assertForbidden();
        $supervisor = User::factory()->create(['role' => 'superadmin']);
        $this->actingAs($supervisor)->postJson($ruta, ['resultado' => 'impresa', 'motivo' => 'Mismo resultado vigente.'])->assertStatus(409);
        $this->actingAs($supervisor)->postJson($ruta, ['resultado' => 'fallida', 'motivo' => 'Se confirmó por error.'])->assertOk();

        $this->assertSame('impresa', $impresion->refresh()->resultado);
        $this->assertSame('fallida', $impresion->resultadoVigente());
    }

    // ---- Reimpresión histórica ------------------------------------------------------------

    public function test_reimpresion_historica_reutiliza_los_insumos_originales_sin_leer_los_vigentes(): void
    {
        $elemento = $this->elementoImprimible();
        $emision = $this->emitir($this->impresor, $elemento);
        $fotoOriginal = $emision->biometricos()->where('categoria', 'foto')->value('sha256');

        // Cambio posterior de la foto vigente (fuera del flujo de la interfaz, que sigue bloqueado tras emitir).
        $foto = BiometricoArchivo::find($elemento->expedienteBiometrico->foto_archivo_id);
        $nueva = $this->pngSintetico(7777);
        Storage::disk('biometricos_ingesta')->put('INGESTA/ESTATALES/FOTO/nueva.png', $nueva);
        DB::table('biometrico_archivos')->where('id', $foto->id)->update(['ruta_relativa' => 'INGESTA/ESTATALES/FOTO/nueva.png', 'sha256' => hash('sha256', $nueva), 'tamano_bytes' => strlen($nueva)]);

        $reimpresion = $this->prepararImpresion($this->impresor, $elemento, 'reimpresion');

        $this->assertSame($emision->id, $reimpresion->preparacion_origen_id);
        $this->assertSame($emision->manifiesto_sha256, $reimpresion->manifiesto_sha256);
        $this->assertSame($emision->datos_variables_cifrados, $reimpresion->datos_variables_cifrados);
        $this->assertSame($emision->plantilla_config_canonica, $reimpresion->plantilla_config_canonica);
        $this->assertSame(
            $emision->biometricos()->orderBy('categoria')->pluck('biometrico_evidencia_id')->all(),
            $reimpresion->biometricos()->orderBy('categoria')->pluck('biometrico_evidencia_id')->all(),
        );

        $servida = $this->actingAs($this->impresor)->get(route('impresion.preparacion.biometrico', ['preparacion' => $reimpresion, 'categoria' => 'foto']))->assertOk();
        $this->assertSame($fotoOriginal, hash('sha256', $servida->getContent()));
        $this->assertNotSame(hash('sha256', $nueva), $fotoOriginal);
        $servida->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->verEvidencias($this->impresor, $reimpresion, ['firma', 'pulgar_derecho', 'pulgar_izquierdo']);
        $this->confirmar($reimpresion, ['resultado' => 'impresa', 'motivo' => 'Tarjeta dañada al laminar'], true)->assertOk()->assertJson(['numero_intento' => 2]);
    }

    public function test_reimpresion_historica_imposible_sin_evidencia_de_emision(): void
    {
        $elemento = $this->elementoImprimible();
        // Credencial emitida antes de conservar snapshots (sin preparación de emisión).
        $elemento->credencialEmitida->forceFill(['estado_emision' => 'emitida', 'fecha_emision' => now()])->save();

        $this->actingAs($this->impresor)->postJson(route('impresion.elemento.preparar', $elemento), ['tipo' => 'reimpresion'])
            ->assertStatus(409)->assertJsonFragment(['message' => 'La credencial no tiene evidencia histórica de su emisión (emitida antes de conservar snapshots); la reimpresión histórica no es posible.']);
    }

    // ---- Autorización ------------------------------------------------------------------------

    public function test_acceso_no_autorizado_y_consulta_historica_auditada(): void
    {
        $elemento = $this->elementoImprimible();
        $preparacion = $this->prepararImpresion($this->impresor, $elemento);
        $foto = ['preparacion' => $preparacion, 'categoria' => 'foto'];

        foreach (['credencializador', 'operador', 'local_demo'] as $rol) {
            $this->actingAs(User::factory()->create(['role' => $rol]))->get(route('impresion.preparacion.biometrico', $foto))->assertForbidden();
            $this->actingAs(User::factory()->create(['role' => $rol]))->postJson(route('impresion.elemento.preparar', $elemento), ['tipo' => 'emision'])->assertForbidden();
        }
        $this->actingAs($this->impresor)->get(route('impresion.preparacion.evidencia', $foto))->assertForbidden();

        // La consulta histórica no depende de la vigencia de la vista y queda auditada.
        $this->travel(2)->days();
        $supervisor = User::factory()->create(['role' => 'superadmin']);
        $this->actingAs($supervisor)->get(route('impresion.preparacion.evidencia', $foto))->assertOk();
        $this->assertTrue(BiometricoAuditoria::where('evento', 'consulta_evidencia_historica')->where('user_id', $supervisor->id)->exists());
        $this->actingAs($this->impresor)->get(route('impresion.preparacion.biometrico', $foto))->assertStatus(409);
    }

    // ---- Inmutabilidad -------------------------------------------------------------------------

    public function test_snapshot_evidencias_e_impresiones_no_se_modifican_ni_eliminan(): void
    {
        $preparacion = $this->prepararImpresion($this->impresor, $this->elementoImprimible());
        $this->verEvidencias($this->impresor, $preparacion);
        $this->confirmar($preparacion, ['resultado' => 'impresa'])->assertOk();
        $impresion = CredencialImpresion::firstOrFail();
        $evidencia = BiometricoEvidencia::firstOrFail();

        // Capa Eloquent.
        foreach ([
            fn () => $preparacion->forceFill(['plantilla_version' => 99])->save(),
            fn () => $preparacion->delete(),
            fn () => $evidencia->forceFill(['formato' => 'jpg'])->save(),
            fn () => $evidencia->delete(),
            fn () => $impresion->forceFill(['resultado' => 'fallida'])->save(),
            fn () => $preparacion->biometricos()->first()->delete(),
        ] as $operacion) {
            try {
                $operacion();
                $this->fail('Eloquent debió rechazar la modificación.');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        // Capa de base de datos (query builder, que no dispara eventos de Eloquent).
        foreach ([
            fn () => DB::table('credencial_preparaciones')->where('id', $preparacion->id)->update(['manifiesto_sha256' => str_repeat('0', 64)]),
            fn () => DB::table('credencial_preparaciones')->where('id', $preparacion->id)->update(['estado' => 'preparada']),
            fn () => DB::table('credencial_preparaciones')->delete(),
            fn () => DB::table('biometrico_evidencias')->update(['formato' => 'jpg']),
            fn () => DB::table('biometrico_evidencias')->delete(),
            fn () => DB::table('credencial_preparacion_biometricos')->delete(),
            fn () => DB::table('credencial_impresiones')->update(['resultado' => 'fallida']),
            fn () => DB::table('credencial_impresiones')->delete(),
            fn () => DB::table('credencial_preparacion_entregas')->update(['categoria' => 'firma']),
            fn () => DB::table('credencial_preparacion_entregas')->delete(),
        ] as $operacion) {
            try {
                $operacion();
                $this->fail('La base de datos debió rechazar la modificación.');
            } catch (\Illuminate\Database\QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('impresa', $impresion->refresh()->resultado);
        $this->assertSame('confirmada', $preparacion->refresh()->estado);
    }

    // ---- Hallazgo 1: reutilización solo con la misma plantilla (hash) -------------------------

    private function publicarPlantillaV1(array $config): void
    {
        \App\Models\CredentialTemplate::updateOrCreate(
            ['slug' => \App\Http\Controllers\CredentialTemplateController::SLUG_V1],
            ['name' => 'Plantilla sintética', 'status' => 'published', 'current_version' => 1, 'published_version' => 1, 'template_config' => $config, 'published_config' => $config],
        );
    }

    public function test_otra_plantilla_no_reutiliza_la_preparacion_y_conserva_la_anterior(): void
    {
        $elemento = $this->elementoImprimible();
        $v1 = $this->prepararImpresion($this->impresor, $elemento, 'emision', ['template_version' => '1']);
        $this->assertSame($v1->uuid, $this->prepararImpresion($this->impresor, $elemento, 'emision', ['template_version' => '1'])->uuid, 'Misma plantilla: se reutiliza.');

        $v2 = $this->prepararImpresion($this->impresor, $elemento, 'emision', ['template_version' => '2']);

        $this->assertNotSame($v1->uuid, $v2->uuid);
        $this->assertNotSame($v1->plantilla_config_sha256, $v2->plantilla_config_sha256);
        $v1->refresh();
        $this->assertSame('cancelada', $v1->estado);
        $this->assertSame('reemplazada_por_cambio_de_plantilla', $v1->motivo_cancelacion);
        $this->assertSame(1, CredencialPreparacion::where('credencial_emitida_id', $v1->credencial_emitida_id)->where('estado', 'preparada')->count());
        // La anterior conserva sus evidencias pero ya no sirve imágenes ni admite confirmación normal.
        $this->assertCount(4, $v1->biometricos);
        foreach ($v1->biometricos as $componente) {
            $this->assertFileExists(Storage::disk('biometricos_evidencia')->path($componente->evidencia->ruta_relativa));
        }
        $this->actingAs($this->impresor)->get(route('impresion.preparacion.biometrico', ['preparacion' => $v1, 'categoria' => 'foto']))->assertStatus(409);
        $this->confirmar($v1, ['resultado' => 'fallida'])->assertStatus(409);
    }

    public function test_misma_version_publicada_con_distinta_configuracion_no_se_reutiliza(): void
    {
        $elemento = $this->elementoImprimible();
        $config = config('credential-templates.default');
        $this->publicarPlantillaV1($config);
        $primera = $this->prepararImpresion($this->impresor, $elemento, 'emision', ['template_version' => '1']);

        $config['faces']['front']['elements'][0]['x'] = ($config['faces']['front']['elements'][0]['x'] ?? 0) + 1;
        $this->publicarPlantillaV1($config); // misma published_version (1), contenido distinto
        $segunda = $this->prepararImpresion($this->impresor, $elemento, 'emision', ['template_version' => '1']);

        $this->assertSame($primera->plantilla_version, $segunda->plantilla_version);
        $this->assertNotSame($primera->uuid, $segunda->uuid);
        $this->assertSame('cancelada', $primera->refresh()->estado);
    }

    public function test_plantilla_que_no_muestra_las_cuatro_evidencias_no_se_puede_preparar(): void
    {
        $config = config('credential-templates.default');
        foreach ($config['faces'] as $cara => $datos) {
            foreach ($datos['elements'] as $i => $elemento) {
                if (($elemento['field'] ?? null) === 'huella_izquierda') {
                    $config['faces'][$cara]['elements'][$i]['visible'] = false;
                }
            }
        }
        $this->publicarPlantillaV1($config);

        $this->actingAs($this->impresor)->postJson(route('impresion.elemento.preparar', $this->elementoImprimible()), ['tipo' => 'emision', 'template_version' => '1'])
            ->assertStatus(409)->assertJsonFragment(['message' => 'La plantilla publicada no muestra todas las evidencias biométricas (pulgar_izquierdo).']);
        $this->assertDatabaseCount('credencial_preparaciones', 0);
    }

    // ---- Hallazgo 2: "impresa" exige las cuatro evidencias del snapshot servidas ----------------

    public function test_impresa_exige_las_cuatro_evidencias_servidas(): void
    {
        $preparacion = $this->prepararImpresion($this->impresor, $this->elementoImprimible());
        $this->confirmar($preparacion, ['resultado' => 'impresa'])->assertStatus(409);

        $servidas = [];
        foreach (['foto', 'firma', 'pulgar_derecho'] as $categoria) {
            $this->verEvidencias($this->impresor, $preparacion, [$categoria]);
            $servidas[] = $categoria;
            $this->confirmar($preparacion, ['resultado' => 'impresa'])->assertStatus(409)
                ->assertJsonFragment(['message' => 'No es posible confirmar la impresión: no se sirvieron todas las evidencias biométricas de la preparación (faltan: '.implode(', ', array_diff(['foto', 'firma', 'pulgar_derecho', 'pulgar_izquierdo'], $servidas)).').']);
        }

        $this->verEvidencias($this->impresor, $preparacion, ['pulgar_izquierdo']);
        // Repetir una entrega no duplica registros.
        $this->verEvidencias($this->impresor, $preparacion, ['foto']);
        $this->assertSame(4, DB::table('credencial_preparacion_entregas')->where('credencial_preparacion_id', $preparacion->id)->count());
        $this->confirmar($preparacion, ['resultado' => 'impresa'])->assertOk()->assertJson(['estado_emision' => 'emitida']);
    }

    public function test_entregas_de_otra_preparacion_o_de_otra_evidencia_no_cuentan(): void
    {
        $ajena = $this->prepararImpresion($this->impresor, $this->elementoImprimible(1));
        $this->verEvidencias($this->impresor, $ajena);
        $propia = $this->prepararImpresion($this->impresor, $this->elementoImprimible(2, folio: 'FOL-2026-00002'));

        // Filas manipuladas: entregas de la preparación propia apuntando a las evidencias de la ajena.
        foreach ($ajena->biometricos as $componente) {
            DB::table('credencial_preparacion_entregas')->insert([
                'credencial_preparacion_id' => $propia->id,
                'categoria' => $componente->categoria->value,
                'biometrico_evidencia_id' => $componente->biometrico_evidencia_id,
                'sha256' => $componente->sha256,
                'created_at' => now(),
            ]);
        }

        $this->confirmar($propia, ['resultado' => 'impresa'])->assertStatus(409);
        $this->assertSame(['foto', 'firma', 'pulgar_derecho', 'pulgar_izquierdo'], app(CredencialPreparacionService::class)->evidenciasPendientes($propia));
    }

    public function test_una_evidencia_que_fallo_puede_volver_a_solicitarse(): void
    {
        $preparacion = $this->prepararImpresion($this->impresor, $this->elementoImprimible());
        $foto = $preparacion->biometricos()->where('categoria', 'foto')->firstOrFail()->evidencia;
        $ruta = Storage::disk('biometricos_evidencia')->path($foto->ruta_relativa);
        $original = (string) file_get_contents($ruta);
        chmod($ruta, 0666);
        file_put_contents($ruta, 'ALTERADO');

        $this->actingAs($this->impresor)->get(route('impresion.preparacion.biometrico', ['preparacion' => $preparacion, 'categoria' => 'foto']))->assertStatus(409);
        $this->assertContains('foto', app(CredencialPreparacionService::class)->evidenciasPendientes($preparacion));

        file_put_contents($ruta, $original);
        $this->verEvidencias($this->impresor, $preparacion);
        $this->confirmar($preparacion, ['resultado' => 'impresa'])->assertOk();
    }

    public function test_fallida_no_exige_evidencias_y_la_extemporanea_impresa_si(): void
    {
        $fallida = $this->prepararImpresion($this->impresor, $this->elementoImprimible(1));
        $this->confirmar($fallida, ['resultado' => 'fallida'])->assertOk();

        $extemporanea = $this->prepararImpresion($this->impresor, $this->elementoImprimible(2, folio: 'FOL-2026-00002'));
        $this->verEvidencias($this->impresor, $extemporanea, ['foto']);
        $this->cancelar($extemporanea);
        $this->confirmar($extemporanea, ['resultado' => 'impresa', 'extemporanea' => true, 'justificacion_extemporanea' => 'Justificación suficiente.'])->assertStatus(409);
        $this->confirmar($extemporanea, ['resultado' => 'fallida', 'extemporanea' => true, 'justificacion_extemporanea' => 'Justificación suficiente.'])->assertOk();
        $this->assertSame('cancelada', $extemporanea->refresh()->estado);
    }

    // ---- Hallazgos 3 y 4: regla única de imprimibilidad y lanzador ------------------------------

    public function test_reglas_de_imprimibilidad_iguales_en_todos_los_puntos_de_entrada(): void
    {
        foreach ([
            ['evaluada', 'listo_impresion', 'FOL-2026-00011', 11, 'La tarjeta de trabajo no está autorizada para impresión.'],
            ['enviada_impresion', 'pendiente_biometrico', 'FOL-2026-00012', 12, 'El elemento no está listo para impresión.'],
            ['enviada_impresion', 'listo_impresion', null, 13, 'El elemento no tiene folio asignado.'],
        ] as [$estadoSolicitud, $estadoTramite, $folio, $n, $mensaje]) {
            $elemento = $this->elementoImprimible($n, $estadoSolicitud, $estadoTramite, $folio);

            $this->actingAs($this->impresor)->get(route('impresion.plantilla.imprimir', ['elemento_id' => $elemento->id]))->assertStatus(409);
            $this->actingAs($this->impresor)->postJson(route('impresion.elemento.preparar', $elemento), ['tipo' => 'emision'])
                ->assertStatus(409)->assertJsonFragment(['message' => $mensaje]);
            $this->actingAs($this->impresor)->postJson(route('impresion.elemento.emision', $elemento), ['preparacion_uuid' => (string) \Illuminate\Support\Str::uuid(), 'resultado' => 'fallida'])
                ->assertStatus(409)->assertJsonFragment(['message' => $mensaje]);
        }

        // Una preparación válida deja de poder confirmarse si el elemento deja de ser imprimible.
        $elemento = $this->elementoImprimible(14, folio: 'FOL-2026-00014');
        $preparacion = $this->prepararImpresion($this->impresor, $elemento);
        $elemento->forceFill(['estado_tramite' => 'pendiente_biometrico'])->save();
        $this->confirmar($preparacion, ['resultado' => 'fallida'])->assertStatus(409)->assertJsonFragment(['message' => 'El elemento no está listo para impresión.']);
    }

    public function test_lanzador_solo_ofrece_continuar_preparaciones_vigentes(): void
    {
        $elemento = $this->elementoImprimible();
        $url = route('impresion.plantilla.imprimir', ['elemento_id' => $elemento->id]);
        $vigente = $this->prepararImpresion($this->impresor, $elemento);
        $this->actingAs($this->impresor)->get($url)->assertOk()->assertSee('data-continuar-preparacion', false);

        $this->travel(25)->hours();
        $this->actingAs($this->impresor)->get($url)->assertOk()->assertDontSee('data-continuar-preparacion', false);
        $this->assertSame('expirada', $vigente->refresh()->estado);
        $this->assertCount(4, $vigente->biometricos);

        $nueva = $this->prepararImpresion($this->impresor, $elemento);
        $this->cancelar($nueva);
        $this->actingAs($this->impresor)->get($url)->assertOk()->assertDontSee('data-continuar-preparacion', false);
        $this->assertSame('cancelada', $nueva->refresh()->estado);
    }

    // ---- Sección 8: borrado de usuarios referenciados --------------------------------------------

    public function test_borrar_un_usuario_referenciado_por_la_evidencia_se_rechaza(): void
    {
        $preparador = User::factory()->create(['role' => 'impresion']);
        $preparacion = $this->prepararImpresion($preparador, $this->elementoImprimible());

        // Clave foránea restrict: rechazo explícito e idéntico en SQLite y MariaDB; el snapshot no cambia.
        try {
            DB::table('users')->where('id', $preparador->id)->delete();
            $this->fail('Debió rechazarse el borrado del usuario que preparó la impresión.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertMatchesRegularExpression('/foreign key|1451/i', $e->getMessage());
        }
        $this->assertSame($preparador->id, (int) $preparacion->refresh()->preparada_por_user_id);
    }

    public function test_restricciones_check_rechazan_filas_invalidas_en_la_base_de_datos(): void
    {
        $preparacion = $this->prepararImpresion($this->impresor, $this->elementoImprimible());
        $base = (array) DB::table('credencial_preparaciones')->where('id', $preparacion->id)->first();
        unset($base['id'], $base['clave_activa']);
        $base['estado'] = 'cancelada';
        $credencial = $preparacion->credencial_emitida_id;

        foreach ([
            'credencial_preparaciones' => [
                [...$base, 'uuid' => '00000000-0000-4000-8000-0000000000a1', 'estado' => 'inventado'],
                [...$base, 'uuid' => '00000000-0000-4000-8000-0000000000a2', 'tipo_operacion' => 'reimpresion_historica', 'preparacion_origen_id' => null],
                [...$base, 'uuid' => '00000000-0000-4000-8000-0000000000a3', 'manifiesto_sha256' => null],
                [...$base, 'uuid' => '00000000-0000-4000-8000-0000000000a4', 'estado_evidencia' => 'parcial'],
            ],
            'credencial_preparacion_biometricos' => [
                ['credencial_preparacion_id' => $preparacion->id, 'categoria' => 'foto', 'clasificacion' => 'verificada', 'biometrico_evidencia_id' => null, 'sha256' => null],
            ],
            'credencial_impresiones' => [
                ['credencial_emitida_id' => $credencial, 'solicitud_elemento_id' => $preparacion->solicitud_elemento_id, 'resultado' => 'impresa', 'modalidad_confirmacion' => 'extemporanea', 'justificacion_extemporanea' => null, 'numero_intento' => 90],
                ['credencial_emitida_id' => $credencial, 'solicitud_elemento_id' => $preparacion->solicitud_elemento_id, 'resultado' => 'impresa', 'modalidad_confirmacion' => 'otra', 'numero_intento' => 91],
            ],
        ] as $tabla => $filas) {
            foreach ($filas as $fila) {
                try {
                    DB::table($tabla)->insert($fila);
                    $this->fail("La base de datos aceptó una fila inválida en {$tabla}.");
                } catch (\Illuminate\Database\QueryException $e) {
                    // SQLite (trigger) y MariaDB (CHECK nativo) nombran la restricción chk_*.
                    $this->assertMatchesRegularExpression('/chk_[a-z_]+/i', $e->getMessage());
                }
            }
        }
    }

    // ---- Cifrado y rotación de claves (claves sintéticas generadas en la prueba) --------------

    public function test_datos_variables_se_recuperan_tras_rotar_app_key_con_claves_anteriores(): void
    {
        $claveA = 'base64:'.base64_encode(Encrypter::generateKey('aes-256-cbc'));
        $claveB = 'base64:'.base64_encode(Encrypter::generateKey('aes-256-cbc'));
        $this->usarClaves($claveA, []);
        $preparacion = $this->prepararImpresion($this->impresor, $this->elementoImprimible());
        $servicio = app(CredencialPreparacionService::class);
        $esperados = $servicio->datosVariables($preparacion);

        $this->usarClaves($claveB, [$claveA]);
        $this->assertSame($esperados, $servicio->datosVariables($preparacion->refresh()));

        $this->usarClaves($claveB, []);
        $this->expectException(DecryptException::class);
        $servicio->datosVariables($preparacion->refresh());
    }

    private function usarClaves(string $clave, array $anteriores): void
    {
        config(['app.key' => $clave, 'app.previous_keys' => $anteriores]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }
}
