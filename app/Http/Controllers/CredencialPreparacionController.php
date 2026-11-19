<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BiometricoCategoria;
use App\Models\BiometricoIncidencia;
use App\Models\CredencialImpresion;
use App\Models\CredencialPreparacion;
use App\Models\SolicitudElemento;
use App\Services\CredencialPreparacionService;
use App\Services\TrabajoImpresionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fase 1B.2 — Preparación de impresión con snapshot histórico.
 * Abrir o recargar una vista nunca crea preparaciones: solo el POST explícito "preparar".
 */
class CredencialPreparacionController extends Controller
{
    public function __construct(private readonly CredencialPreparacionService $servicio) {}

    public function preparar(Request $request, SolicitudElemento $elemento): RedirectResponse|JsonResponse
    {
        $this->autorizarImpresion($request, $elemento);
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(['emision', 'reimpresion'])],
            'template_version' => ['nullable', 'string', 'max:20'],
        ]);
        $tipo = $datos['tipo'] === 'reimpresion' ? CredencialPreparacion::TIPO_REIMPRESION_HISTORICA : CredencialPreparacion::TIPO_EMISION;

        try {
            $preparacion = $this->servicio->preparar($elemento, $tipo, $request->user(), $datos['template_version'] ?? null);
        } catch (HttpException $e) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
            }

            return back()->withErrors(['preparacion' => $e->getMessage()]);
        }

        $url = route('impresion.preparacion', $preparacion);

        return $request->expectsJson()
            ? response()->json(['success' => true, 'uuid' => $preparacion->uuid, 'url' => $url])
            : redirect()->to($url);
    }

    public function mostrar(Request $request, CredencialPreparacion $preparacion): View
    {
        $elemento = $preparacion->elemento()->with(['solicitud', 'credencialEmitida'])->firstOrFail();
        $this->autorizarImpresion($request, $elemento);
        $this->servicio->expirarSiCorresponde($preparacion);
        $preparacion->refresh()->load('impresion');

        $biometricUrls = [];
        if ($preparacion->vistaVigente()) {
            foreach (CredencialPreparacionService::CAMPOS_BIOMETRICOS as $campo => $categoria) {
                $biometricUrls[$campo] = route('impresion.preparacion.biometrico', ['preparacion' => $preparacion, 'categoria' => $categoria->value]);
            }
        }

        $extemporaneaHasta = $preparacion->primera_vista_at?->copy()->addHours((int) config('credencializacion.impresion.extemporanea_horas', 72));

        return view('modules.credential-template-print', [
            'templateConfig' => $this->servicio->plantilla($preparacion),
            'templateVersion' => $preparacion->plantilla_version,
            'selectedTemplateVersion' => $preparacion->plantilla_version,
            'fieldLabels' => config('credential-templates.fields'),
            'demoData' => $this->servicio->datosVariables($preparacion),
            'isRealElement' => true,
            'biometricUrls' => $biometricUrls,
            'elementoReal' => $elemento,
            'preparacion' => $preparacion,
            'extemporaneaPermitida' => ! $preparacion->estaPreparada()
                && $preparacion->impresion === null
                && $extemporaneaHasta !== null
                && now()->lte($extemporaneaHasta),
            'puedeRegistrarImpresion' => (bool) $request->user()?->can('print-credentials'),
            'agenteEstacionUrl' => config('devices.agente_estacion.url'),
            'colaImpresora' => config('devices.agente_estacion.cola_impresora'),
        ]);
    }

    public function biometrico(Request $request, CredencialPreparacion $preparacion, string $categoria): Response
    {
        $elemento = $preparacion->elemento()->firstOrFail();
        $this->autorizarImpresion($request, $elemento);

        return $this->responderImagen($preparacion, $categoria, $request, false);
    }

    public function evidencia(Request $request, CredencialPreparacion $preparacion, string $categoria): Response
    {
        abort_unless($request->user()?->can('view-biometric-evidence'), 403, 'No cuenta con autorización para consultar evidencia biométrica histórica.');

        return $this->responderImagen($preparacion, $categoria, $request, true);
    }

    public function cancelar(Request $request, CredencialPreparacion $preparacion): RedirectResponse|JsonResponse
    {
        $elemento = $preparacion->elemento()->firstOrFail();
        $this->autorizarImpresion($request, $elemento);
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:250']]);

        $this->servicio->cancelar($preparacion, $request->user(), $datos['motivo']);

        return $request->expectsJson()
            ? response()->json(['success' => true, 'estado' => CredencialPreparacion::CANCELADA])
            : redirect()->route('impresion.preparacion', $preparacion)->with('status', 'La preparación se canceló; su evidencia se conserva.');
    }

    public function rectificar(Request $request, CredencialImpresion $impresion): JsonResponse
    {
        abort_unless($request->user()?->can('supervise-print-evidence'), 403, 'No cuenta con autorización para rectificar resultados de impresión.');
        $datos = $request->validate([
            'resultado' => ['required', Rule::in([CredencialImpresion::RESULTADO_IMPRESA, CredencialImpresion::RESULTADO_FALLIDA])],
            'motivo' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $rectificacion = $this->servicio->rectificar($impresion, $datos['resultado'], $datos['motivo'], $request->user());

        return response()->json([
            'success' => true,
            'rectificacion_id' => $rectificacion->id,
            'resultado_vigente' => $rectificacion->resultado_rectificado,
            'message' => 'Rectificación registrada. El registro original se conserva; cualquier efecto sobre el estado de la credencial requiere el procedimiento institucional.',
        ]);
    }

    public function resolverIncidencia(Request $request, BiometricoIncidencia $incidencia): JsonResponse
    {
        abort_unless($request->user()?->can('supervise-print-evidence'), 403, 'No cuenta con autorización para resolver incidencias de impresión.');
        $datos = $request->validate(['resolucion' => ['required', 'string', 'min:10', 'max:1000']]);

        $this->servicio->resolverIncidencia($incidencia, $datos['resolucion'], $request->user());

        return response()->json(['success' => true, 'message' => 'Incidencia resuelta y registrada.']);
    }

    private function responderImagen(CredencialPreparacion $preparacion, string $categoria, Request $request, bool $historico): Response
    {
        $categoriaEnum = BiometricoCategoria::tryFrom($categoria);
        abort_if($categoriaEnum === null, 404);

        $imagen = $this->servicio->biometrico($preparacion, $categoriaEnum, $request->user(), $historico);

        return response($imagen['bytes'], 200, [
            'Content-Type' => $imagen['mime'],
            'Content-Length' => (string) strlen($imagen['bytes']),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="evidencia.'.$imagen['formato'].'"',
        ]);
    }

    /**
     * Las mismas condiciones que la impresión real: ver biométricos del elemento y registrar impresiones.
     */
    /**
     * Autoriza el envío de esta preparación a la impresora y devuelve el identificador del documento (y el ticket
     * para el agente de estación). No marca nada como impreso.
     */
    public function autorizarTrabajo(Request $request, CredencialPreparacion $preparacion, TrabajoImpresionService $trabajos): JsonResponse
    {
        $elemento = $preparacion->elemento()->with(['solicitud', 'credencialEmitida'])->firstOrFail();
        $this->autorizarImpresion($request, $elemento);
        $datos = $request->validate(['motivo_reenvio' => ['nullable', 'string', 'min:10', 'max:500']]);

        $previos = $trabajos->enviosPrevios($preparacion);
        if ($previos > 0 && blank($datos['motivo_reenvio'] ?? null)) {
            return response()->json([
                'success' => false,
                'codigo' => 'trabajo_previo',
                'envios_previos' => $previos,
                'message' => 'Esta preparación ya se envió a imprimir. Revise la impresora y la tarjeta; si debe reenviarla, justifique el motivo.',
            ], 409);
        }

        try {
            $resultado = $trabajos->autorizar($preparacion, $request->user(), $datos['motivo_reenvio'] ?? null);
        } catch (HttpException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['success' => true, ...$resultado]);
    }

    /** Estado del trabajo en la cola de Windows informado por la estación (auditoría; no confirma la impresión). */
    public function estadoTrabajo(Request $request, CredencialPreparacion $preparacion, TrabajoImpresionService $trabajos): JsonResponse
    {
        $elemento = $preparacion->elemento()->with(['solicitud', 'credencialEmitida'])->firstOrFail();
        $this->autorizarImpresion($request, $elemento);
        $datos = $request->validate([
            'trabajo' => ['required', 'string', 'regex:/^[0-9a-f]{32}$/'],
            'estado' => ['required', Rule::in(TrabajoImpresionService::ESTADOS)],
            'job_id' => ['nullable', 'integer', 'min:0'],
            'cola' => ['nullable', 'string', 'max:120'],
            'alertas' => ['nullable', 'array', 'max:12'],
            'alertas.*' => ['string', 'regex:/^[a-z_]{1,40}$/'],
            'simulacion' => ['nullable', 'boolean'],
        ]);

        try {
            $registrado = $trabajos->registrarEstado($preparacion, $request->user(), $datos['trabajo'], $datos);
        } catch (HttpException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['success' => true, 'registrado' => $registrado]);
    }

    private function autorizarImpresion(Request $request, SolicitudElemento $elemento): void
    {
        abort_unless(
            $request->user()?->can('viewPreview', $elemento) && $request->user()->can('print-credentials'),
            403,
            'No cuenta con autorización para imprimir esta credencial.'
        );
    }
}
