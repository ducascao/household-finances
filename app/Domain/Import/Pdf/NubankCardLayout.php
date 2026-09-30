<?php

namespace App\Domain\Import\Pdf;

use App\Domain\Import\Parsers\ParsedLine;
use App\Domain\Import\Parsers\StatementParseException;
use Illuminate\Support\Carbon;

/**
 * Fatura do cartão Nubank (a conferir com um PDF real). Lançamentos depois de "TRANSAÇÕES DE … A …",
 * com data "03 SET" sem ano (deduzido pelo vencimento). Compras positivas no PDF viram negativas;
 * pagamentos e estornos ("−R$ …") viram positivos.
 */
class NubankCardLayout extends PdfLayout
{
    public function name(): string
    {
        return 'Nubank — fatura do cartão';
    }

    public function isCard(): bool
    {
        return true;
    }

    public function matches(string $text): bool
    {
        return (bool) preg_match('/Nu Pagamentos|nubank/iu', $text)
            && (bool) preg_match('/TRANSA[ÇC][ÕO]ES\s+DE\s+\d{2}\s+[A-Za-z]{3}/iu', $text);
    }

    public function parse(string $text): array
    {
        $end = $this->dueDate($text);
        $started = false;
        $lines = [];

        foreach (PdfText::lines($text) as $index => $line) {
            if (preg_match('/TRANSA[ÇC][ÕO]ES\s+DE/iu', $line)) {
                $started = true;

                continue;
            }

            if (! $started || ! preg_match('/^\s*(\d{2})\s+([A-Za-z]{3})\s+(.+)$/u', $line, $match) || ($month = PdfText::month($match[2])) === null) {
                continue;
            }

            [$description, $amounts] = PdfText::trailingAmounts($match[3]);
            $date = PdfText::withoutYear((int) $match[1], $month, $end);

            if ($amounts === [] || $date === null) {
                continue;
            }

            // Tira o final do cartão ("•••• 1234") da descrição.
            $description = (string) preg_replace('/[•*·]{2,}\s*\d{4}\s*/u', '', $description);

            $lines[] = new ParsedLine($index + 1, $date, PdfText::clean($description), -end($amounts));
        }

        return $lines;
    }

    private function dueDate(string $text): Carbon
    {
        foreach (['/vencimento:?\s*(\d{2})\s+([A-Za-z]{3})\s+(\d{4})/iu', '/FATURA\s+(\d{2})\s+([A-Za-z]{3})\s+(\d{4})/u'] as $pattern) {
            if (preg_match($pattern, $text, $match) && ($month = PdfText::month($match[2])) !== null
                && ($date = PdfText::date((int) $match[3], $month, (int) $match[1])) !== null) {
                return $date;
            }
        }

        throw new StatementParseException('Não encontrei a data de vencimento da fatura no PDF.');
    }
}
