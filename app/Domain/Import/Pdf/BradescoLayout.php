<?php

namespace App\Domain\Import\Pdf;

use App\Domain\Import\Parsers\ParsedLine;
use Illuminate\Support\Carbon;

/**
 * Extrato da conta Bradesco (a conferir com um PDF real). Colunas Data, Lançamento, Dcto., Crédito,
 * Débito e Saldo; a data só aparece no primeiro lançamento do dia e o complemento (ex.: "REM: FULANO")
 * vem na linha de baixo. Sinal: o "-" impresso ou, sem ele, a coluna (Crédito ou Débito) do valor.
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
            && (bool) preg_match('/Dcto|Lan[çc]amento|Hist[óo]rico/iu', $text);
    }

    public function parse(string $text): array
    {
        $date = null;
        $columns = null;
        $rows = [];
        $last = null;

        foreach (PdfText::lines($text) as $index => $line) {
            if (trim($line) === '') {
                $last = null;

                continue;
            }

            if (preg_match('/Cr[ée]dito/u', $line) && preg_match('/D[ée]bito/u', $line)) {
                $columns = $this->columns($line);
                $last = null;

                continue;
            }

            $dated = preg_match('/^\s*(\d{2})\/(\d{2})\/(\d{4})\s+(.*)$/u', $line, $match) === 1;

            if ($dated) {
                $date = PdfText::date((int) $match[3], (int) $match[2], (int) $match[1]);
            }

            [$description, $amounts] = PdfText::trailingAmounts($dated ? $match[4] : trim($line));

            if ($amounts === []) {
                // Complemento do lançamento de cima (linha recuada, sem data e sem valor).
                if ($last !== null && ! $dated && preg_match('/^\s{2,}\S/u', $line)) {
                    $rows[$last]['description'] .= ' '.PdfText::clean($line);
                }

                $last = null;

                continue;
            }

            $last = null;

            if (! $date instanceof Carbon || $description === '' || preg_match('/^(SALDO|Total)/iu', $description)) {
                continue;
            }

            $amount = $amounts[0];

            if ($amount > 0 && $columns !== null && $this->inDebitColumn($line, $columns)) {
                $amount = -$amount;
            }

            $rows[] = [
                'line' => $index + 1,
                'date' => $date,
                'description' => PdfText::clean((string) preg_replace('/\s+\d{3,}$/u', '', $description)),
                'amount' => $amount,
            ];
            $last = array_key_last($rows);
        }

        return array_map(fn (array $row): ParsedLine => new ParsedLine($row['line'], $row['date'], $row['description'], $row['amount']), $rows);
    }

    /**
     * Posição (em caracteres) do fim dos títulos das colunas Crédito e Débito.
     *
     * @return array{credit: int, debit: int}
     */
    private function columns(string $header): array
    {
        preg_match('/Cr[ée]dito(\s*\(R\$\))?/u', $header, $credit, PREG_OFFSET_CAPTURE);
        preg_match('/D[ée]bito(\s*\(R\$\))?/u', $header, $debit, PREG_OFFSET_CAPTURE);

        return [
            'credit' => mb_strlen(substr($header, 0, $credit[0][1])) + mb_strlen($credit[0][0]),
            'debit' => mb_strlen(substr($header, 0, $debit[0][1])) + mb_strlen($debit[0][0]),
        ];
    }

    /**
     * O primeiro valor da linha termina mais perto da coluna Débito que da Crédito?
     *
     * @param  array{credit: int, debit: int}  $columns
     */
    private function inDebitColumn(string $line, array $columns): bool
    {
        if (! preg_match_all('/'.PdfText::AMOUNT.'/u', $line, $matches, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        $count = count($matches[0]);
        // O valor é o penúltimo quando há saldo na linha, senão o último.
        [$token, $offset] = $matches[0][$count >= 2 ? $count - 2 : 0];
        $end = mb_strlen(substr($line, 0, $offset)) + mb_strlen($token);

        return abs($end - $columns['debit']) < abs($end - $columns['credit']);
    }
}
