@props([
    'batch',
    'progressUrl',
    'previewUrl',
    'cardTitle' => 'Estado del análisis',
    'cardDescription' => 'Los datos se actualizan automáticamente desde el lote en cola. El análisis no modifica los datos operativos hasta su confirmación.',
    'pendingTitle' => 'Análisis en cola',
    'processingTitle' => 'Analizando archivo…',
    'workerCommand' => null,
    'statusBadgeId' => null,
    'initialEstimatedRows' => null,
    'initialStage' => null,
    'id' => null,
])

@php
    $elementId = $id ?? ('import-progress-' . ($batch->id ?? 'monitor'));
    $isPending = ($batch->estado ?? '') === 'pendiente';
    $currentTitle = $isPending ? $pendingTitle : $processingTitle;
    $initialRows = (int) ($batch->total_leidas ?? 0);
    $estimatedRows = is_numeric($initialEstimatedRows) ? (int) $initialEstimatedRows : null;
@endphp

<x-card class="mb-5" :title="$cardTitle" :description="$cardDescription">
    <div id="{{ $elementId }}"
        class="space-y-4"
        role="status"
        aria-live="polite"
        data-progress-url="{{ $progressUrl }}"
        data-preview-url="{{ $previewUrl }}"
        data-worker-command="{{ $workerCommand }}"
        data-status-badge-id="{{ $statusBadgeId }}"
        data-pending-title="{{ $pendingTitle }}"
        data-processing-title="{{ $processingTitle }}">

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-start gap-3">
                {{-- Spinner animado para estado procesando --}}
                <svg data-monitor-spinner class="mt-0.5 h-5 w-5 shrink-0 animate-spin text-inst-700 {{ $isPending ? 'hidden' : '' }}" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                </svg>

                {{-- Icono de espera para estado pendiente --}}
                <svg data-monitor-queued class="mt-0.5 h-5 w-5 shrink-0 text-estado-adv {{ $isPending ? '' : 'hidden' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"/>
                    <path d="M12 7v5l3 2"/>
                </svg>

                <div>
                    <p data-monitor-title class="font-semibold text-inst-900">
                        {{ $currentTitle }}
                    </p>

                    <p data-monitor-detail class="mt-1 text-sm text-tx-soft">
                        @if ($isPending)
                            El trabajo espera un trabajador de cola. No se han aplicado cambios al padrón.
                        @else
                            Filas procesadas: {{ number_format($initialRows) }}{{ $estimatedRows ? ' de ' . number_format($estimatedRows) . ' estimadas' : '' }}. No se han aplicado cambios.
                        @endif
                    </p>

                    <p data-monitor-stage class="mt-1 text-xs text-tx-soft">
                        @if ($initialStage)
                            Etapa del servidor: {{ str($initialStage)->replace('_', ' ') }}.
                        @endif
                    </p>

                    <div data-monitor-warning class="mt-2 hidden rounded-md border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900">
                        <span data-monitor-warning-text></span>
                    </div>

                    <div data-monitor-error class="mt-2 hidden rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
                        <span data-monitor-error-text></span>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2 self-start sm:self-center">
                <button data-monitor-refresh type="button"
                    class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-inst-900 shadow-sm transition-colors hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-inst-700 focus:ring-offset-1 disabled:cursor-wait disabled:opacity-70">
                    <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                        <path d="M3 3v5h5"/>
                        <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/>
                        <path d="M16 21h5v-5"/>
                    </svg>
                    <span data-monitor-refresh-label>Consultar estado</span>
                </button>
            </div>
        </div>
    </div>
</x-card>

