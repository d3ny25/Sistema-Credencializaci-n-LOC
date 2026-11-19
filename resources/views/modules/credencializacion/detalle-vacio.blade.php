{{-- Vista de referencia o demostración cuando no se localiza el expediente --}}
<x-card title="Resumen de la solicitud" description="Vista de demostración o solicitud no localizada.">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => 'Número de tarjeta', 'value' => '—'],
            ['label' => 'Oficio', 'value' => '—'],
            ['label' => 'Fecha de recepción', 'value' => '—'],
            ['label' => 'Estado general', 'value' => 'Sin consultar'],
        ] as $field)
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                <dt class="text-xs text-tx-soft">{{ $field['label'] }}</dt>
                <dd class="mt-1 text-sm font-medium text-tx">{{ $field['value'] }}</dd>
            </div>
        @endforeach
    </div>
    <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
        @foreach (['Elementos recibidos' => 'users', 'Aptos' => 'check', 'Descartados' => 'alert'] as $label => $icono)
            <x-kpi :label="$label" value="—" :icon="$icono" tone="neutral" variant="compact"/>
        @endforeach
    </div>
</x-card>

<div class="mt-5">
    <x-card title="Elementos de la solicitud" description="Los motivos de rechazo y validaciones se mostrarán desde la evaluación persistida.">
        <x-empty-table :columns="['CUIP', 'Nombre', 'Categoría', 'Municipio', 'Adscripción', 'Datos solicitados', 'Resultado', 'Motivo', 'Estado']" message="No hay elementos visibles en este expediente de referencia."/>
    </x-card>
</div>

