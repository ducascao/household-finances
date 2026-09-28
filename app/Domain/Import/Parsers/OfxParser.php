<?php

namespace App\Domain\Import\Parsers;

use App\Domain\Import\Text;
use Illuminate\Support\Carbon;

/**
 * Lê OFX em SGML (tags de valor sem fechamento, formato comum dos bancos brasileiros) e em XML.
 */
class OfxParser implements StatementParser
{
    public function parse(string $content): array
    {
        $content = Text::toUtf8($content);

        if (! str_contains(strtoupper($content), '<STMTTRN>')) {
            throw new StatementParseException('Arquivo OFX sem lançamentos (STMTTRN).');
        }

        $blocks = preg_split('/<STMTTRN>/i', $content) ?: [];
        array_shift($blocks);

        $lines = [];

        foreach ($blocks as $index => $block) {
            $block = preg_split('/<\/STMTTRN>|<\/BANKTRANLIST>/i', $block)[0] ?? $block;

            $date = $this->field($block, 'DTPOSTED');
            $amount = $this->field($block, 'TRNAMT');
            $description = $this->field($block, 'MEMO') ?? $this->field($block, 'NAME') ?? '';

            if ($date === null || $amount === null) {
                throw new StatementParseException('Lançamento '.($index + 1).' do OFX sem data ou valor.');
            }

            $lines[] = new ParsedLine(
                lineNumber: $index + 1,
                date: $this->date($date),
                description: Text::clean(html_entity_decode($description, ENT_QUOTES | ENT_XML1, 'UTF-8')),
                amount: $this->amount($amount),
            );
        }

        return $lines;
    }

    private function field(string $block, string $tag): ?string
    {
        if (preg_match('/<'.$tag.'>([^<\r\n]*)/i', $block, $match) !== 1) {
            return null;
        }

        $value = trim($match[1]);

        return $value === '' ? null : $value;
    }

    /**
     * DTPOSTED: AAAAMMDD[HHMMSS[.XXX]][[-3:BRT]]
     */
    private function date(string $value): Carbon
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $value, $match) !== 1) {
            throw new StatementParseException("Data inválida no OFX: {$value}");
        }

        return Carbon::create((int) $match[1], (int) $match[2], (int) $match[3])->startOfDay();
    }

    private function amount(string $value): int
    {
        $value = str_replace(',', '.', trim($value));

        if (! is_numeric($value)) {
            throw new StatementParseException("Valor inválido no OFX: {$value}");
        }

        return (int) round((float) $value * 100);
    }
}
