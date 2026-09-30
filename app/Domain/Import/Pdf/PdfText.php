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

    public static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
