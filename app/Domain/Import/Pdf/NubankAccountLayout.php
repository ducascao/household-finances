<?php

namespace App\Domain\Import\Pdf;

use App\Domain\Import\Parsers\ParsedLine;
use Illuminate\Support\Carbon;

/**
 * Extrato da conta Nubank (conferido com o PDF do app). Os dias aparecem como "07 AGO 2026" e os
 * lançamentos vêm em blocos "Total de entradas" / "Total de saídas", que definem o sinal. A descrição
 * longa (origem/destino do Pix) continua nas linhas recuadas abaixo do valor, às vezes com linha em branco no meio.
 */
class NubankAccountLayout extends PdfLayout
{
    /** Continuação da descrição fica na coluna de origem/destino, bem recuada. */
    private const CONTINUATION_INDENT = 30;

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
        $rows = [];
        $last = null;

        foreach (PdfText::lines($text) as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            if (preg_match('/^\s*(\d{2})\s+([A-Za-z]{3})\s+(\d{4})\b(.*)$/u', $line, $match) && ($month = PdfText::month($match[2])) !== null) {
                $date = PdfText::date((int) $match[3], $month, (int) $match[1]);
                $line = $match[4];
                $last = null;
            }

            $content = trim($line);

            if (preg_match('/^Total de entradas/iu', $content)) {
                [$sign, $last] = [1, null];

                continue;
            }

            if (preg_match('/^Total de sa[íi]das/iu', $content)) {
                [$sign, $last] = [-1, null];

                continue;
            }

            [$description, $amounts] = PdfText::trailingAmounts($content);

            if ($amounts === []) {
                if ($last !== null && mb_strlen($line) - mb_strlen(ltrim($line)) >= self::CONTINUATION_INDENT) {
                    $rows[$last]['description'] .= ' '.$content;
                } else {
                    $last = null;
                }

                continue;
            }

            $last = null;

            if (! $date instanceof Carbon || $sign === 0 || count($amounts) !== 1 || $description === '' || preg_match('/^(Saldo|Rendimento)/iu', $description)) {
                continue;
            }

            $rows[] = ['line' => $index + 1, 'date' => $date, 'description' => $description, 'amount' => $sign * abs($amounts[0])];
            $last = array_key_last($rows);
        }

        return array_map(fn (array $row): ParsedLine => new ParsedLine($row['line'], $row['date'], PdfText::clean($row['description']), $row['amount']), $rows);
    }
}
