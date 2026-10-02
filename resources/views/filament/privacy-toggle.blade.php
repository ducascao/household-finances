{{-- Botão do modo privacidade (barra superior): esconde os valores em R$ dos painéis neste navegador. --}}
<div x-data="{ on: window.fcPrivacy?.isOn() ?? false }" class="fc-privacy-toggle">
    <x-filament::icon-button
        x-show="! on"
        x-on:click="on = window.fcPrivacy.toggle()"
        icon="heroicon-o-eye"
        color="gray"
        label="Esconder valores"
        tooltip="Esconder valores"
    />
    <x-filament::icon-button
        x-show="on"
        x-cloak
        x-on:click="on = window.fcPrivacy.toggle()"
        icon="heroicon-o-eye-slash"
        color="gray"
        label="Mostrar valores"
        tooltip="Mostrar valores"
    />
</div>
