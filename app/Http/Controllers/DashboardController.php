<?php

namespace App\Http\Controllers;

use App\Models\CredencialEmitida;
use App\Models\Device;
use App\Models\LocImportBatch;
use App\Models\LocPersona;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        // Un solo recorrido sobre solicitud_elementos para todos los conteos del flujo.
        $elementos = SolicitudElemento::query()->toBase()->selectRaw("
            COUNT(*) AS total,
            SUM(CASE WHEN resultado_prefiltro IS NULL THEN 1 ELSE 0 END) AS en_validacion,
            SUM(CASE WHEN resultado_prefiltro = 'Apto' THEN 1 ELSE 0 END) AS aptos,
            SUM(CASE WHEN resultado_prefiltro = 'Descartado' THEN 1 ELSE 0 END) AS descartados,
            SUM(CASE WHEN estado_tramite = 'pendiente_biometrico' THEN 1 ELSE 0 END) AS pendientes_biometrico,
            SUM(CASE WHEN estado_tramite = 'listo_impresion' THEN 1 ELSE 0 END) AS listos_impresion
        ")->first();

        $metrics = array_map('intval', (array) $elementos) + [
            'people' => LocPersona::query()->count(),
            'requests' => SolicitudCredencial::query()->count(),
            'credentials' => CredencialEmitida::query()->count(),
        ];

        $pct = fn (int $valor, int $total): int => $total > 0 ? min(100, (int) round(($valor / $total) * 100)) : 0;
        $evaluados = $metrics['aptos'] + $metrics['descartados'];

        // Solo lectura: actividad real ya persistida, sin bitácora ni datos de demostración.
        $recentRequests = SolicitudCredencial::query()
            ->latest('id')
            ->limit(6)
            ->get(['id', 'numero_tarjeta', 'numero_oficio', 'area_solicitante', 'estado_general', 'total_elementos', 'total_aptos', 'created_at']);

        $recentImports = LocImportBatch::query()
            ->latest('id')
            ->limit(5)
            ->get(['id', 'tipo_fuente', 'nombre_archivo', 'estado', 'fecha_procesamiento', 'created_at']);

        $devices = Device::query()->toBase()->selectRaw("
            COUNT(*) AS total,
            SUM(CASE WHEN administrative_status = 'enabled' THEN 1 ELSE 0 END) AS habilitados,
            SUM(CASE WHEN last_checked_at IS NULL THEN 1 ELSE 0 END) AS sin_prueba
        ")->first();

        return view('dashboard', [
            'recentRequests' => $recentRequests,
            'recentImports' => $recentImports,
            'devices' => array_map('intval', (array) $devices),
            'metrics' => $metrics,
            'progress' => [
                ['label' => 'Evaluados en prefiltro', 'detail' => "{$evaluados} de {$metrics['total']} elementos", 'pct' => $pct($evaluados, $metrics['total']), 'bar' => 'bg-inst-700'],
                ['label' => 'Aptos sobre evaluados', 'detail' => "{$metrics['aptos']} de {$evaluados} evaluados", 'pct' => $pct($metrics['aptos'], $evaluados), 'bar' => 'bg-estado-exito'],
                ['label' => 'Listos para impresión', 'detail' => "{$metrics['listos_impresion']} de {$metrics['aptos']} aptos", 'pct' => $pct($metrics['listos_impresion'], $metrics['aptos']), 'bar' => 'bg-estado-adv'],
                ['label' => 'Credenciales registradas', 'detail' => "{$metrics['credentials']} sobre {$metrics['aptos']} aptos", 'pct' => $pct($metrics['credentials'], $metrics['aptos']), 'bar' => 'bg-estado-exito'],
            ],
        ]);
    }
}
