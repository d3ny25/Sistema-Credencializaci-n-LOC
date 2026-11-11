<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\PrefiltroSourcesMissingException;
use App\Http\Requests\FilterSolicitudElementosRequest;
use App\Http\Requests\StoreSolicitudCredencialRequest;
use App\Http\Requests\UploadSolicitudElementosRequest;
use App\Models\LocCatalogoOrganizacional;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Services\SolicitudElementosImporter;
use App\Services\SolicitudPrefiltroService;
use App\Services\SolicitudTarjetaSequenceService;
use App\Support\MotivoDescarte;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class SolicitudCredencialController extends Controller
{
    /**
     * Muestra el listado de solicitudes de credencialización.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->input('buscar', ''));
        $estado = trim((string) $request->input('estado', ''));
        $desde = $request->input('desde');
        $hasta = $request->input('hasta');

        $query = SolicitudCredencial::query();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('numero_oficio', 'like', "%{$search}%")
                  ->orWhere('numero_tarjeta', 'like', "%{$search}%")
                  ->orWhere('area_solicitante', 'like', "%{$search}%");
            });
        }

        if ($estado !== '' && $estado !== 'Todos') {
            $query->where('estado_general', $estado);
        }

        if ($desde) {
            $query->whereDate('fecha_recepcion', '>=', $desde);
        }

        if ($hasta) {
            $query->whereDate('fecha_recepcion', '<=', $hasta);
        }

        $solicitudes = $query->latest('id')->paginate(15)->withQueryString();

        $counts = [
            'total' => SolicitudCredencial::count(),
            'en_revision' => SolicitudCredencial::where('total_elementos', '>', 0)
                ->where('total_aptos', 0)
                ->where('total_descartados', 0)
                ->count(),
            'aptos' => (int) SolicitudCredencial::sum('total_aptos'),
            'descartados' => (int) SolicitudCredencial::sum('total_descartados'),
        ];

        return view('modules.credencializacion', [
            'detail' => false,
            'solicitud' => null,
            'solicitudes' => $solicitudes,
            'counts' => $counts,
            'filters' => [
                'buscar' => $search,
                'estado' => $estado,
                'desde' => $desde,
                'hasta' => $hasta,
            ],
        ]);
    }

    /**
     * Búsqueda de áreas en el catálogo organizacional vigente para autocompletado institucional.
     */
    public function buscarCatalogo(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([
                'data' => [],
            ]);
        }

        $registros = LocCatalogoOrganizacional::vigente()
            ->where(function ($sub) use ($q) {
                $sub->where('clave_origen', 'like', "%{$q}%")
                    ->orWhere('nombre', 'like', "%{$q}%")
                    ->orWhere('agrupamiento', 'like', "%{$q}%")
                    ->orWhere('direccion_general', 'like', "%{$q}%")
                    ->orWhere('coordinacion', 'like', "%{$q}%")
                    ->orWhere('subdireccion', 'like', "%{$q}%");
            })
            ->orderBy('clave_origen')
            ->orderBy('id')
            ->limit(15)
            ->get(['id', 'clave_origen', 'nombre', 'agrupamiento', 'coordinacion']);

        $data = $registros->map(function ($item): array {
            $denominacion = $item->nombre ?: $item->agrupamiento ?: $item->clave_origen;

            return [
                'id' => (int) $item->id,
                'clave' => (string) $item->clave_origen,
                'nombre' => (string) $denominacion,
                'descripcion' => (string) $denominacion,
            ];
        })->values();

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Registra un nuevo expediente de solicitud de credencialización.
     */
    public function store(
        StoreSolicitudCredencialRequest $request,
        SolicitudTarjetaSequenceService $sequenceService
    ): RedirectResponse {
        try {
            $solicitud = DB::transaction(function () use ($request, $sequenceService): SolicitudCredencial {
                $catId = $request->validated('catalogo_organizacional_id');
                $areaClave = null;
                $areaSolicitante = $request->validated('area_solicitante');

                if (! empty($catId)) {
                    $catalogo = LocCatalogoOrganizacional::vigente()->find($catId);
                    if (! $catalogo) {
                        throw ValidationException::withMessages([
                            'catalogo_organizacional_id' => ['El área seleccionada no existe, no está activa o no pertenece al catálogo organizacional vigente.'],
                        ]);
                    }

                    $catId = $catalogo->id;
                    $areaClave = $catalogo->clave_origen;
                    // Congela autoritativamente la denominación del catálogo vigente
                    $areaSolicitante = $catalogo->nombre ?: $catalogo->agrupamiento ?: $catalogo->clave_origen;
                } else {
                    $catId = null;
                    $areaClave = null;
                    $areaSolicitante = $areaSolicitante !== null && trim((string) $areaSolicitante) !== ''
                        ? trim((string) $areaSolicitante)
                        : null;
                }

                // Generación atómica del número de tarjeta y consecutivo dentro de la transacción
                $secuencia = $sequenceService->generateNextNumber();

                return SolicitudCredencial::create([
                    'numero_tarjeta' => $secuencia['numero_tarjeta'],
                    'consecutivo_tarjeta' => $secuencia['consecutivo_tarjeta'],
                    'numero_oficio' => trim((string) $request->validated('numero_oficio')),
                    'fecha_recepcion' => $request->validated('fecha_recepcion'),
                    'catalogo_organizacional_id' => $catId,
                    'area_solicitante_clave' => $areaClave,
                    'area_solicitante' => $areaSolicitante,
                    'observaciones' => $request->validated('observaciones'),
                    'usuario_responsable_id' => $request->user()?->id ?: null,
                    'estado_general' => 'recibida',
                    'total_elementos' => 0,
                    'total_aptos' => 0,
                    'total_descartados' => 0,
                ]);
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (QueryException $e) {
            // Manejo controlado en caso de colisión concurrente de claves únicas (ej. oficio duplicado)
            if ($e->getCode() === '23000') {
                return back()
                    ->withInput()
                    ->withErrors(['numero_oficio' => 'El número de oficio ya fue registrado en otro expediente.']);
            }
            throw $e;
        }

        return redirect()
            ->route('credencializacion.detalle', $solicitud->id)
            ->with('status', "Expediente con Oficio {$solicitud->numero_oficio} y Tarjeta {$solicitud->numero_tarjeta} creado exitosamente.");
    }

    /**
     * Muestra el detalle de una solicitud y sus elementos importados con soporte para filtros de la Fase 2E y respuestas parciales AJAX.
     */
    public function show(FilterSolicitudElementosRequest $request, string $solicitud): View|JsonResponse
    {
        $solicitudModel = SolicitudCredencial::query()
            ->when(is_numeric($solicitud), fn ($q) => $q->where('id', (int) $solicitud))
            ->when(! is_numeric($solicitud), fn ($q) => $q->where('numero_oficio', $solicitud)->orWhere('numero_tarjeta', $solicitud))
            ->first();

        // Si se llama con un identificador de referencia o demo que no existe en BD
        if ($solicitudModel === null) {
            if ($solicitud === 'referencia' || ! is_numeric($solicitud)) {
                return view('modules.credencializacion', [
                    'detail' => true,
                    'solicitud' => null,
                    'elementos' => collect(),
                    'filters' => [],
                ]);
            }

            abort(404, 'Expediente de solicitud no encontrado.');
        }

        $catalogoActivo = LocCatalogoOrganizacional::query()
            ->where('activo', true)
            ->get(['id', 'clave_origen', 'nombre', 'agrupamiento', 'coordinacion']);

        $filters = $request->activeFilters();

        $elementosQuery = $solicitudModel->elementos()
            ->with([
                'persona',
                'armaCorta',
                'armaLarga',
            ])
            ->filterBy($filters, $catalogoActivo);

        $elementos = $elementosQuery
            ->orderBy('numero_fila')
            ->paginate(50)
            ->withQueryString();

        if ($request->ajax() || $request->wantsJson()) {
            $hasActiveFilters = ! empty($filters) && count(array_filter($filters)) > 0;
            $totalCoincidentes = $elementos->total();
            $cardTitle = $hasActiveFilters
                ? "Elementos del expediente ({$totalCoincidentes} coincidentes de {$solicitudModel->total_elementos} registrados)"
                : "Elementos del expediente ({$solicitudModel->total_elementos} registrados)";
            $cardDesc = $hasActiveFilters
                ? "Visualizando resultados filtrados del expediente en servidor. Total: {$totalCoincidentes} elementos."
                : "Listado íntegro de filas cargadas desde el archivo Excel original, conservando registros con CUIP vacío o duplicado.";

            return response()->json([
                'table_html' => view('modules.credencializacion.partials.tabla-elementos', [
                    'solicitud' => $solicitudModel,
                    'elementos' => $elementos,
                    'catalogo' => $catalogoActivo,
                    'filters' => $filters,
                    'hasActiveFilters' => $hasActiveFilters,
                ])->render(),
                'chips_html' => view('modules.credencializacion.partials.filtros-chips', [
                    'solicitud' => $solicitudModel,
                    'filters' => $filters,
                    'hasActiveFilters' => $hasActiveFilters,
                ])->render(),
                'total_coincidentes' => $totalCoincidentes,
                'total_registrados' => (int) $solicitudModel->total_elementos,
                'has_active_filters' => $hasActiveFilters,
                'title' => $cardTitle,
                'description' => $cardDesc,
            ]);
        }

        return view('modules.credencializacion', [
            'detail' => true,
            'solicitud' => $solicitudModel,
            'elementos' => $elementos,
            'catalogo' => $catalogoActivo,
            'filters' => $filters,
        ]);
    }

    /**
     * Carga el archivo Excel e importa los elementos asociados al expediente.
     */
    public function uploadElementos(
        UploadSolicitudElementosRequest $request,
        string $solicitud,
        SolicitudElementosImporter $importer
    ): RedirectResponse {
        $solicitudModel = is_numeric($solicitud)
            ? SolicitudCredencial::findOrFail((int) $solicitud)
            : SolicitudCredencial::where('numero_oficio', $solicitud)->orWhere('numero_tarjeta', $solicitud)->firstOrFail();

        try {
            $file = $request->file('archivo');
            if (! $file || ! $file->isValid()) {
                return back()->withErrors(['archivo' => 'El archivo no fue recibido correctamente por el servidor.']);
            }

            $result = $importer->import($solicitudModel, $file->getRealPath(), $request->user()?->id);
        } catch (Throwable $e) {
            return back()->withErrors(['archivo' => $e->getMessage()]);
        }

        return redirect()
            ->route('credencializacion.detalle', $solicitudModel->id)
            ->with('status', "Se importaron exitosamente {$result['total']} elementos al expediente.");
    }

    /**
     * Ejecuta el motor de prefiltro sobre los elementos importados en el expediente.
     */
    public function ejecutarPrefiltro(
        Request $request,
        string $solicitud,
        SolicitudPrefiltroService $prefiltroService
    ): RedirectResponse {
        $solicitudModel = is_numeric($solicitud)
            ? SolicitudCredencial::findOrFail((int) $solicitud)
            : SolicitudCredencial::where('numero_oficio', $solicitud)->orWhere('numero_tarjeta', $solicitud)->firstOrFail();

        $user = $request->user();
        abort_unless(
            $user && (
                $user->role === 'superadmin' ||
                ($solicitudModel->usuario_responsable_id !== null && (int) $solicitudModel->usuario_responsable_id === (int) $user->id)
            ),
            403,
            'No cuenta con autorización para dictaminar el prefiltro en este expediente.'
        );

        try {
            $resultado = $prefiltroService->evaluate($solicitudModel, $request->user());
        } catch (PrefiltroSourcesMissingException $e) {
            return back()->withErrors([
                'prefiltro' => 'No es posible ejecutar el prefiltro de credencialización: faltan las siguientes fuentes oficiales confirmadas: ' . implode(', ', $e->getMissingSources()) . '. Asegúrese de haber confirmado las importaciones oficiales en el módulo de Gestión de Información.',
            ]);
        } catch (Throwable $e) {
            return back()->withErrors([
                'prefiltro' => 'Ocurrió un error al procesar el prefiltro: ' . $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('credencializacion.detalle', $solicitudModel->id)
            ->with('status', "Prefiltro ejecutado exitosamente. {$resultado['total_elementos']} elementos evaluados: {$resultado['total_aptos']} aptos y {$resultado['total_descartados']} descartados.");
    }
}

