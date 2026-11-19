<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\SequenceNotInitializedException;
use App\Services\SolicitudTarjetaSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class FolioCredencialSequenceTest extends TestCase
{
    use RefreshDatabase;

    private function servicio(): SolicitudTarjetaSequenceService
    {
        return app(SolicitudTarjetaSequenceService::class);
    }

    private function siguiente(): string
    {
        return DB::transaction(fn () => $this->servicio()->generateNextFolio());
    }

    private function ultimoConsecutivo(): int
    {
        return (int) DB::table('solicitud_secuencias')->where('clave', 'folio_credencial')->value('ultimo_consecutivo');
    }

    public function test_la_migracion_inicializa_la_secuencia_con_el_ultimo_folio_institucional(): void
    {
        // Último folio institucional confirmado: OM2527E00000.
        $valor = DB::table('solicitud_secuencias')->where('clave', 'folio_credencial')->value('ultimo_consecutivo');
        $this->assertNotNull($valor, 'La migración debe crear la secuencia folio_credencial.');
        $this->assertSame(0, (int) $valor);
    }

    public function test_primer_folio_es_el_consecutivo_siguiente_al_ultimo_institucional(): void
    {
        $this->assertSame('OM2527E00001', $this->siguiente());
        $this->assertSame(1, $this->ultimoConsecutivo());
    }

    public function test_cada_folio_incrementa_en_uno_y_es_unico(): void
    {
        $folios = array_map(fn () => $this->siguiente(), range(1, 5));

        $this->assertSame(['OM2527E00001', 'OM2527E00002', 'OM2527E00003', 'OM2527E00004', 'OM2527E00005'], $folios);
        $this->assertCount(5, array_unique($folios));
    }

    public function test_sin_secuencia_inicializada_lanza_excepcion_y_no_genera_folio_alternativo(): void
    {
        DB::table('solicitud_secuencias')->where('clave', 'folio_credencial')->delete();

        $this->expectException(SequenceNotInitializedException::class);
        $this->siguiente();
    }

    public function test_si_la_transaccion_falla_el_incremento_se_revierte(): void
    {
        try {
            DB::transaction(function (): void {
                $this->servicio()->generateNextFolio();
                throw new RuntimeException('Fallo simulado al crear la credencial');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $this->ultimoConsecutivo());
        $this->assertSame('OM2527E00001', $this->siguiente());
    }

    public function test_el_formato_es_configurable_y_continua_al_cambiar_de_periodo(): void
    {
        $this->assertSame('OM2527E00001', $this->siguiente());

        config(['credencializacion.tarjeta_periodo' => '27-29']);

        // El consecutivo no se reinicia con el periodo (misma regla que las tarjetas).
        $this->assertSame('OM2729E00002', $this->siguiente());
    }

    public function test_la_migracion_no_sobrescribe_una_secuencia_existente(): void
    {
        DB::table('solicitud_secuencias')->where('clave', 'folio_credencial')->update(['ultimo_consecutivo' => 37]);

        $migracion = require database_path('migrations/2026_11_12_000001_seed_folio_credencial_sequence.php');
        $migracion->up();

        $this->assertSame(37, $this->ultimoConsecutivo());
        $this->assertSame('OM2527E00038', $this->siguiente());
    }
}