<script>
(() => {
    const panel = document.getElementById('{{ $elementId }}');
    if (!panel || panel.dataset.initialized) return;
    panel.dataset.initialized = 'true';

    const progressUrl = panel.dataset.progressUrl;
    const previewUrl = panel.dataset.previewUrl;
    const workerCommand = panel.dataset.workerCommand || '';
    const statusBadgeId = panel.dataset.statusBadgeId;
    const pendingTitle = panel.dataset.pendingTitle || 'Análisis en cola';
    const processingTitle = panel.dataset.processingTitle || 'Analizando archivo…';

    const titleEl = panel.querySelector('[data-monitor-title]');
    const detailEl = panel.querySelector('[data-monitor-detail]');
    const stageEl = panel.querySelector('[data-monitor-stage]');
    const spinnerEl = panel.querySelector('[data-monitor-spinner]');
    const queuedEl = panel.querySelector('[data-monitor-queued]');
    const warningBox = panel.querySelector('[data-monitor-warning]');
    const warningText = panel.querySelector('[data-monitor-warning-text]');
    const errorBox = panel.querySelector('[data-monitor-error]');
    const errorText = panel.querySelector('[data-monitor-error-text]');
    const refreshBtn = panel.querySelector('[data-monitor-refresh]');
    const refreshLabel = panel.querySelector('[data-monitor-refresh-label]');

    const statusBadge = statusBadgeId ? document.getElementById(statusBadgeId) : null;
    const terminalStatuses = ['en_revision', 'completado', 'fallido', 'fallido_parcial'];

    let timer = null;
    let isPolling = false;
    let consecutiveFailures = 0;

    const poll = async () => {
        if (isPolling) return;
        isPolling = true;

        if (refreshBtn) {
            refreshBtn.disabled = true;
            if (refreshLabel) refreshLabel.textContent = 'Consultando…';
        }

        try {
            const response = await fetch(progressUrl, {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });

            if (!response.ok) throw new Error('Respuesta inválida del servidor (' + response.status + ')');
            const result = await response.json();

            consecutiveFailures = 0;
            if (errorBox) errorBox.classList.add('hidden');

            const status = result.status;

            if (terminalStatuses.includes(status)) {
                window.location.assign(previewUrl);
                return;
            }

            const isPendingState = (status === 'pendiente');
            const rowsRead = Number(result.rows_read ?? 0);
            const rowsEstimated = result.rows_estimated ?? result.estimated_rows ?? null;
            const queuedSec = Number(result.queued_seconds ?? 0);
            const updatedSec = Number(result.updated_seconds ?? 0);

            if (spinnerEl) spinnerEl.classList.toggle('hidden', isPendingState);
            if (queuedEl) queuedEl.classList.toggle('hidden', !isPendingState);
            if (titleEl) titleEl.textContent = isPendingState ? pendingTitle : processingTitle;

            if (detailEl) {
                if (isPendingState) {
                    detailEl.textContent = 'El trabajo espera un trabajador de cola. No se han aplicado cambios al padrón.';
                } else {
                    const estText = rowsEstimated ? ` de ${Number(rowsEstimated).toLocaleString()} estimadas` : '';
                    detailEl.textContent = `Filas procesadas: ${rowsRead.toLocaleString()}${estText}. No se han aplicado cambios.`;
                }
            }

            if (stageEl) {
                stageEl.textContent = result.stage
                    ? `Etapa del servidor: ${String(result.stage).replace(/_/g, ' ')}.`
                    : '';
            }

            if (warningBox && warningText) {
                if (isPendingState && queuedSec >= 60) {
                    warningBox.classList.remove('hidden');
                    warningText.textContent = 'El análisis sigue en cola. Aún no hay un trabajador de cola que haya tomado el trabajo. ' +
                        (workerCommand ? `Comprueba que esté activo con: ${workerCommand}. ` : '') +
                        'No vuelvas a cargar el mismo archivo.';
                } else if (!isPendingState && updatedSec >= 120) {
                    warningBox.classList.remove('hidden');
                    warningText.textContent = `Sin actualización reciente. El lote continúa en estado ${status}; el servidor no lo actualiza desde hace al menos dos minutos. El trabajo podría seguir activo. No vuelvas a cargar el archivo.`;
                } else {
                    warningBox.classList.add('hidden');
                }
            }

            if (statusBadge) {
                const badgeMap = {
                    pendiente: 'Pendiente',
                    procesando: 'Procesando',
                    en_revision: 'En revisión',
                    completado: 'Terminado',
                    fallido: 'Error de análisis',
                    fallido_parcial: 'Análisis parcial'
                };
                if (badgeMap[status]) statusBadge.textContent = badgeMap[status];
            }

        } catch (err) {
            consecutiveFailures++;
            if (errorBox && errorText) {
                errorBox.classList.remove('hidden');
                errorText.textContent = 'No se pudo consultar el estado del análisis. El proceso en cola puede continuar; haz clic en "Consultar estado" para reintentar.';
            }
            if (consecutiveFailures >= 5) {
                isPolling = false;
                if (refreshBtn) {
                    refreshBtn.disabled = false;
                    if (refreshLabel) refreshLabel.textContent = 'Reintentar consulta';
                }
                return;
            }
        } finally {
            isPolling = false;
            if (refreshBtn) {
                refreshBtn.disabled = false;
                if (refreshLabel) refreshLabel.textContent = 'Consultar estado';
            }
            const delay = consecutiveFailures > 0 ? 5000 : 3000;
            timer = window.setTimeout(poll, delay);
        }
    };

    if (refreshBtn) {
        refreshBtn.addEventListener('click', () => {
            if (timer) window.clearTimeout(timer);
            poll();
        });
    }

    timer = window.setTimeout(poll, 1500);
})();
</script>

