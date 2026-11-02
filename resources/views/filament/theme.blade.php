{{--
    Tema do painel (E24), no estilo do Organizze: fundo cinza-claro, cards brancos arredondados com sombra suave,
    menu lateral branco com o item atual em verde claro e cards de resumo com ícone em círculo colorido.
    É CSS puro por cima das classes do Filament (fi-*), carregado depois do CSS dele: não precisa de build.
--}}
<style>
    :root {
        --radius-md: 0.5rem;
        --radius-lg: 0.75rem;
        --radius-xl: 1rem;
        --fc-bg: #f3f5f8;
        --fc-border: #e6e9ef;
        --fc-card-shadow: 0 1px 2px rgb(16 24 40 / 0.04), 0 6px 20px rgb(16 24 40 / 0.05);
    }

    /* Fundo, barra do topo e menu lateral */
    .fi-body { background-color: var(--fc-bg); }
    .fi-topbar { background-color: #fff; box-shadow: 0 1px 0 var(--fc-border); }
    .fi-sidebar-header { box-shadow: 0 1px 0 var(--fc-border) !important; }
    @media (min-width: 64rem) {
        .fi-sidebar { background-color: #fff; box-shadow: 1px 0 0 var(--fc-border); }
    }
    .fi-body .fi-sidebar .fi-sidebar-nav { padding-inline: 1rem 0.5rem; padding-block: 1.25rem; row-gap: 1.25rem; scrollbar-width: thin; }
    .fi-sidebar-group-label {
        font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--gray-400);
    }
    .fi-sidebar-item-btn { padding: 0.55rem 0.75rem; transition: background-color 0.15s; }
    .fi-sidebar-item-btn:hover { background-color: var(--gray-50); }
    .fi-sidebar-item-label { font-weight: 500; }
    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn { background-color: var(--primary-50); }
    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn > .fi-sidebar-item-label { font-weight: 600; }
    /* Menu recolhido: só os ícones, centralizados */
    @media (min-width: 64rem) {
        .fi-body .fi-sidebar:not(.fi-sidebar-open) .fi-sidebar-nav { padding-inline: 0; align-items: center; scrollbar-gutter: auto; overflow-x: hidden; }
        .fi-body .fi-sidebar:not(.fi-sidebar-open) .fi-sidebar-item-btn { justify-content: center; }
    }

    /* Cards: seções, tabelas, gráficos e resumos */
    .fi-body .fi-section:not(.fi-section-not-contained):not(.fi-aside),
    .fi-body .fi-section:not(.fi-section-not-contained).fi-aside > .fi-section-content-ctn,
    .fi-body .fi-ta-ctn,
    .fi-body .fi-wi-stats-overview-stat {
        box-shadow: var(--fc-card-shadow);
    }
    /* Botão principal verde com texto branco: o tom 700 mantém o contraste mínimo (WCAG AA) */
    .fi-btn.fi-color-primary:not(.fi-outlined),
    .fi-btn.fi-color-success:not(.fi-outlined) {
        --bg: var(--color-700); --text: #fff; --hover-bg: var(--color-800); --hover-text: #fff;
        --dark-bg: var(--color-600); --dark-text: #fff; --dark-hover-bg: var(--color-500); --dark-hover-text: #fff;
        font-weight: 600;
    }
    .fi-header-heading { font-weight: 650; letter-spacing: -0.015em; }
    .fi-ta-table > thead > tr { background-color: var(--gray-50); }
    .fi-ta-header-cell { color: var(--gray-500); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; }

    /* Cards de resumo: ícone em círculo colorido no canto, valor em destaque */
    .fi-wi-stats-overview-stat { position: relative; }
    .fi-wi-stats-overview-stat-label-ctn { min-height: 2.5rem; padding-inline-end: 3rem; }
    .fi-wi-stats-overview-stat-value { font-size: 1.625rem; line-height: 2.125rem; }
    .fi-wi-stats-overview-stat-label { font-weight: 500; }
    .fi-wi-stats-overview-stat-label-ctn > .fi-icon {
        position: absolute; top: 1.25rem; inset-inline-end: 1.25rem; width: 2.75rem; height: 2.75rem; padding: 0.65rem;
        border-radius: 9999px; color: var(--fc-tone); background-color: color-mix(in oklab, var(--fc-tone) 12%, transparent);
    }
    .fi-wi-stats-overview-stat { --fc-tone: var(--primary-600); }
    .fc-tone-success { --fc-tone: var(--success-600); }
    .fc-tone-danger { --fc-tone: var(--danger-600); }
    .fc-tone-warning { --fc-tone: var(--warning-600); }
    .fc-tone-info { --fc-tone: var(--info-600); }
    .fc-tone-gray { --fc-tone: var(--gray-500); }
    .fc-tone-primary { --fc-tone: var(--primary-600); }
    .fc-tone-value .fi-wi-stats-overview-stat-value { color: var(--fc-tone); }

    /* Modo escuro */
    .dark {
        --fc-border: rgb(255 255 255 / 0.08);
    }
    .dark .fi-body { background-color: var(--gray-950); }
    .dark .fi-topbar { background-color: var(--gray-900); }
    @media (min-width: 64rem) {
        .dark .fi-sidebar { background-color: var(--gray-900); }
    }
    .dark .fi-sidebar-group-label { color: var(--gray-500); }
    .dark .fi-sidebar-item-btn:hover { background-color: rgb(255 255 255 / 0.05); }
    .dark .fi-sidebar-item.fi-active > .fi-sidebar-item-btn { background-color: color-mix(in oklab, var(--primary-500) 15%, transparent); }
    .dark .fi-body .fi-section:not(.fi-section-not-contained):not(.fi-aside),
    .dark .fi-body .fi-section:not(.fi-section-not-contained).fi-aside > .fi-section-content-ctn,
    .dark .fi-body .fi-ta-ctn,
    .dark .fi-body .fi-wi-stats-overview-stat {
        box-shadow: 0 0 0 1px rgb(255 255 255 / 0.08);
    }
    .dark .fi-ta-table > thead > tr { background-color: rgb(255 255 255 / 0.03); }
    .dark .fi-ta-header-cell { color: var(--gray-400); }
    .dark .fi-wi-stats-overview-stat { --fc-tone: var(--primary-400); }
    .dark .fc-tone-success { --fc-tone: var(--success-400); }
    .dark .fc-tone-danger { --fc-tone: var(--danger-400); }
    .dark .fc-tone-warning { --fc-tone: var(--warning-400); }
    .dark .fc-tone-info { --fc-tone: var(--info-400); }
    .dark .fc-tone-gray { --fc-tone: var(--gray-400); }
    .dark .fc-tone-primary { --fc-tone: var(--primary-400); }
</style>
