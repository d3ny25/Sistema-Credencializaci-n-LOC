<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CategoriaDocumentoEntrega;
use App\Http\Requests\FiltrarTarjetasEntregaRequest;
use App\Http\Requests\IncorporarDocumentoEntregaRequest;
use App\Http\Requests\RegistrarEntregaRequest;
use App\Models\CredencialDocumento;
use App\Models\CredencialEntrega;
use App\Models\CredencialImpresion;
use App\Models\SolicitudCredencial;
use App\Services\CredencialEntregaService;
use App\Services\ExpedienteDocumentalService;
use App\Services\SeguimientoTarjetaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Entrega y Seguimiento de Credenciales: localizar un número de tarjeta y consultar su avance (1.1), sus bandejas
 * de credenciales impresas y pendientes (1.2) y registrar sus entregas físicas (1.3). Solo el registro de entregas
 * escribe, a través de CredencialEntregaService; nada cambia emisiones ni impresiones.
 */
class EntregaSeguimientoController extends Controller
{
    public const FILTROS_RECIBIDAS = [
        'varias' => 'Con más de una impresión efectiva',
        'reimpresion' => 'Con reimpresión histórica efectiva',
        'rectificadas' => 'Con intentos rectificados',
    ];

    public const CATEGORIAS_PENDIENTES = [
        'biometricos' => 'Pendientes de biométricos',
        'impresion' => 'Pendientes de impresión',
        'loc' => 'Pendientes de conciliación LOC',
    ];

    /** Secciones del expediente; implementadas: panel (1.1), credenciales recibidas y pendientes (1.2), entregas (1.3). */
    public const SECCIONES = [
        'panel' => 'Panel',
        'credenciales-recibidas' => 'Credenciales recibidas',
        'pendientes' => 'Pendientes',
        'entregas' => 'Entregas',
        'retenidas' => 'Retenidas',
        'expediente-documental' => 'Expediente documental',
        'conciliacion-loc' => 'Conciliación LOC',
        'historial' => 'Historial',
    ];

    public function __construct(
        private readonly SeguimientoTarjetaService $seguimiento,
        private readonly CredencialEntregaService $entregas,
        private readonly ExpedienteDocumentalService $documentos,
    ) {}

    public function index(FiltrarTarjetasEntregaRequest $request): View|JsonResponse
    {
        $filtros = $request->validated();
        $tarjetas = $this->seguimiento->listado($filtros);

        return $this->responder($request, view('modules.entrega', [
            'tarjetas' => $tarjetas,
            'filtros' => $filtros,
            'indicadores' => $this->seguimiento->indicadoresGenerales(),
            'seguimiento' => $this->seguimiento,
        ]));
    }

    public function tarjeta(Request $request, SolicitudCredencial $solicitud, string $seccion = 'panel'): View|JsonResponse
    {
        $datos = $this->expediente($request, $solicitud, $seccion);
        $tarjeta = $datos['tarjeta'];

        if ($seccion === 'panel') {
            $etapa = $request->query('etapa');
            $elementos = $tarjeta->elementos->map(fn ($e) => ['elemento' => $e, 'etapa' => $this->seguimiento->etapaElemento($e), 'impresas' => $this->seguimiento->impresionesEfectivas($e)]);
            if (is_string($etapa) && $etapa !== '') {
                $elementos = $elementos->where('etapa', $etapa)->values();
            }
            $datos['cronologia'] = $this->seguimiento->cronologia($tarjeta, $datos['resumen']);
            $datos['etapaFiltro'] = is_string($etapa) ? $etapa : '';
            $datos['elementos'] = $this->paginar($elementos, $request);
        }

        if (in_array($seccion, ['credenciales-recibidas', 'pendientes'], true)) {
            $recibidas = $seccion === 'credenciales-recibidas';
            $filtros = $request->validate([
                'q' => ['nullable', 'string', 'max:100'],
                'filtro' => ['nullable', 'in:'.implode(',', array_keys($recibidas ? self::FILTROS_RECIBIDAS : self::CATEGORIAS_PENDIENTES))],
            ]);
            $filas = $recibidas ? $this->seguimiento->credencialesRecibidas($tarjeta) : $this->seguimiento->pendientes($tarjeta);
            $datos['conteos'] = $recibidas
                ? ['elementos' => $filas->count(), 'impresiones_fisicas' => $filas->sum('efectivas'), 'intentos' => $filas->sum(fn (array $f): int => $f['intentos']->count()), 'multiples' => $filas->where('efectivas', '>', 1)->count()]
                : $filas->countBy('categoria')->all();
            $datos['filtros'] = $filtros;
            $datos['opcionesFiltro'] = $recibidas ? self::FILTROS_RECIBIDAS : self::CATEGORIAS_PENDIENTES;
            $datos['sinFiltrar'] = $filas->count();
            $datos['filas'] = $this->paginar($this->filtrar($filas, $filtros), $request);
        }

        if ($seccion === 'entregas') {
            $operaciones = CredencialEntrega::query()->where('solicitud_id', $tarjeta->id)
                ->with('entregadoPor:id,name')->withCount('detalles')
                ->orderByDesc('entregada_at')->orderByDesc('id')
                ->paginate(25, ['*'], 'pagina')->withQueryString();
            $datos['operaciones'] = $operaciones;
            $datos['conteosEntrega'] = [
                'operaciones' => $operaciones->total(),
                'fisicas' => $tarjeta->elementos->flatMap(fn ($e) => $e->credencialEmitida?->impresiones ?? [])
                    ->filter(static fn (CredencialImpresion $i): bool => $i->entregaDetalle !== null)->count(),
            ];
        }

        if ($seccion === 'expediente-documental') {
            $filtros = $request->validate([
                'q' => ['nullable', 'string', 'max:100'],
                'categoria' => ['nullable', Rule::enum(CategoriaDocumentoEntrega::class)],
            ]);
            $base = CredencialDocumento::query()->where('solicitud_id', $tarjeta->id);
            $datos['conteosDocumentos'] = (clone $base)->toBase()->selectRaw('categoria, count(*) as total')->groupBy('categoria')->pluck('total', 'categoria')->map(fn ($n) => (int) $n)->all();
            $documentos = $base->with('subidoPor:id,name')
                ->when($filtros['categoria'] ?? null, fn ($q, string $c) => $q->where('categoria', $c))
                ->when($filtros['q'] ?? null, fn ($q, string $t) => $q->where(fn ($w) => $w
                    ->where('nombre_original', 'like', '%'.addcslashes($t, '%_\\').'%')
                    ->orWhere('descripcion', 'like', '%'.addcslashes($t, '%_\\').'%')))
                ->orderByDesc('subido_at')->orderByDesc('id')
                ->paginate(25, ['*'], 'pagina')->withQueryString();
            $documentos->getCollection()->each(fn (CredencialDocumento $d) => $d->setAttribute('disponible', $this->documentos->disponible($d)));
            $datos['documentos'] = $documentos;
            $datos['filtros'] = $filtros;
        }

        return $this->responder($request, view('modules.entrega.tarjeta', $datos));
    }

