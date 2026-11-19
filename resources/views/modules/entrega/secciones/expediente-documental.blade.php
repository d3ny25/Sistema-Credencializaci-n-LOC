@php
    $categorias = \App\Enums\CategoriaDocumentoEntrega::opciones();
    $tonos = ['oficio_solicitud' => 'info', 'confirmacion_recepcion' => 'success', 'otros' => 'neutral'];
    $tamano = static fn (int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1).' MB' : number_format(max(1, (int) ceil($b / 1024))).' KB';
    $total = array_sum($conteosDocumentos);
    $seccionUrl = route('entregas.tarjeta', ['solicitud' => $tarjeta->id, 'seccion' => $seccion]);
    $columnas = ['Documento', 'Categoría', 'Incorporado', 'Tipo y tamaño', 'Acciones'];
    $maxMb = (int) (config('credencializacion.documentos.max_kb') / 1024);
@endphp

<p class="mb-4 rounded-md border border-inst-200 bg-inst-50 p-3 text-xs leading-5 text-inst-900" data-proposito="expediente-documental">
    Repositorio de documentos de esta tarjeta. Es evidencia complementaria: incorporar un documento <strong>no registra entregas ni cambia el estado</strong>
    de ninguna credencial. Los documentos no se editan ni se eliminan; una versión corregida se incorpora como un documento nuevo.
</p>

<section aria-labelledby="documentos-indicadores" class="mb-5">
    <h2 id="documentos-indicadores" class="sr-only">Documentos por categoría</h2>
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi label="Documentos registrados" :value="number_format($total)" hint="En el expediente de esta tarjeta" icon="file" tone="info"/>
        @foreach ($categorias as $valor => $etiqueta)
            <x-kpi :label="$etiqueta" :value="number_format($conteosDocumentos[$valor] ?? 0)" hint="Documentos de esta categoría" icon="file" :tone="$tonos[$valor] ?? 'neutral'"/>
        @endforeach
    </div>
    @if (($conteosDocumentos['oficio_solicitud'] ?? 0) === 0 && filled($tarjeta->numero_oficio))
        <p class="mt-3 text-xs text-tx-soft" data-oficio-sin-archivo>
            La solicitud registra el oficio <span class="font-mono font-semibold text-tx">{{ $tarjeta->numero_oficio }}</span>, pero Credencialización solo conserva su número, no el archivo.
            Para que forme parte del expediente, incorpórelo como «Oficio de solicitud».
        </p>
    @endif
</section>

@can('manage-delivery-documents')
    <x-card class="mb-5" title="Incorporar documento" icon="upload" :description="'PDF, JPG, PNG, DOCX o XLSX, hasta '.$maxMb.' MB. El usuario y la fecha y hora se registran automáticamente.'">
        <form method="POST" action="{{ route('entregas.documento.store', $tarjeta) }}" enctype="multipart/form-data" aria-label="Incorporar documento al expediente"
              x-data="{ categoria: @js(old('categoria', '')), descripcion: @js(old('descripcion', '')), archivo: null, tamano: '' }"
              onsubmit="this.querySelector('[type=submit]').disabled = true">
            @csrf
            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                <label class="field-label">
                    Categoría
                    <select name="categoria" required x-model="categoria">
                        <option value="">Seleccione…</option>
                        @foreach ($categorias as $valor => $etiqueta)
                            <option value="{{ $valor }}" @selected(old('categoria') === $valor)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field-label">
                    Nombre o descripción <span class="font-normal text-tx-soft" x-text="categoria === 'otros' ? '(obligatorio)' : '(opcional)'">(opcional)</span>
                    <input type="text" name="descripcion" value="{{ old('descripcion') }}" maxlength="200" autocomplete="off" x-model="descripcion" :required="categoria === 'otros'">
                </label>
                <label class="field-label">
                    Archivo
                    <input type="file" name="archivo" required accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx"
                           @change="archivo = $event.target.files[0] ?? null; tamano = archivo ? (archivo.size / 1048576).toFixed(2) + ' MB' : ''">
                </label>
            </div>

            {{-- Revisión previa: resumen de lo que se va a incorporar; el servidor vuelve a validar todo. --}}
            <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3 text-xs" x-show="archivo" x-cloak data-revision>
                <p class="font-semibold text-inst-900">Revise antes de incorporar</p>
                <dl class="mt-1 grid grid-cols-1 gap-1 sm:grid-cols-4">
                    <div><dt class="text-tx-soft">Tarjeta</dt><dd class="font-mono">{{ $numero }}</dd></div>
                    <div><dt class="text-tx-soft">Categoría</dt><dd x-text="@js($categorias)[categoria] ?? 'Sin seleccionar'"></dd></div>
                    <div><dt class="text-tx-soft">Archivo</dt><dd class="break-all" x-text="archivo?.name"></dd></div>
                    <div><dt class="text-tx-soft">Tamaño</dt><dd x-text="tamano"></dd></div>
                </dl>
                <label class="mt-2 flex items-start gap-2">
                    <input type="checkbox" name="confirmacion" value="1" class="mt-0.5">
                    <span>Confirmo que el documento corresponde a la tarjeta {{ $numero }} y que no podrá editarse ni eliminarse después.</span>
                </label>
            </div>

            <div class="mt-4 flex justify-end">
                <button type="submit" class="btn btn-primary"><x-icon name="upload" class="h-4 w-4"/> Incorporar documento</button>
            </div>
        </form>
    </x-card>
