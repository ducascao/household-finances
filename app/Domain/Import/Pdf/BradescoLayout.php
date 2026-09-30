<?php

namespace App\Domain\Import\Pdf;

use App\Domain\Import\Parsers\ParsedLine;
use Illuminate\Support\Carbon;

/**
 * Extrato da conta Bradesco (conferido com o PDF do Bradesco Celular). Colunas Data, Histórico, Docto.,
 * Crédito, Débito e Saldo. Cada lançamento é um bloco: histórico na linha de cima, data (só no primeiro do
 * dia), documento e valores no meio, complemento embaixo (ex.: "REM: FULANO 22/09").
 * O valor vem sem sinal: é crédito ou débito conforme a coluna em que está.
 */
class BradescoLayout extends PdfLayout
{
    public function name(): string
    {
        return 'Bradesco — extrato da conta';
    }

    public function matches(string $text): bool
    {
        return (bool) preg_match('/bradesco/iu', $text)
            && (bool) preg_match('/Cr[ée]dito\s*\(R\$\)/u', $text)
            && (bool) preg_match('/D[ée]bito\s*\(R\$\)/u', $text);
    }

    public function parse(string $text): array
    {
        $columns = null;
        $date = null;
        $lines = [];

        foreach (PdfText::blocks($text) as $block) {
            $content = [];

            foreach ($block as $line) {
                if (preg_match('/Cr[ée]dito\s*\(R\$\)/u', $line[1]) && preg_match('/D[ée]bito\s*\(R\$\)/u', $line[1])) {
                    $columns = $this->columns($line[1]);
                } else {
                    $content[] = $line;
                }
            }

            if (collect($content)->contains(fn (array $line): bool => (bool) preg_match('/^\s*Total\b/u', $line[1]))) {
                continue;
            }

            $records = PdfText::records($content, fn (string $line): bool => PdfText::amountsWithPosition($line) !== []);

            foreach ($records as $record) {
                [$number, $anchor] = $record['anchor'];

                if (preg_match('/^\s*(\d{2})\/(\d{2})\/(\d{4})(.*)$/u', $anchor, $match)) {
                    $date = PdfText::date((int) $match[3], (int) $match[2], (int) $match[1]);
                    $inline = $match[4];
                } else {
                    $inline = $anchor;
                }

                $amount = $this->value($anchor, $columns);
                // Texto no meio da linha (formato antigo, histórico junto da data), sem valores nem documento.
                $inline = (string) preg_replace('/\s*\d{3,}$/u', '', PdfText::trailingAmounts($inline)[0]);
                $description = PdfText::clean(implode(' ', [...$record['above'], $inline, ...$record['below']]));

                if (! $date instanceof Carbon || $amount === null || $amount === 0 || $description === ''
                    || preg_match('/^(COD\. LANC\.|SALDO)/iu', $description)) {
                    continue;
                }

                $lines[] = new ParsedLine($number, $date, $description, $amount);
            }
        }

        return $lines;
    }

    /**
     * Onde terminam os títulos das colunas Crédito, Débito e Saldo (os valores são alinhados à direita).
     *
     * @return array{credit: int, debit: int, balance: int|null}
     */
    private function columns(string $header): array
    {
        $end = function (string $pattern) use ($header): ?int {
            if (! preg_match($pattern, $header, $match, PREG_OFFSET_CAPTURE)) {
                return null;
            }

            return mb_strlen(substr($header, 0, $match[0][1])) + mb_strlen($match[0][0]);
        };

        return [
            'credit' => (int) $end('/Cr[ée]dito(\s*\(R\$\))?/u'),
            'debit' => (int) $end('/D[ée]bito(\s*\(R\$\))?/u'),
            'balance' => $end('/Saldo(\s*\(R\$\))?/u'),
        ];
    }

    /**
     * Valor do lançamento: o primeiro que estiver na coluna Crédito (positivo) ou Débito (negativo).
     * Sem as colunas, o primeiro valor como impresso.
     *
     * @param  array{credit: int, debit: int, balance: int|null}|null  $columns
     */
    private function value(string $line, ?array $columns): ?int
    {
        $amounts = PdfText::amountsWithPosition($line);

        if ($columns === null) {
            return $amounts[0]['amount'] ?? null;
        }

        foreach ($amounts as $amount) {
            $distances = array_filter([
                'credit' => abs($amount['end'] - $columns['credit']),
                'debit' => abs($amount['end'] - $columns['debit']),
                'balance' => $columns['balance'] !== null ? abs($amount['end'] - $columns['balance']) : null,
            ], fn (?int $distance): bool => $distance !== null);

            $column = array_search(min($distances), $distances, true);

            if ($column === 'credit') {
                return abs($amount['amount']);
            }

            if ($column === 'debit') {
                return -abs($amount['amount']);
            }
        }

        return null;
    }
}
