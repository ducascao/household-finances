<x-filament-panels::page>
    @php($connection = $this->connection())

    <x-filament::section>
        @if ($connection)
            <x-slot name="heading">Conectado</x-slot>
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Conta Google</dt>
                    <dd class="font-medium">{{ $connection->email }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Pasta raiz no Drive</dt>
                    <dd class="font-medium">{{ $connection->root_folder }}</dd>
                </div>
            </dl>
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                Comprovantes ficam em <code>{{ $connection->root_folder }}/ano/mês</code>. O app só enxerga os arquivos que ele mesmo criou (escopo <code>drive.file</code>).
            </p>
        @elseif ($this->isConfigured())
            <x-slot name="heading">Não conectado</x-slot>
            <p class="text-sm">
                Conecte a conta Google do lar para guardar os comprovantes dos lançamentos no Drive.
                Faça a conexão a partir deste computador (endereço <code>localhost</code>): o Google não aceita o IP da rede de casa como retorno.
            </p>
        @else
            <x-slot name="heading">Credenciais do Google não configuradas</x-slot>
            <p class="text-sm">
                Preencha <code>GOOGLE_CLIENT_ID</code> e <code>GOOGLE_CLIENT_SECRET</code> no <code>.env</code> (passo a passo no README, seção "Google Drive") e recarregue esta página.
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
