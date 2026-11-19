<?php

use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\ConsultaController;
use App\Http\Controllers\CredentialTemplateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CredencialPreparacionController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EntregaSeguimientoController;
use App\Http\Controllers\LocGestionController;
use App\Http\Controllers\LocWeaponsImportController;
use App\Http\Controllers\LocCredentialsImportController;
use App\Http\Controllers\LocCatalogoImportController;
use App\Http\Controllers\PrintQueueController;
use App\Http\Controllers\SolicitudCredencialController;
use App\Http\Middleware\LocalDemoAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
});
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::get('/dashboard', DashboardController::class)
    ->middleware([LocalDemoAccess::class, 'auth'])
    ->name('dashboard');

Route::middleware([LocalDemoAccess::class, 'auth', 'can:access-administration'])->group(function () {
    Route::get('/gestion', [LocGestionController::class, 'index'])->name('gestion');
    Route::get('/gestion/historial', [LocGestionController::class, 'history'])->name('gestion.historial');
    Route::post('/gestion/importaciones/personal-loc', [LocGestionController::class, 'upload'])
        ->name('gestion.importaciones.subir');
    Route::post('/gestion/importaciones/armamento-loc', [LocWeaponsImportController::class, 'upload'])
        ->name('gestion.armamento.importaciones.subir');
    Route::get('/gestion/importaciones/armamento-loc/{batch}/progreso', [LocWeaponsImportController::class, 'progress'])
        ->name('gestion.armamento.importaciones.progreso');
    Route::get('/gestion/importaciones/armamento-loc/{batch}/vista-previa', [LocWeaponsImportController::class, 'preview'])
        ->name('gestion.armamento.importaciones.preview');
    Route::post('/gestion/importaciones/armamento-loc/{batch}/confirmar', [LocWeaponsImportController::class, 'confirm'])
        ->name('gestion.armamento.importaciones.confirmar');
    Route::post('/gestion/importaciones/armamento-loc/{batch}/omitir-fila/{row}', [LocWeaponsImportController::class, 'omitRow'])
        ->name('gestion.armamento.importaciones.omitir');
    Route::post('/gestion/importaciones/vigencias-loc', [LocCredentialsImportController::class, 'upload'])
        ->name('gestion.vigencias.importaciones.subir');
    Route::get('/gestion/importaciones/vigencias-loc/{batch}/progreso', [LocCredentialsImportController::class, 'progress'])
        ->name('gestion.vigencias.importaciones.progreso');
    Route::get('/gestion/importaciones/vigencias-loc/{batch}/vista-previa', [LocCredentialsImportController::class, 'preview'])
        ->name('gestion.vigencias.importaciones.preview');
    Route::post('/gestion/importaciones/vigencias-loc/{batch}/confirmar', [LocCredentialsImportController::class, 'confirm'])
        ->name('gestion.vigencias.importaciones.confirmar');
    Route::post('/gestion/importaciones/vigencias-loc/{batch}/omitir-fila/{row}', [LocCredentialsImportController::class, 'omitRow'])
        ->name('gestion.vigencias.importaciones.omitir');
    Route::post('/gestion/importaciones/catalogo-loc', [LocCatalogoImportController::class, 'upload'])
        ->name('gestion.catalogo.importaciones.subir');
    Route::get('/gestion/importaciones/catalogo-loc/{batch}/progreso', [LocCatalogoImportController::class, 'progress'])
        ->name('gestion.catalogo.importaciones.progreso');
    Route::get('/gestion/importaciones/catalogo-loc/{batch}/vista-previa', [LocCatalogoImportController::class, 'preview'])
        ->name('gestion.catalogo.importaciones.preview');
    Route::post('/gestion/importaciones/catalogo-loc/{batch}/confirmar', [LocCatalogoImportController::class, 'confirm'])
        ->name('gestion.catalogo.importaciones.confirmar');
    Route::get('/gestion/catalogo/vigente', [LocCatalogoImportController::class, 'current'])
        ->name('gestion.catalogo.vigente');
    Route::get('/gestion/catalogo/versiones/{version}', [LocCatalogoImportController::class, 'showVersion'])
        ->name('gestion.catalogo.version');
    Route::get('/gestion/importaciones/{batch}/progreso', [LocGestionController::class, 'progress'])
        ->name('gestion.importaciones.progreso');
    Route::get('/gestion/importaciones/{batch}/vista-previa', [LocGestionController::class, 'preview'])
        ->name('gestion.importaciones.preview');
    Route::post('/gestion/importaciones/{batch}/confirmar', [LocGestionController::class, 'confirm'])
        ->name('gestion.importaciones.confirmar');
    Route::post('/gestion/importaciones/{batch}/omitir-fila/{row}', [LocGestionController::class, 'omitRow'])
        ->name('gestion.importaciones.omitir');
});

