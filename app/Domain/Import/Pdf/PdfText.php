<?php

namespace App\Domain\Import\Pdf;

use Illuminate\Support\Carbon;

/**
 * Utilitários para ler o texto dos PDFs de banco (saída do pdftotext -layout).
 */
final class PdfText
{
    /** Valor em reais como aparece nos PDFs: "1.234,56", "-50,00", "− R$ 1.200,00", "+ R$ 200,00". */
    public const AMOUNT = '(?:[+\-−–]\s*)?(?:R\$\s*)?(?:[+\-−–]\s*)?(?:\d{1,3}(?:\.\d{3})+|\d+),\d{2}(?!\d)';

    public const MONTHS = ['JAN' => 1, 'FEV' => 2, 'MAR' => 3, 'ABR' => 4, 'MAI' => 5, 'JUN' => 6, 'JUL' => 7, 'AGO' => 8, 'SET' => 9, 'OUT' => 10, 'NOV' => 11, 'DEZ' => 12];

    /**
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        return preg_split('/\R|\f/u', $text) ?: [];
    }

    /**
     * Centavos com sinal (negativo quando há "-" ou "−" no valor).
     */
    public static function amount(string $token): int
    {
        $negative = (bool) preg_match('/[-−–]/u', $token);
        [$integer, $cents] = explode(',', (string) preg_replace('/[^\d,]/', '', $token));
        $value = (int) $integer * 100 + (int) $cents;

        return $negative ? -$value : $value;
    }

    /**
     * Separa os valores do fim da linha (valor, saldo…) do texto antes deles.
     *
     * @return array{0: string, 1: list<int>}
     */
    public static function trailingAmounts(string $line): array
    {
        $amounts = [];

        while (preg_match('/^(.*?)\s+('.self::AMOUNT.')\s*$/u', ' '.$line, $match)) {
            array_unshift($amounts, self::amount($match[2]));
            $line = $match[1];
        }

        return [trim($line), $amounts];
    }

    /**
     * Mês abreviado em português ("SET", "set") → número; null se não for mês.
     */
    public static function month(string $abbreviation): ?int
    {
        return self::MONTHS[mb_strtoupper($abbreviation)] ?? null;
    }

    public static function date(int $year, int $month, int $day): ?Carbon
    {
        return checkdate($month, $day, $year) ? Carbon::create($year, $month, $day)?->startOfDay() : null;
    }

    /**
     * Data sem ano (dia/mês) num documento que termina em $end: o ano do fim, ou o anterior se a data
     * cairia depois do fim (ex.: fatura que vence em janeiro com compra de "28 DEZ").
     */
    public static function withoutYear(int $day, int $month, Carbon $end): ?Carbon
    {
        $date = self::date($end->year, $month, $day);

        return $date === null || $date->gt($end) ? self::date($end->year - 1, $month, $day) : $date;
    }

    /**
     * Blocos de linhas separados por linha em branco (nos extratos, cada lançamento costuma ser um bloco:
     * descrição acima e/ou abaixo da linha com data e valor).
     *
     * @return list<list<array{0: int, 1: string}>> [número da linha (1…), texto]
     */
    public static function blocks(string $text): array
    {
        $blocks = [];
        $current = [];

        foreach (self::lines($text) as $index => $line) {
            if (trim($line) === '') {
                if ($current !== []) {
                    $blocks[] = $current;
                    $current = [];
                }

                continue;
            }

            $current[] = [$index + 1, $line];
        }

        if ($current !== []) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * Divide um bloco pelas linhas-âncora (a que tem data/valor): as linhas antes de cada âncora vão para ela,
     * e as que sobram depois da última também.
     *
     * @param  list<array{0: int, 1: string}>  $block
     * @param  callable(string): bool  $isAnchor
     * @return list<array{anchor: array{0: int, 1: string}, above: list<string>, below: list<string>}>
     */
    public static function records(array $block, callable $isAnchor): array
    {
        $records = [];
        $pending = [];

        foreach ($block as $line) {
            if ($isAnchor($line[1])) {
                $records[] = ['anchor' => $line, 'above' => $pending, 'below' => []];
                $pending = [];
            } else {
                $pending[] = $line[1];
            }
        }

        if ($records !== [] && $pending !== []) {
            $records[count($records) - 1]['below'] = $pending;
        }

        return $records;
    }

    /**
     * Valores da linha com a posição (em caracteres) onde cada um termina e se tem "-" logo depois
     * (como a fatura do Bradesco marca créditos: "3.909,86 -").
     *
     * @return list<array{amount: int, end: int}>
     */
    public static function amountsWithPosition(string $line): array
    {
        preg_match_all('/('.self::AMOUNT.')(\s?-(?!\d))?/u', $line, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        $amounts = [];

        foreach ($matches[1] as $index => [$token, $offset]) {
            $amount = self::amount((string) $token);
            $amounts[] = [
                'amount' => ($matches[2][$index][0] ?? null) !== null ? -abs($amount) : $amount,
                'end' => mb_strlen(substr($line, 0, (int) $offset)) + mb_strlen((string) $token),
            ];
        }

        return $amounts;
    }

    /**
     * Posição (em caracteres) onde o texto começa na linha, ou null.
     */
    public static function column(string $line, string $pattern): ?int
    {
        if (! preg_match($pattern, $line, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return mb_strlen(substr($line, 0, $match[0][1]));
    }

    /**
     * Pedaço da linha entre duas colunas (em caracteres).
     */
    public static function slice(string $line, int $from, ?int $to = null): string
    {
        return trim(mb_substr($line, $from, $to === null ? null : max(0, $to - $from)));
    }

    public static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
