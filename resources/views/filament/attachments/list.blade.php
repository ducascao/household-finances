@php /** @var \Illuminate\Support\Collection<int, \App\Models\Attachment> $attachments */ @endphp

@if ($attachments->isEmpty())
    <p class="fc-label">Nenhum comprovante ainda.</p>
@else
    <ul class="fc-list">
        @foreach ($attachments as $attachment)
            <li class="fc-row" wire:key="attachment-{{ $attachment->id }}">
                <div class="fc-grow">
                    <p class="fc-text fc-strong fc-truncate">{{ $attachment->name }}</p>
                    <p class="fc-small">{{ $attachment->humanSize() }} · {{ $attachment->created_at?->format('d/m/Y H:i') }}</p>
                </div>
                <div class="fc-row-start">
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
