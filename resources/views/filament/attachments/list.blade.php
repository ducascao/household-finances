@php /** @var \Illuminate\Support\Collection<int, \App\Models\Attachment> $attachments */ @endphp

@if ($attachments->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">Nenhum comprovante ainda.</p>
@else
    <ul class="divide-y divide-gray-200 dark:divide-white/10">
        @foreach ($attachments as $attachment)
            <li class="flex items-center justify-between gap-3 py-2" wire:key="attachment-{{ $attachment->id }}">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium">{{ $attachment->name }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $attachment->humanSize() }} · {{ $attachment->created_at?->format('d/m/Y H:i') }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    @if ($attachment->isPreviewable())
                        <x-filament::link :href="route('attachments.show', $attachment)" target="_blank" icon="heroicon-m-eye" size="sm">Abrir</x-filament::link>
                    @endif
                    <x-filament::link :href="route('attachments.show', ['attachment' => $attachment, 'download' => 1])" icon="heroicon-m-arrow-down-tray" size="sm">Baixar</x-filament::link>
                    <x-filament::link
                        tag="button"
                        color="danger"
                        icon="heroicon-m-trash"
                        size="sm"
                        wire:click="deleteAttachment({{ $attachment->id }})"
                        wire:confirm="Excluir este comprovante? O arquivo também é removido do Drive."
                    >Excluir</x-filament::link>
                </div>
            </li>
        @endforeach
    </ul>
@endif