@endcan

<x-card title="Documentos del expediente" icon="file" description="Ordenados del más reciente al más antiguo.">
    <form method="GET" action="{{ $seccionUrl }}" class="panel-muted mb-4 p-4" role="search" aria-label="Buscar documentos">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto]">
            <label class="field-label">
                Nombre o descripción
                <input type="search" name="q" value="{{ $filtros['q'] ?? '' }}" maxlength="100" autocomplete="off">
            </label>
            <label class="field-label">
                Categoría
                <select name="categoria">
                    <option value="">Todas las categorías</option>
                    @foreach ($categorias as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected(($filtros['categoria'] ?? '') === $valor)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </label>
            <div class="flex items-end gap-2">
                <button type="submit" class="btn btn-primary flex-1">Buscar</button>
                <a href="{{ $seccionUrl }}" class="btn btn-secondary">Limpiar</a>
            </div>
        </div>
    </form>

    @if ($documentos->isNotEmpty())
        <div class="table-wrap">
            <table class="table-data">
                <caption class="sr-only">Documentos del expediente de la tarjeta {{ $numero }}</caption>
                <thead>
                    <tr>
                        @foreach ($columnas as $columna)
                            <th scope="col">{{ $columna }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($documentos as $documento)
                        <tr data-documento="{{ $documento->id }}">
                            <td class="max-w-[22rem]">
                                <span class="block break-words text-xs font-semibold text-tx">{{ $documento->descripcion ?: $documento->nombre_original }}</span>
                                @if ($documento->descripcion)
                                    <span class="block break-all text-2xs text-tx-soft">{{ $documento->nombre_original }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap"><x-status-badge :label="$documento->categoria->label()" :tone="$tonos[$documento->categoria->value] ?? 'neutral'" size="xs"/></td>
                            <td class="whitespace-nowrap text-xs">
                                <span class="block font-mono">{{ $documento->subido_at->format('d/m/Y H:i') }}</span>
                                <span class="block text-2xs text-tx-soft">{{ $documento->subidoPor?->name ?? '—' }}</span>
                            </td>
                            <td class="whitespace-nowrap text-xs">{{ strtoupper($documento->extension) }} · {{ $tamano($documento->tamano_bytes) }}</td>
                            <td class="whitespace-nowrap text-right">
                                @if ($documento->disponible)
                                    @if ($documento->previsualizable())
                                        <a href="{{ route('entregas.documento.archivo', [$tarjeta, $documento, 'ver']) }}" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">
                                            <x-icon name="eye" class="h-3.5 w-3.5"/> Ver
                                        </a>
                                    @endif
                                    <a href="{{ route('entregas.documento.archivo', [$tarjeta, $documento, 'descargar']) }}" class="btn btn-secondary btn-sm">Descargar</a>
                                @else
                                    <x-status-badge label="Archivo no disponible" tone="danger" size="xs"/>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($documentos->hasPages())
            <div class="mt-4">{{ $documentos->links() }}</div>
        @endif
    @else
        <x-empty-table :columns="$columnas" :message="match (true) {
            $total === 0 => 'El expediente de esta tarjeta aún no tiene documentos.',
            filled($filtros['q'] ?? null) => 'Ningún documento coincide con la búsqueda.',
            default => 'No hay documentos de la categoría seleccionada.',
        }"/>
    @endif
</x-card>
