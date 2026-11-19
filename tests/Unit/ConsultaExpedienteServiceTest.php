<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\LocVigenciaCredencial;
use App\Services\ConsultaExpedienteService;
use App\Services\LocCredentialsPolicyService;
use Tests\TestCase;

/** Detección de cronología no determinable sobre la regla de LocCredentialsPolicyService (sin base de datos). */
class ConsultaExpedienteServiceTest extends TestCase
{
    private int $id = 0;

    private function v(?string $solicitud, ?string $baja = null, string $estatus = 'ALTA'): LocVigenciaCredencial
    {
        return (new LocVigenciaCredencial)->forceFill(['id' => ++$this->id, 'fecha_solicitud' => $solicitud, 'fecha_baja' => $baja, 'estatus_credencial' => $estatus]);
    }

    public function test_determinable_solo_con_fecha_comparable_sin_empate(): void
    {
        $s = app(ConsultaExpedienteService::class);
        $politica = app(LocCredentialsPolicyService::class);
        $caso = function (array $registros) use ($s, $politica): bool {
            $c = collect($registros);

            return $s->esDeterminable($c, $politica->determineChronologicallyLatestRecord($c));
        };

        $this->assertTrue($caso([$this->v('2025-01-01')]), 'un solo registro');
        $this->assertTrue($caso([$this->v('2024-01-01'), $this->v('2025-01-01')]));
        $this->assertTrue($caso([$this->v('2025-01-01'), $this->v('2025-01-01', '2025-01-01', 'BAJA')]), 'empate resuelto por BAJA en la política');
        $this->assertFalse($caso([$this->v(null), $this->v(null)]), 'sin fechas: la política devolvería el orden de inserción');
        $this->assertFalse($caso([$this->v('2025-01-01'), $this->v('2025-01-01')]), 'empate sin desempate');
        $this->assertTrue($caso([$this->v('2025-01-01'), $this->v(null)]), 'con una fecha frente a nula, la fechada es la más reciente');
    }
}
