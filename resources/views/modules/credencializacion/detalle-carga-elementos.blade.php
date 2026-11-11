{{-- Tarjeta de importación de elementos Excel para un expediente vacío --}}
<div class="mt-5">
    <x-card title="Carga de elementos (Archivo Excel)" description="Sube el archivo Excel oficial (.xlsx o .xls) con las solicitudes de los elementos. Se preservarán todas las filas recibidas.">
        <form method="POST" action="{{ route('credencializacion.elementos.subir', $solicitud->id) }}" enctype="multipart/form-data" class="space-y-4">
            @csrf

            <x-file-dropzone 
                name="archivo" 
                id="archivo_excel"
                label="Selecciona o arrastra el archivo Excel de elementos"
                help="Formatos admitidos: .xlsx, .xls (máximo 50.0 MB)"
                accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
                :allowed-extensions="['xlsx', 'xls']"
                :max-kilobytes="51200"
                button-text="Seleccionar archivo"
                required />

            <div class="rounded-md bg-blue-50 p-4 border border-blue-200">
                <h4 class="text-xs font-semibold text-blue-900">Columnas requeridas en el archivo:</h4>
                <ul class="mt-1 list-disc list-inside text-xs text-blue-800 space-y-0.5">
                    <li><strong>CUIP:</strong> Encabezado con 'CUIP' o 'Clave Única'. Filas sin CUIP serán conservadas como defectuosas.</li>
                    <li><strong>Armamento:</strong> Al menos una columna para arma corta ('Arma Corta', 'Matrícula Corta') o arma larga ('Arma Larga', 'Matrícula Larga').</li>
                    <li><strong>Datos complementarios (opcionales):</strong> Nombre, Categoría, Municipio, Adscripción.</li>
                </ul>
            </div>

            <div class="flex justify-end pt-2">
                <x-button type="submit" variant="primary">
                    Cargar e importar elementos
                </x-button>
            </div>
        </form>
    </x-card>
</div>

