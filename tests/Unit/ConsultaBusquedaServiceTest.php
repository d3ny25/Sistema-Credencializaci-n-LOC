<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ConsultaBusquedaService;
use Tests\TestCase;

/** Detección orientativa de identificadores y términos de nombre (sin base de datos). */
class ConsultaBusquedaServiceTest extends TestCase
{
    public function test_deteccion_orientativa_por_formato(): void
    {
        $s = new ConsultaBusquedaService;
        $this->assertSame('CURP', $s->detectar('pelj800101hdfrpn09'));
        $this->assertSame('RFC', $s->detectar('PELJ800101AB1'));
        $this->assertSame('RFC', $s->detectar('PELJ800101'));
        $this->assertSame('Número de tarjeta', $s->detectar('CE/TA25-27/50001/2026'));
        $this->assertSame('Folio de credencial', $s->detectar('OM2527E00001'));
        $this->assertSame('CUIP', $s->detectar('ABCD800101H1234567AB'));
        $this->assertSame('ID_PERSONAL o ID_ARMAMENTO', $s->detectar('12345'));
        $this->assertSame('Nombre', $s->detectar('Juan Pérez'));
        $this->assertNull($s->detectar('ABC1234'), 'una matrícula no tiene formato propio: no se adivina');
    }

    public function test_terminos_de_nombre_solo_sin_digitos(): void
    {
        $s = new ConsultaBusquedaService;
        $this->assertSame(['juan', 'perez'], $s->terminosNombre('juan perez'));
        $this->assertSame(['de', 'la'], array_values(array_intersect(['de', 'la'], $s->terminosNombre('maria de la cruz'))));
        $this->assertSame([], $s->terminosNombre('ABC1234'));
        $this->assertSame([], $s->terminosNombre('ab'));
    }
}
