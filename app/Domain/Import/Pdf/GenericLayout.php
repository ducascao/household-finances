<?php

namespace App\Domain\Import\Pdf;

use App\Domain\Import\Parsers\ParsedLine;
use Illuminate\Support\Carbon;

/**
 * Leitor genérico: toda linha que começa com data (dd/mm/aaaa, dd-mm-aaaa, dd/mm/aa ou dd/mm) e termina
 * com valor. Com mais de um valor no fim (valor e saldo), usa o primeiro. Data sem ano: deduzida pela
 * data completa mais recente do documento (fim do período).
 */
class GenericLayout extends PdfLayout
{
    public function name(): string
    {
        return 'Genérico';
    }

    public function matches(string $text): bool
    {
        return true;
    }

    public function parse(string $text): array
    {
        $end = $this->periodEnd($text);
        $lines = [];

        foreach (PdfText::lines($text) as $index => $line) {
            if (! preg_match('/^\s*(\d{2})[\/\-.](\d{2})(?:[\/\-.](\d{4}|\d{2}))?\s+(.+)$/u', $line, $match)) {
                continue;
            }

            [$description, $amounts] = PdfText::trailingAmounts($match[4]);
            $year = $match[3];
            $date = match (strlen($year)) {
                4 => PdfText::date((int) $year, (int) $match[2], (int) $match[1]),
                2 => PdfText::date(2000 + (int) $year, (int) $match[2], (int) $match[1]),
                default => PdfText::withoutYear((int) $match[1], (int) $match[2], $end),
            };

            if ($amounts === [] || $date === null || $description === '' || preg_match('/^Saldo/iu', $description)) {
                continue;
            }

            $lines[] = new ParsedLine($index + 1, $date, PdfText::clean($description), $amounts[0]);
        }

        return $lines;
    }

    private function periodEnd(string $text): Carbon
    {
        $end = null;

        preg_match_all('/\b(\d{2})[\/\-.](\d{2})[\/\-.](\d{4})\b/u', $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $date = PdfText::date((int) $match[3], (int) $match[2], (int) $match[1]);

            if ($date !== null && ($end === null || $date->gt($end))) {
                $end = $date;
            }
        }

        return $end ?? today();
    }
}
