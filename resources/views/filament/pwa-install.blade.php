{{-- Botão "Instalar app" (barra superior): só aparece quando o navegador permite instalar e some depois de instalado. --}}
<div
    x-data="{ can: window.fcPwa?.canInstall() ?? false }"
    x-on:fc-pwa-changed.window="can = window.fcPwa.canInstall()"
    x-show="can"
    x-cloak
    class="fc-pwa-install"
>
    <x-filament::icon-button
        x-on:click="window.fcPwa.install()"
        icon="heroicon-o-arrow-down-tray"
        color="gray"
        label="Instalar app"
        tooltip="Instalar app"
    />
</div>
