{{-- Métricas superiores del módulo de credencialización --}}
<div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-card">
        <p class="text-xs text-tx-soft font-medium">Solicitudes registradas</p>
        <p class="mt-2 text-2xl font-bold text-inst-900">{{ $counts['total'] ?? 0 }}</p>
        <p class="mt-1 text-xs text-tx-soft">Expedientes en el sistema</p>
    </div>
    <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-card">
        <p class="text-xs text-tx-soft font-medium">En proceso / Carga</p>
        <p class="mt-2 text-2xl font-bold text-inst-700">{{ $counts['en_revision'] ?? 0 }}</p>
        <p class="mt-1 text-xs text-tx-soft">Con elementos pendientes</p>
    </div>
    <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-card">
        <p class="text-xs text-tx-soft font-medium">Elementos aptos acumulados</p>
        <p class="mt-2 text-2xl font-bold text-estado-exito">{{ $counts['aptos'] ?? 0 }}</p>
        <p class="mt-1 text-xs text-tx-soft">Dictamen prefiltro</p>
    </div>
    <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-card">
        <p class="text-xs text-tx-soft font-medium">Elementos descartados</p>
        <p class="mt-2 text-2xl font-bold text-estado-error">{{ $counts['descartados'] ?? 0 }}</p>
        <p class="mt-1 text-xs text-tx-soft">Dictamen prefiltro</p>
    </div>
</div>

