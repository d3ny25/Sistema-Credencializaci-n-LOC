<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\BuscarConsultaRequest;
use App\Models\LocPersona;
use App\Services\ConsultaBusquedaService;
use App\Services\ConsultaExpedienteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Consultas: búsqueda centralizada (C1) y expediente de elemento (C2), de solo lectura. Arma y tarjeta: C3–C4. */
class ConsultaController extends Controller
{
    public function __construct(
        private readonly ConsultaBusquedaService $busqueda,
        private readonly ConsultaExpedienteService $expediente,
    ) {}

    public function index(BuscarConsultaRequest $request): View|JsonResponse
    {
        $q = $request->validated('q');
        $contexto = $request->validated('tipo') ?? 'todo';

        return $this->responder($request, 'modules.consultas.contenido', [
            'q' => $q,
            'contexto' => $contexto,
            'detectado' => $q === null ? null : $this->busqueda->detectar($q),
            'resultados' => $q === null ? null : $this->busqueda->buscar($q, $contexto),
        ]);
    }

    /** Cada sección consulta solo sus datos; la persona llega por su ID interno (route model binding, 404 si no existe). */
    public function elemento(Request $request, LocPersona $persona, string $seccion = 'resumen'): View|JsonResponse
    {
        abort_unless($request->user()?->can('access-consultas'), 403);

        return $this->responder($request, 'modules.consultas.elemento', [
            'persona' => $persona,
            'seccion' => $seccion,
            'datos' => match ($seccion) {
                'resumen' => $this->expediente->resumen($persona),
                'vigencias' => $this->expediente->vigencias($persona),
                'armamento-vinculado' => $this->expediente->armamento($persona),
                'tramites' => $this->expediente->tramitesDetallados($persona),
            },
        ]);
    }

    /** Mismo contrato de navegación dinámica que EntregaSeguimientoController::responder(). */
    private function responder(Request $request, string $parcial, array $datos): View|JsonResponse
    {
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'html' => view($parcial, $datos)->render(),
                'avisos' => view('layouts.partials.avisos')->render(),
                'url' => $request->fullUrl(),
            ]);
        }

        return view('modules.consultas', [...$datos, 'parcial' => $parcial]);
    }
}
