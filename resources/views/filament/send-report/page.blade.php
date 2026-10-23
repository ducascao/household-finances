<x-filament-panels::page>
    @unless (\App\Filament\Pages\SendReport::isMailConfigured())
        <x-filament::section>
            <x-slot name="heading">E-mail ainda não configurado</x-slot>
            <p class="fc-text">
                Os envios funcionam, mas as mensagens ficam só no log do sistema. Para enviar de verdade, preencha
                <code class="fc-code">MAIL_MAILER=smtp</code>, <code class="fc-code">MAIL_USERNAME</code> e
                <code class="fc-code">MAIL_PASSWORD</code> (senha de app do Google) no <code class="fc-code">.env</code>;
                passo a passo no README, seção "E-mail".
            </p>
        </x-filament::section>
    @endunless

    {{ $this->table }}
</x-filament-panels::page>
