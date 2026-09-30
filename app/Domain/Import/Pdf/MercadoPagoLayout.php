<?php

namespace App\Domain\Import\Pdf;

use App\Domain\Import\Parsers\ParsedLine;

/**
 * Extrato da conta Mercado Pago (a conferir com um PDF real). Colunas Data (dd-mm-aaaa), Descrição,
 * ID da operação, Valor e Saldo.
 */
class MercadoPagoLayout extends PdfLayout
{
    public function name(): string
    {
        return 'Mercado Pago — extrato da conta';
    }

    public function matches(string $text): bool
    {
        return (bool) preg_match('/mercado\s*pago/iu', $text)
            && (bool) preg_match('/ID da opera[çc][ãa]o|DETALHE DOS MOVIMENTOS/iu', $text);
    }

    public function parse(string $text): array
    {
        $lines = [];

        foreach (PdfText::lines($text) as $index => $line) {
            if (! preg_match('/^\s*(\d{2})-(\d{2})-(\d{4})\s+(.+)$/u', $line, $match)) {
                continue;
            }

            [$description, $amounts] = PdfText::trailingAmounts($match[4]);
            $date = PdfText::date((int) $match[3], (int) $match[2], (int) $match[1]);
            // Tira o ID da operação do fim da descrição.
            $description = PdfText::clean((string) preg_replace('/\s+\d{6,}$/u', '', $description));

            if ($amounts === [] || $date === null || $description === '' || preg_match('/^Saldo/iu', $description)) {
                continue;
            }

            $lines[] = new ParsedLine($index + 1, $date, $description, $amounts[0]);
        }

        return $lines;
    }
}
