<?php
// Integra estas rutas en routes/web.php dentro del middleware de autenticación que ya use tu aplicación.
// No registres este archivo aparte salvo que adaptes RouteServiceProvider.

use App\Http\Controllers\LocGestionController;
use Illuminate\Support\Facades\Route;

Route::get('/gestion', [LocGestionController::class, 'index'])->name('gestion');
Route::get('/gestion/historial', [LocGestionController::class, 'history'])->name('gestion.historial');
Route::post('/gestion/importaciones/personal-loc', [LocGestionController::class, 'upload'])
    ->name('gestion.importaciones.subir');
Route::get('/gestion/importaciones/{batch}/vista-previa', [LocGestionController::class, 'preview'])
    ->name('gestion.importaciones.preview');
Route::post('/gestion/importaciones/{batch}/confirmar', [LocGestionController::class, 'confirm'])
    ->name('gestion.importaciones.confirmar');