    /** Incorpora un documento al expediente de la tarjeta de la ruta (la tarjeta nunca viene del formulario). */
    public function documentoStore(IncorporarDocumentoEntregaRequest $request, SolicitudCredencial $solicitud): RedirectResponse
    {
        $seccion = route('entregas.tarjeta', ['solicitud' => $solicitud->id, 'seccion' => 'expediente-documental']);
        try {
            $documento = $this->documentos->incorporar($solicitud, $request->file('archivo'), $request->categoria(), $request->validated('descripcion'), $request->user());
        } catch (ValidationException $e) {
            return redirect($seccion)->withErrors($e->errors())->withInput($request->only('categoria', 'descripcion'));
        }

        return redirect($seccion)->with('status', "Documento incorporado: {$documento->nombre_original} ({$documento->categoria->label()}).");
    }

    /** Consulta (ver) o descarga de un documento, solo si pertenece a la tarjeta de la ruta. */
    public function documentoArchivo(Request $request, SolicitudCredencial $solicitud, CredencialDocumento $documento, string $modo): StreamedResponse
    {
        abort_unless($request->user()?->can('access-entregas'), 403, 'No cuenta con autorización para consultar el seguimiento de tarjetas.');
        abort_unless((int) $documento->solicitud_id === (int) $solicitud->id, 404);

        return $this->documentos->respuesta($documento, $modo === 'ver');
    }

    public function nueva(Request $request, SolicitudCredencial $solicitud): View|JsonResponse
    {
        $datos = $this->expediente($request, $solicitud, 'entregas');

        return $this->responder($request, view('modules.entrega.tarjeta', [...$datos, 'vista' => 'nueva', 'opciones' => $this->entregas->opciones($datos['tarjeta'])]));
    }

    /** Resumen previo: valida la selección contra el estado actual sin guardar y genera la clave de la operación. */
    public function confirmar(RegistrarEntregaRequest $request, SolicitudCredencial $solicitud): View|JsonResponse|RedirectResponse
    {
        $datos = $this->expediente($request, $solicitud, 'entregas');
        try {
            $seleccion = $this->entregas->seleccion($datos['tarjeta'], $request->impresionIds());
        } catch (ValidationException $e) {
            return $this->volverANueva($solicitud, $e);
        }

        return $this->responder($request, view('modules.entrega.tarjeta', [...$datos, 'vista' => 'confirmar', 'seleccion' => $seleccion,
            'receptor' => $request->validated('receptor_nombre'), 'clave' => (string) Str::uuid()]));
    }

    public function store(RegistrarEntregaRequest $request, SolicitudCredencial $solicitud): RedirectResponse
    {
        try {
            ['entrega' => $entrega, 'repetida' => $repetida] = $this->entregas->registrar($solicitud, $request->impresionIds(),
                $request->validated('receptor_nombre'), $request->validated('clave_operacion'), $request->user());
        } catch (ValidationException $e) {
            return $this->volverANueva($solicitud, $e);
        }

        return redirect()->route('entregas.entrega.detalle', [$solicitud, $entrega])->with('status', $repetida
            ? 'Esta entrega ya estaba registrada; no se duplicó.'
            : 'Entrega registrada: '.count($request->impresionIds()).' credencial(es) a '.$entrega->receptor_nombre.'.');
    }

