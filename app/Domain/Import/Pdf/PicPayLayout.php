<?php

namespace App\Domain\Import\Pdf;

use App\Domain\Import\Parsers\ParsedLine;

/**
 * Extrato da conta PicPay (a conferir com um PDF real). Linhas "02/09/2026 10:15  Descrição  + R$ 200,00".
 */
class PicPayLayout extends PdfLayout
{
    public function name(): string
    {
        return 'PicPay — extrato da conta';
    }

    public function matches(string $text): bool
    {
        return (bool) preg_match('/picpay/iu', $text) && (bool) preg_match('/extrato/iu', $text);
    }

    public function parse(string $text): array
    {
        $lines = [];

        foreach (PdfText::lines($text) as $index => $line) {
            if (! preg_match('/^\s*(\d{2})\/(\d{2})\/(\d{4})(?:\s+(?:às\s+)?\d{2}:\d{2}(?::\d{2})?)?\s+(.+)$/u', $line, $match)) {
                continue;
            }

            [$description, $amounts] = PdfText::trailingAmounts($match[4]);
            $date = PdfText::date((int) $match[3], (int) $match[2], (int) $match[1]);

            if ($amounts === [] || $date === null || $description === '' || preg_match('/^Saldo/iu', $description)) {
                continue;
            }

            $lines[] = new ParsedLine($index + 1, $date, PdfText::clean($description), $amounts[0]);
        }

        return $lines;
    }
}
