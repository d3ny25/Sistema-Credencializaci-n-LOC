{{-- Métricas superiores del módulo de credencialización --}}
<div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <x-kpi label="Solicitudes registradas" :value="number_format($counts['total'] ?? 0)" hint="Expedientes en el sistema" icon="inbox"/>
    <x-kpi label="En proceso / Carga" :value="number_format($counts['en_revision'] ?? 0)" hint="Con elementos pendientes" icon="clock" tone="warning"/>
    <x-kpi label="Elementos aptos acumulados" :value="number_format($counts['aptos'] ?? 0)" hint="Dictamen prefiltro" icon="check" tone="success"/>
    <x-kpi label="Elementos descartados" :value="number_format($counts['descartados'] ?? 0)" hint="Dictamen prefiltro" icon="alert" tone="danger"/>
</div>
