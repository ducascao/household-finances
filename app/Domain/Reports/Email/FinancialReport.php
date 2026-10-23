<?php

namespace App\Domain\Reports\Email;

/**
 * Conteúdo do e-mail de resumo, já calculado e formatado no momento do envio (só textos, para a fila).
 * Cada parte vem null quando não foi escolhida.
 */
final readonly class FinancialReport
{
    /**
     * @param  list<string>  $sectionLabels
     * @param  array<string, mixed>|null  $monthly
     * @param  array<string, mixed>|null  $netWorth
     * @param  array<string, mixed>|null  $portfolio
     */
    public function __construct(
        public string $household,
        public string $sender,
        public string $viewLabel,
        public bool $sharedOnly,
        public string $generatedAt,
        public array $sectionLabels,
        public ?string $message = null,
        public ?array $monthly = null,
        public ?array $netWorth = null,
        public ?array $portfolio = null,
    ) {}

    public function subject(): string
    {
        return 'Finanças de Casa: '.implode(', ', $this->sectionLabels).' ('.$this->generatedAt.')';
    }
}
