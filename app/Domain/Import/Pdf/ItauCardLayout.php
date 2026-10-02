<?php

namespace App\Domain\Import\Pdf;

use App\Domain\Import\Parsers\ParsedLine;
use App\Domain\Import\Parsers\StatementParseException;
use Illuminate\Support\Carbon;

/**
 * Fatura do cartão Itaú (conferida com a fatura mensal em PDF). Duas colunas: à esquerda "Pagamentos efetuados"
 * e "Lançamentos: compras e saques" (um bloco por titular); à direita "Lançamentos: produtos e serviços" e, logo
 * abaixo, "Compras parceladas - próximas faturas" (ignoradas, são de faturas futuras) e os limites. Cada lançamento
 * é "13/10  DESCRIÇÃO 11/12  valor", com a categoria e a cidade na linha de baixo (ignorada). Data sem ano: deduzida
 * pelo vencimento. Parcela "11/12": a data vira a da compra + 10 meses, para cair no ciclo desta fatura.
 */
class ItauCardLayout extends PdfLayout
{
    private const RECORD = '/^\s*(\d{2})\/(\d{2})\s+(\S.*?)\s+('.PdfText::AMOUNT.')\s*$/u';

    public function name(): string
    {
        return 'Itaú — fatura do cartão';
    }

    public function isCard(): bool
    {
        return true;
    }

    public function matches(string $text): bool
    {
        return (bool) preg_match('/ita[uú]/iu', $text)
            && (bool) preg_match('/Lan[çc]amentos:\s*compras e saques/iu', $text);
    }

    public function parse(string $text): array
    {
        $all = PdfText::lines($text);
        $end = $this->dueDate($all);
        $split = $this->rightColumn($all);
        $left = false;
        $right = false;
        $lines = [];

        foreach ($all as $index => $line) {
            $columns = $split === null ? [$line, ''] : [mb_substr($line, 0, $split), mb_substr($line, $split)];

            if (preg_match('/Pagamentos efetuados|Lan[çc]amentos:\s*compras e saques|Lan[çc]amentos internacionais/iu', $columns[0])) {
                $left = true;
            }

            if (preg_match('/Lan[çc]amentos:\s*produtos e servi[çc]os/iu', $columns[1])) {
                $right = true;
            } elseif (preg_match('/Compras parceladas|pr[óo]ximas faturas|Limites de cr[ée]dito/iu', $columns[1])) {
                $right = false;
            }

            foreach ([[$left, $columns[0]], [$right, $columns[1]]] as [$active, $column]) {
                if ($active && ($parsed = $this->record($index + 1, $column, $end)) !== null) {
                    $lines[] = $parsed;
                }
            }
        }

        return $lines;
    }

    private function record(int $number, string $column, Carbon $end): ?ParsedLine
    {
        if (! preg_match(self::RECORD, $column, $match)) {
            return null;
        }

        $description = PdfText::clean($match[3]);
        $date = PdfText::withoutYear((int) $match[1], (int) $match[2], $end);

        if ($date === null || $description === '') {
            return null;
        }

        if (preg_match('/(\d{2})\/(\d{2})$/u', $description, $installment) && (int) $installment[1] >= 2 && (int) $installment[1] <= (int) $installment[2]) {
            $date = $date->copy()->addMonthsNoOverflow((int) $installment[1] - 1);
        }

        // Compra positiva no PDF vira negativa no cartão; pagamento e estorno ("-") viram positivos.
        return new ParsedLine($number, $date, $description, -PdfText::amount($match[4]));
    }

    /**
     * Coluna onde começa a metade direita da página (título "Lançamentos: produtos e serviços"), ou null.
     *
     * @param  list<string>  $lines
     */
    private function rightColumn(array $lines): ?int
    {
        foreach ($lines as $line) {
            if (preg_match('/\S.*Lan[çc]amentos:\s*produtos e servi[çc]os/iu', $line)) {
                return PdfText::column($line, '/Lan[çc]amentos:\s*produtos e servi[çc]os/iu');
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function dueDate(array $lines): Carbon
    {
        foreach ($lines as $index => $line) {
            if (! preg_match('/Vencimento/iu', $line)) {
                continue;
            }

            foreach (array_slice($lines, $index, 3) as $candidate) {
                if (preg_match('/\b(\d{2})\/(\d{2})\/(\d{4})\b/u', $candidate, $match)
                    && ($date = PdfText::date((int) $match[3], (int) $match[2], (int) $match[1])) !== null) {
                    return $date;
                }
            }
        }

        throw new StatementParseException('Não encontrei a data de vencimento da fatura no PDF.');
    }
}
