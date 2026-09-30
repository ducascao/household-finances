<?php

namespace App\Domain\Import\Pdf;

use App\Domain\Import\Parsers\ParsedLine;
use Illuminate\Support\Carbon;

/**
 * Extrato da conta Nubank (a conferir com um PDF real). Os dias aparecem como "02 SET 2026" e os
 * lançamentos vêm em blocos "Total de entradas" / "Total de saídas", que definem o sinal. Descrição longa
 * quebra em mais de uma linha: o valor fica na última.
 */
class NubankAccountLayout extends PdfLayout
{
    public function name(): string
    {
        return 'Nubank — extrato da conta';
    }

    public function matches(string $text): bool
    {
        return (bool) preg_match('/Nu Pagamentos|nubank/iu', $text)
            && (bool) preg_match('/Total de (entradas|sa[íi]das)/iu', $text);
    }

    public function parse(string $text): array
    {
        $date = null;
        $sign = 0;
        $pending = '';
        $lines = [];

        foreach (PdfText::lines($text) as $index => $line) {
            if (preg_match('/^\s*(\d{2})\s+([A-Za-z]{3})\s+(\d{4})\b(.*)$/u', $line, $match) && ($month = PdfText::month($match[2])) !== null) {
                $date = PdfText::date((int) $match[3], $month, (int) $match[1]);
                $line = $match[4];
                $pending = '';
            }

            $content = trim($line);

            if (preg_match('/^Total de entradas/iu', $content)) {
                $sign = 1;
                $pending = '';

                continue;
            }

            if (preg_match('/^Total de sa[íi]das/iu', $content)) {
                $sign = -1;
                $pending = '';

                continue;
            }

            [$description, $amounts] = PdfText::trailingAmounts($content);

            if ($amounts === [] && $content !== '' && $date instanceof Carbon && $sign !== 0) {
                $pending .= ' '.$content;

                continue;
            }

            $description = trim($pending.' '.$description);
            $pending = '';

            if (! $date instanceof Carbon || $sign === 0 || count($amounts) !== 1 || $description === '' || preg_match('/^(Saldo|Rendimento)/iu', $description)) {
                continue;
            }

            $lines[] = new ParsedLine($index + 1, $date, PdfText::clean($description), $sign * abs($amounts[0]));
        }

        return $lines;
    }
}