Route::middleware([LocalDemoAccess::class, 'auth', 'can:access-credencializacion'])->group(function () {
    Route::get('/credencializacion/solicitudes', [SolicitudCredencialController::class, 'index'])
        ->name('credencializacion');
    Route::get('/credencializacion/solicitudes/{solicitud}', [SolicitudCredencialController::class, 'show'])
        ->name('credencializacion.detalle');
    Route::get('/credencializacion/catalogo/buscar', [SolicitudCredencialController::class, 'buscarCatalogo'])
        ->name('credencializacion.catalogo.buscar');
    Route::post('/credencializacion/solicitudes', [SolicitudCredencialController::class, 'store'])
        ->name('credencializacion.store');
    Route::post('/credencializacion/solicitudes/{solicitud}/elementos', [SolicitudCredencialController::class, 'uploadElementos'])
        ->name('credencializacion.elementos.subir');
    Route::post('/credencializacion/solicitudes/{solicitud}/prefiltro', [SolicitudCredencialController::class, 'ejecutarPrefiltro'])
        ->name('credencializacion.prefiltro');
    Route::post('/credencializacion/solicitudes/{solicitud}/enviar-impresion', [SolicitudCredencialController::class, 'enviarAImpresion'])
        ->name('credencializacion.enviar-impresion');
});

Route::middleware([LocalDemoAccess::class, 'auth'])->group(function () {
    Route::get('/impresion', [PrintQueueController::class, 'index'])->name('impresion.index');
    Route::get('/impresion/cola', [PrintQueueController::class, 'index'])->name('impresion');
    Route::view('/impresion/biometricos', 'modules.impresion', ['section' => 'biometricos'])->name('impresion.biometricos');
    Route::get('/impresion/plantilla', [CredentialTemplateController::class, 'editor'])
        ->name('impresion.plantilla');
    Route::get('/impresion/plantilla/imprimir', [CredentialTemplateController::class, 'printPreview'])
        ->name('impresion.plantilla.imprimir');
    Route::get('/impresion/tarjetas/{solicitud}/elementos', [PrintQueueController::class, 'elementosPorTarjeta'])
        ->name('impresion.tarjeta.elementos');
    Route::post('/impresion/tarjetas/{solicitud}/validar-biometria', [PrintQueueController::class, 'validarBiometriaPorTarjeta'])
        ->name('impresion.tarjeta.validar-biometria');
    Route::get('/impresion/elementos/{elemento}/previsualizacion', [PrintQueueController::class, 'previsualizacionElemento'])
        ->name('impresion.elemento.previsualizacion');
    Route::get('/impresion/plantilla/elementos/{elemento}/previsualizacion', [CredentialTemplateController::class, 'elementPreview'])
        ->name('impresion.plantilla.elemento.previsualizacion');
    Route::get('/impresion/elementos/{elemento}/biometricos/{categoria}', [PrintQueueController::class, 'streamBiometrico'])
        ->name('impresion.elemento.biometrico.stream');
    Route::post('/impresion/elementos/{elemento}/actualizar-biometrico', [PrintQueueController::class, 'actualizarBiometrico'])
        ->name('impresion.elemento.actualizar-biometrico');
    Route::post('/impresion/elementos/{elemento}/captura-ticket', [PrintQueueController::class, 'ticketCaptura'])
        ->name('impresion.elemento.captura-ticket');
    Route::post('/impresion/elementos/{elemento}/emision', [PrintQueueController::class, 'registrarEmision'])
        ->name('impresion.elemento.emision');
    Route::post('/impresion/elementos/{elemento}/reimpresion', [PrintQueueController::class, 'registrarReimpresion'])
        ->name('impresion.elemento.reimpresion');

    // Fase 1B.2: preparación de impresión con snapshot histórico (biometría, plantilla y datos).
    Route::post('/impresion/elementos/{elemento}/preparar', [CredencialPreparacionController::class, 'preparar'])
        ->name('impresion.elemento.preparar');
    Route::get('/impresion/preparaciones/{preparacion}', [CredencialPreparacionController::class, 'mostrar'])
        ->whereUuid('preparacion')
        ->name('impresion.preparacion');
    Route::get('/impresion/preparaciones/{preparacion}/biometricos/{categoria}', [CredencialPreparacionController::class, 'biometrico'])
        ->whereUuid('preparacion')
        ->name('impresion.preparacion.biometrico');
    Route::get('/impresion/preparaciones/{preparacion}/evidencia/{categoria}', [CredencialPreparacionController::class, 'evidencia'])
        ->whereUuid('preparacion')
        ->name('impresion.preparacion.evidencia');
    Route::post('/impresion/preparaciones/{preparacion}/cancelar', [CredencialPreparacionController::class, 'cancelar'])
        ->whereUuid('preparacion')
        ->name('impresion.preparacion.cancelar');
    // Envío a la impresora y seguimiento de la cola de Windows (no confirman la impresión física).
    Route::post('/impresion/preparaciones/{preparacion}/trabajo', [CredencialPreparacionController::class, 'autorizarTrabajo'])
        ->whereUuid('preparacion')
        ->name('impresion.preparacion.trabajo');
    Route::post('/impresion/preparaciones/{preparacion}/trabajo/estado', [CredencialPreparacionController::class, 'estadoTrabajo'])
        ->whereUuid('preparacion')
        ->name('impresion.preparacion.trabajo.estado');
    Route::post('/impresion/impresiones/{impresion}/rectificar', [CredencialPreparacionController::class, 'rectificar'])
        ->whereNumber('impresion')
        ->name('impresion.impresion.rectificar');
    Route::post('/impresion/incidencias/{incidencia}/resolver', [CredencialPreparacionController::class, 'resolverIncidencia'])
        ->whereNumber('incidencia')
        ->name('impresion.incidencia.resolver');
});
Route::get('/impresion/plantilla/versiones', [CredentialTemplateController::class, 'versions'])
    ->middleware([LocalDemoAccess::class, 'auth', 'can:view-credential-template-versions'])
    ->name('impresion.plantilla.versiones');
