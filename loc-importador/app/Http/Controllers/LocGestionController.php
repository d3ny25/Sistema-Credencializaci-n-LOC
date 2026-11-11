<?php

namespace App\Http\Controllers;

use App\Models\LocImportBatch;
use App\Models\LocImportStagingRow;
use App\Services\LocPersonalXlsImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class LocGestionController extends Controller
{
    public function index()
    {
        $batches = LocImportBatch::query()->latest('id')->paginate(15);

        return view('modules.gestion', [
            'section' => 'fuentes',
            'batches' => $batches,
        ]);
    }

    public function history()
    {
        $batches = LocImportBatch::query()->latest('id')->paginate(20);

        return view('modules.gestion', [
            'section' => 'historial',
            'batches' => $batches,
        ]);
    }

    public function upload(Request $request, LocPersonalXlsImporter $importer)
    {
        $request->validate([
            'archivo_loc' => ['required', 'file', 'mimes:xls', 'max:102400'],
        ], [
            'archivo_loc.mimes' => 'Selecciona un archivo Excel antiguo con extensión .xls.',
            'archivo_loc.max' => 'El archivo no puede superar los 100 MB.',
        ]);

        $file = $request->file('archivo_loc');
        $path = $file->store('loc-imports', 'local');

        $batch = LocImportBatch::create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => mb_substr($file->getClientOriginalName(), 0, 255),
            'fecha_procesamiento' => now(),
            'usuario_id' => $request->user()?->id,
            'estado' => 'procesando',
            'total_leidas' => 0,
            'total_aceptadas' => 0,
            'total_rechazadas' => 0,
            'total_actualizadas' => 0,
        ]);

        try {
            $counts = $importer->stage(Storage::disk('local')->path($path), $batch);

            return redirect()->route('gestion.importaciones.preview', $batch)
                ->with('success', 'Archivo analizado. Revisa el resumen antes de confirmar la importación.');
        } catch (Throwable $e) {
            $batch->update([
                'estado' => 'fallido',
                'resumen_errores' => json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
            ]);

            report($e);

            throw ValidationException::withMessages([
                'archivo_loc' => 'No fue posible analizar el archivo .xls. Verifica que no esté dañado y que la primera fila contenga los encabezados esperados.',
            ]);
        }
    }

    public function preview(LocImportBatch $batch)
    {
        abort_unless($batch->tipo_fuente === 'personal_autorizado_loc', 404);

        $counts = LocImportStagingRow::where('import_batch_id', $batch->id)
            ->selectRaw('accion, COUNT(*) as total')
            ->groupBy('accion')->pluck('total', 'accion');

        $rows = LocImportStagingRow::where('import_batch_id', $batch->id)
            ->orderBy('numero_fila')->paginate(25);

        return view('modules.gestion-import-preview', compact('batch', 'counts', 'rows'));
    }

    public function confirm(LocImportBatch $batch, LocPersonalXlsImporter $importer)
    {
        abort_unless($batch->tipo_fuente === 'personal_autorizado_loc', 404);

        try {
            $result = $importer->commit($batch);
        } catch (Throwable $e) {
            report($e);
            return back()->withErrors(['importacion' => $e->getMessage()]);
        }

        return redirect()->route('gestion.historial')
            ->with('success', "Importación terminada: {$result['inserted']} nuevas, {$result['updated']} actualizadas y {$result['unchanged']} sin cambios.");
    }
}