    public function detalle(Request $request, SolicitudCredencial $solicitud, CredencialEntrega $entrega): View|JsonResponse
    {
        abort_unless((int) $entrega->solicitud_id === (int) $solicitud->id, 404);
        $datos = $this->expediente($request, $solicitud, 'entregas');
        // Tarjetas físicas de la operación, con su elemento, a partir de la tarjeta ya cargada (sin consultas extra).
        $porImpresion = $datos['tarjeta']->elementos->flatMap(fn ($e) => ($e->credencialEmitida?->impresiones ?? collect())
            ->map(static fn (CredencialImpresion $i): array => ['elemento' => $e, 'impresion' => $i]))->keyBy(fn (array $f): int => $f['impresion']->id);
        $entrega->load('entregadoPor:id,name', 'detalles:id,credencial_entrega_id,credencial_impresion_id');

        return $this->responder($request, view('modules.entrega.tarjeta', [...$datos, 'vista' => 'detalle', 'entrega' => $entrega,
            'incluidas' => $entrega->detalles->map(fn ($d): array => $porImpresion[$d->credencial_impresion_id])]));
    }

    /**
     * Navegación dinámica (mismo criterio que SolicitudCredencialController::show): ante una petición fetch se
     * devuelven las secciones ya renderizadas de la MISMA vista; sin ella, la página completa. La autorización y la
     * validación ya ocurrieron antes de llegar aquí, igual para ambas respuestas.
     */
    private function responder(Request $request, View $vista): View|JsonResponse
    {
        if (! ($request->ajax() || $request->wantsJson())) {
            return $vista;
        }
        $secciones = $vista->renderSections();

        return response()->json([
            'html' => $secciones['entrega'] ?? '',
            // document.title es texto plano: se decodifica lo que Blade escapó (el HTML del resto va a innerHTML).
            'titulo' => html_entity_decode(trim($secciones['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8').' · Sistema Estatal LOC 139',
            'encabezado' => trim($secciones['heading'] ?? ''),
            'subencabezado' => trim($secciones['subheading'] ?? ''),
            // El layout no se vuelve a pintar: los avisos de sesión (éxito o errores) viajan con el contenido.
            'avisos' => view('layouts.partials.avisos')->render(),
            'url' => $request->fullUrl(),
        ]);
    }

    /** Datos comunes del expediente de una tarjeta. */
    private function expediente(Request $request, SolicitudCredencial $solicitud, string $seccion): array
    {
        abort_unless($request->user()?->can('access-entregas'), 403, 'No cuenta con autorización para consultar el seguimiento de tarjetas.');

        $tarjeta = $this->seguimiento->cargarTarjeta($solicitud);
        $resumen = $this->seguimiento->resumen($tarjeta->elementos);

        return [
            'tarjeta' => $tarjeta,
            'resumen' => $resumen,
            'estado' => $this->seguimiento->estadoDerivado($tarjeta, $resumen),
            'seccion' => $seccion,
            'secciones' => self::SECCIONES,
            'vista' => null,
        ];
    }

    private function volverANueva(SolicitudCredencial $solicitud, ValidationException $e): RedirectResponse
    {
        return redirect()->route('entregas.entrega.nueva', $solicitud)->withErrors($e->errors())->withInput(request()->only('receptor_nombre', 'impresiones'));
    }

    /**
     * Filtros de la bandeja sobre los elementos ya cargados de la tarjeta: nunca amplían la consulta a otras tarjetas.
     *
     * @param  Collection<int, array<string, mixed>>  $filas
     */
    private function filtrar(Collection $filas, array $filtros): Collection
    {
        $texto = mb_strtolower(trim((string) ($filtros['q'] ?? '')));
        $filtro = $filtros['filtro'] ?? null;

        return $filas->filter(static function (array $f) use ($texto, $filtro): bool {
            $e = $f['elemento'];
            $coincide = $texto === '' || collect([$e->cuip, $e->persona?->nombre, $e->datos_solicitados['nombre'] ?? null, $e->credencialEmitida?->folio])
                ->filter()->contains(static fn ($valor): bool => str_contains(mb_strtolower((string) $valor), $texto));

            return $coincide && match ($filtro) {
                null, '' => true,
                'varias' => $f['efectivas'] > 1,
                'reimpresion' => $f['con_reimpresion'],
                'rectificadas' => $f['intentos']->contains('rectificada', true),
                default => $f['categoria'] === $filtro,
            };
        })->values();
    }

    /** Paginación sobre la colección ya cargada para el resumen: evita una segunda consulta de los mismos elementos. */
    private function paginar(Collection $filas, Request $request): LengthAwarePaginator
    {
        $pagina = max(1, (int) $request->query('pagina', 1));

        return (new LengthAwarePaginator($filas->forPage($pagina, 25)->values(), $filas->count(), 25, $pagina, [
            'path' => $request->url(),
            'pageName' => 'pagina',
        ]))->withQueryString();
    }
}