Route::middleware([LocalDemoAccess::class, 'auth', 'can:manage-credential-templates'])->prefix('/impresion/plantilla')->name('impresion.plantilla.')->group(function () {
    Route::post('/', [CredentialTemplateController::class, 'save'])->name('guardar');
    Route::post('/recurso', [CredentialTemplateController::class, 'uploadResource'])->name('recurso.subir');
    Route::post('/restaurar/{version}', [CredentialTemplateController::class, 'restore'])->name('restaurar');
});
Route::get('/impresion/plantilla/recursos/{filename}', [CredentialTemplateController::class, 'resource'])
    ->where('filename', '[A-Za-z0-9_-]+\.(?:png|jpe?g|webp)')
    ->name('impresion.plantilla.recurso');
Route::post('/impresion/plantilla/aprobar/{version}', [CredentialTemplateController::class, 'approve'])
    ->middleware([LocalDemoAccess::class, 'auth', 'can:approve-credential-templates'])
    ->name('impresion.plantilla.aprobar');

// Entrega y Seguimiento: navegación por número de tarjeta (el expediente se identifica por el id de la solicitud).
Route::middleware([LocalDemoAccess::class, 'auth', 'can:access-entregas'])->group(function () {
    Route::get('/entregas', [EntregaSeguimientoController::class, 'index'])->name('entregas');
    Route::get('/entregas/tarjetas/{solicitud}/{seccion?}', [EntregaSeguimientoController::class, 'tarjeta'])
        ->whereNumber('solicitud')
        ->whereIn('seccion', array_keys(EntregaSeguimientoController::SECCIONES))
        ->name('entregas.tarjeta');
    Route::get('/entregas/tarjetas/{solicitud}/entregas/{entrega}', [EntregaSeguimientoController::class, 'detalle'])
        ->whereNumber(['solicitud', 'entrega'])
        ->name('entregas.entrega.detalle');
    // Expediente documental (Iteración 1.4): el archivo solo se sirve si pertenece a la tarjeta de la ruta.
    Route::get('/entregas/tarjetas/{solicitud}/documentos/{documento}/{modo}', [EntregaSeguimientoController::class, 'documentoArchivo'])
        ->whereNumber(['solicitud', 'documento'])
        ->whereIn('modo', ['ver', 'descargar'])
        ->name('entregas.documento.archivo');
    Route::post('/entregas/tarjetas/{solicitud}/documentos', [EntregaSeguimientoController::class, 'documentoStore'])
        ->whereNumber('solicitud')
        ->middleware('can:manage-delivery-documents')
        ->name('entregas.documento.store');

    // Registro de entregas físicas (Iteración 1.3): escritura con permiso propio, además del de consulta.
    Route::middleware('can:deliver-credentials')->prefix('/entregas/tarjetas/{solicitud}/entregas')->whereNumber('solicitud')->group(function () {
        Route::get('/nueva', [EntregaSeguimientoController::class, 'nueva'])->name('entregas.entrega.nueva');
        Route::post('/confirmar', [EntregaSeguimientoController::class, 'confirmar'])->name('entregas.entrega.confirmar');
        Route::post('/', [EntregaSeguimientoController::class, 'store'])->name('entregas.entrega.store');
    });
});

