<?php

namespace App\Domain\Import\Pdf;

use App\Domain\Import\Parsers\ParsedLine;
use App\Domain\Import\Parsers\StatementParseException;
use Illuminate\Support\Carbon;

/**
 * Fatura do cartão Bradesco (conferida com a fatura mensal em PDF). Lançamentos "06/04 DESCRIÇÃO  CIDADE  valor"
 * na coluna da esquerda (a da direita traz limites e taxas, ignorada). Crédito vem com "-" depois do valor
 * ("3.909,86 -"). Data sem ano: deduzida pelo vencimento. Parcela "04/06": a data vira a da compra + 3 meses,
 * para cair no ciclo desta fatura.
 */
class BradescoCardLayout extends PdfLayout
{
    public function name(): string
    {
        return 'Bradesco — fatura do cartão';
    }

    public function isCard(): bool
    {
        return true;
    }

    public function matches(string $text): bool
    {
        return (bool) preg_match('/bradesco/iu', $text)
            && (bool) preg_match('/Hist[óo]rico de Lan[çc]amentos/iu', $text);
    }

    public function parse(string $text): array
    {
        $all = PdfText::lines($text);
        $end = $this->dueDate($all);
        $city = null;
        $valueEnd = null;
        $started = false;
        $lines = [];

        foreach ($all as $index => $line) {
            if (preg_match('/Hist[óo]rico de Lan[çc]amentos/iu', $line)) {
                $started = true;
                $city = PdfText::column($line, '/Cidade/u');

                continue;
            }

            if ($started && $valueEnd === null && preg_match('/R\$\s+Limites/u', $line)) {
                $valueEnd = (int) PdfText::column($line, '/R\$/u') + 2;
            }

            if (! $started || ! preg_match('/^(\s*)(\d{2})\/(\d{2})\s+(.*)$/u', $line, $match)) {
                continue;
            }

            $descriptionStart = mb_strlen($match[1]) + 5;
            $description = PdfText::clean($city !== null && $city > $descriptionStart
                ? PdfText::slice($line, $descriptionStart, $city)
                : (preg_split('/\s{3,}/u', trim($match[4]))[0] ?? ''));

            $amounts = array_values(array_filter(PdfText::amountsWithPosition($line), fn (array $amount): bool => $amount['end'] > ($city ?? 0)));

            if ($amounts === [] || $description === '') {
                continue;
            }

            if ($valueEnd !== null) {
                usort($amounts, fn (array $a, array $b): int => abs($a['end'] - $valueEnd) <=> abs($b['end'] - $valueEnd));
            }

            $date = PdfText::withoutYear((int) $match[2], (int) $match[3], $end);

            if ($date === null) {
                continue;
            }

            if (preg_match('/(\d{2})\/(\d{2})$/u', $description, $installment) && (int) $installment[1] >= 2 && (int) $installment[1] <= (int) $installment[2]) {
                $date = $date->copy()->addMonthsNoOverflow((int) $installment[1] - 1);
            }

            // Compra positiva no PDF vira negativa no cartão; crédito ("-") vira positivo.
            $lines[] = new ParsedLine($index + 1, $date, $description, -$amounts[0]['amount']);
        }

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     */
    private function dueDate(array $lines): Carbon
    {
        foreach ($lines as $index => $line) {
            if (! preg_match('/Vencimento/u', $line)) {
                continue;
            }

            foreach (array_slice($lines, $index, 5) as $candidate) {
                if (preg_match('/\b(\d{2})\/(\d{2})\/(\d{4})\b/u', $candidate, $match)
                    && ($date = PdfText::date((int) $match[3], (int) $match[2], (int) $match[1])) !== null) {
                    return $date;
                }
            }
        }

        throw new StatementParseException('Não encontrei a data de vencimento da fatura no PDF.');
    }
}
