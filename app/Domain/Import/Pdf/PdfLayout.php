<?php

namespace App\Domain\Import\Pdf;

use App\Domain\Import\Parsers\ParsedLine;

/**
 * Leitor do PDF de um banco: reconhece o documento pelo texto e extrai os lançamentos.
 * Valores com o sinal da conta: saídas/compras negativas, entradas/pagamentos positivos.
 */
abstract class PdfLayout
{
    abstract public function name(): string;

    abstract public function matches(string $text): bool;

    /**
     * @return list<ParsedLine>
     */
    abstract public function parse(string $text): array;

    /** Fatura de cartão (só pode ser importada numa conta do tipo cartão). */
    public function isCard(): bool
    {
        return false;
    }
}
