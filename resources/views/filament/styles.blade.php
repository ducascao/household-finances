{{-- Estilos das telas próprias do app. O CSS do Filament só traz as classes dele (fi-*), então as nossas ficam aqui (fc-*). --}}
<style>
    .fc-cards { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); }
    .fc-stack { display: grid; gap: 0.75rem; }
    .fc-row { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
    .fc-row-start { display: flex; align-items: center; gap: 0.5rem; }
    .fc-grow { flex: 1; min-width: 0; }
    .fc-truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .fc-label { font-size: 0.875rem; color: var(--gray-500); }
    .fc-hint { font-size: 0.875rem; color: var(--gray-500); margin-top: 0.25rem; }
    .fc-small { font-size: 0.75rem; color: var(--gray-500); }
    .fc-text { font-size: 0.875rem; }
    .fc-strong { font-weight: 600; }
    .fc-value { font-size: 1.5rem; line-height: 2rem; font-weight: 600; font-variant-numeric: tabular-nums; margin-top: 0.25rem; }
    .fc-num { font-variant-numeric: tabular-nums; }
    .fc-right { text-align: right; }
    .fc-positive { color: var(--success-600); }
    .fc-negative { color: var(--danger-600); }
    .fc-warning { color: var(--warning-600); }
    .fc-list > * + * { border-top: 1px solid var(--gray-200); }
    .fc-list > * { padding: 0.5rem 0; }
    .fc-dl { display: grid; gap: 0.75rem; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); }
    .fc-mt { margin-top: 1rem; }
    .fc-track { height: 0.5rem; border-radius: 9999px; background: var(--gray-200); overflow: hidden; }
    .fc-track-lg { height: 0.75rem; }
    .fc-bar { height: 100%; border-radius: 9999px; }
    .fc-code { font-family: ui-monospace, monospace; font-size: 0.8125rem; }
    .dark .fc-label, .dark .fc-hint, .dark .fc-small { color: var(--gray-400); }
    .dark .fc-list > * + * { border-top-color: rgb(255 255 255 / 0.1); }
    .dark .fc-track { background: rgb(255 255 255 / 0.1); }
    .dark .fc-positive { color: var(--success-400); }
    .dark .fc-negative { color: var(--danger-400); }
    .dark .fc-warning { color: var(--warning-400); }
</style>
