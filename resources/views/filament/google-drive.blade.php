<x-filament-panels::page>
    @php($connection = $this->connection())

    <x-filament::section>
        @if ($connection)
            <x-slot name="heading">Conectado</x-slot>
            <dl class="fc-dl fc-text">
                <div>
                    <dt class="fc-label">Conta Google</dt>
                    <dd class="fc-strong">{{ $connection->email }}</dd>
                </div>
                <div>
                    <dt class="fc-label">Pasta raiz no Drive</dt>
                    <dd class="fc-strong">{{ $connection->root_folder }}</dd>
                </div>
            </dl>
            <p class="fc-hint fc-mt">
                Comprovantes ficam em <code class="fc-code">{{ $connection->root_folder }}/ano/mês</code>. O app só enxerga os arquivos que ele mesmo criou (escopo <code class="fc-code">drive.file</code>).
            </p>
        @elseif ($this->isConfigured())
            <x-slot name="heading">Não conectado</x-slot>
            <p class="fc-text">
                Conecte a conta Google do lar para guardar os comprovantes dos lançamentos no Drive.
                Faça a conexão a partir deste computador (endereço <code class="fc-code">localhost</code>): o Google não aceita o IP da rede de casa como retorno.
            </p>
        @else
            <x-slot name="heading">Credenciais do Google não configuradas</x-slot>
            <p class="fc-text">
                Preencha <code class="fc-code">GOOGLE_CLIENT_ID</code> e <code class="fc-code">GOOGLE_CLIENT_SECRET</code> no <code class="fc-code">.env</code> (passo a passo no README, seção "Google Drive") e recarregue esta página.
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
