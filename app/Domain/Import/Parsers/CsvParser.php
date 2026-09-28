<?php

namespace App\Domain\Import\Parsers;

use App\Domain\Import\Text;
use App\Models\ImportProfile;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;

/**
 * Lê CSV conforme o perfil da conta. Linhas sem data válida ou sem valor (ex.: "SALDO ANTERIOR",
 * rodapés) são ignoradas.
 */
class CsvParser implements StatementParser
{
    public function __construct(
        private readonly ImportProfile $profile,
    ) {}

    public function parse(string $content): array
    {
        $rows = preg_split('/\r\n|\r|\n/', Text::toUtf8($content)) ?: [];
        $skip = $this->profile->skip_lines + ($this->profile->has_header ? 1 : 0);
        $lines = [];

        foreach ($rows as $index => $row) {
            if ($index < $skip || trim($row) === '') {
                continue;
            }

            $columns = str_getcsv($row, $this->profile->delimiter, '"', '');
            $date = $this->date($this->column($columns, $this->profile->date_column));
            $amount = $this->amount($columns);

            if ($date === null || $amount === null) {
                continue;
            }

            $lines[] = new ParsedLine(
                lineNumber: $index + 1,
                date: $date,
                description: Text::clean($this->column($columns, $this->profile->description_column) ?? ''),
                amount: $this->profile->invert_sign ? -$amount : $amount,
            );
        }

        if ($lines === []) {
            throw new StatementParseException('Nenhum lançamento encontrado no CSV. Confira o perfil de importação (colunas, separador e formato de data).');
        }

        return $lines;
    }

    /**
     * Pré-visualização das primeiras linhas já separadas em colunas, para configurar o perfil.
     *
     * @return list<list<string>>
     */
    public static function preview(string $content, string $delimiter, int $rows = 5): array
    {
        $lines = array_slice(array_filter(preg_split('/\r\n|\r|\n/', Text::toUtf8($content)) ?: [], fn ($line) => trim($line) !== ''), 0, $rows);

        return array_map(fn (string $line): array => array_map('trim', str_getcsv($line, $delimiter, '"', '')), $lines);
    }

    /**
     * @param  list<string|null>  $columns
     */
    private function column(array $columns, ?int $number): ?string
    {
        if ($number === null) {
            return null;
        }

        $value = trim((string) ($columns[$number - 1] ?? ''));

        return $value === '' ? null : $value;
    }

    private function date(?string $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        $format = $this->profile->date_format;

        foreach ([$format, $format.' H:i:s', $format.' H:i', $format.'\TH:i:s'] as $candidate) {
            try {
                $date = Carbon::createFromFormat('!'.$candidate, $value);
            } catch (InvalidFormatException) {
                continue;
            }

            if ($date !== null && $date->format($candidate) === $value) {
                return $date->startOfDay();
            }
        }

        return null;
    }

    /**
     * @param  list<string|null>  $columns
     */
    private function amount(array $columns): ?int
    {
        if ($this->profile->amount_column !== null) {
            return $this->number($this->column($columns, $this->profile->amount_column));
        }

        $credit = $this->number($this->column($columns, $this->profile->credit_column));
        $debit = $this->number($this->column($columns, $this->profile->debit_column));

        if ($credit === null && $debit === null) {
            return null;
        }

        return abs($credit ?? 0) - abs($debit ?? 0);
    }

    private function number(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[^\d,.\-+]/', '', $value) ?? '';

        if ($this->profile->thousands_separator !== null && $this->profile->thousands_separator !== '') {
            $value = str_replace($this->profile->thousands_separator, '', $value);
        }

        $value = str_replace($this->profile->decimal_separator, '.', $value);

        if (! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value * 100);
    }
}