// Consultas (C0 + C1): búsqueda de solo lectura; throttle limita la enumeración por fuerza bruta.
Route::get('/consultas', [ConsultaController::class, 'index'])
    ->middleware([LocalDemoAccess::class, 'auth', 'can:access-consultas', 'throttle:60,1'])
    ->name('consultas');
Route::get('/consultas/elementos/{persona}/{seccion?}', [ConsultaController::class, 'elemento'])
    ->whereNumber('persona')
    ->whereIn('seccion', array_keys(\App\Services\ConsultaExpedienteService::SECCIONES))
    ->middleware([LocalDemoAccess::class, 'auth', 'can:access-consultas', 'throttle:60,1'])
    ->name('consultas.elemento');

Route::view('/reportes', 'modules.reportes')->name('reportes');

Route::middleware([LocalDemoAccess::class, 'auth'])->prefix('/administracion/dispositivos')->group(function () {
    Route::get('/', [DeviceController::class, 'index'])
        ->middleware('can:view-devices')
        ->name('administracion.dispositivos');
    Route::get('/crear', [DeviceController::class, 'create'])
        ->middleware('can:manage-devices')
        ->name('administracion.dispositivos.crear');
    Route::post('/', [DeviceController::class, 'store'])
        ->middleware('can:manage-devices')
        ->name('administracion.dispositivos.guardar');
    Route::get('/{device}/editar', [DeviceController::class, 'edit'])
        ->whereNumber('device')
        ->middleware('can:manage-devices')
        ->name('administracion.dispositivos.editar');
    Route::put('/{device}', [DeviceController::class, 'update'])
        ->whereNumber('device')
        ->middleware('can:manage-devices')
        ->name('administracion.dispositivos.actualizar');
    Route::patch('/{device}/estado', [DeviceController::class, 'updateStatus'])
        ->whereNumber('device')
        ->middleware('can:administer-devices')
        ->name('administracion.dispositivos.estado');
    Route::post('/{device}/diagnostico', [DeviceController::class, 'diagnose'])
        ->whereNumber('device')
        ->middleware('can:test-devices')
        ->name('administracion.dispositivos.diagnostico');
    Route::get('/{device}', [DeviceController::class, 'show'])
        ->whereNumber('device')
        ->middleware('can:view-devices')
        ->name('administracion.dispositivos.detalle');
});

Route::get('/administracion/{section?}', function (?string $section = 'usuarios') {
    $sections = ['usuarios', 'roles', 'catalogos', 'avisos', 'bitacora'];
    abort_unless(in_array($section, $sections, true), 404);

    return view('modules.administracion', ['section' => $section]);
})->middleware([LocalDemoAccess::class, 'auth', 'can:access-administration'])->name('administracion');

Route::view('/avisos', 'modules.avisos')->name('avisos');
Route::get('/perfil', function () {
    return view('modules.perfil', [
        'user' => Auth::guard('web')->user(),
    ]);
})->name('perfil');
