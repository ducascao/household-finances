<?php

namespace App\Domain\Import\Pdf;

use App\Domain\Import\Parsers\ParsedLine;
use Illuminate\Support\Carbon;

/**
 * Extrato da conta PicPay (conferido com o PDF do app). Dias como "08 de setembro 2026" e, em cada dia,
 * colunas Hora, Tipo, Origem / Destino, Forma de pagamento e Valor ("−R$ 367,20", "+R$ 10,00").
 * O nome de origem/destino pode quebrar em linhas acima e abaixo da linha da hora.
 * Descrição: "Tipo - Origem/Destino" (sem números de CPF).
 */
class PicPayLayout extends PdfLayout
{
    public function name(): string
    {
        return 'PicPay — extrato da conta';
    }

    public function matches(string $text): bool
    {
        return (bool) preg_match('/picpay/iu', $text) && (bool) preg_match('/extrato/iu', $text);
    }

    public function parse(string $text): array
    {
        $date = null;
        $columns = null;
        $lines = [];

        foreach (PdfText::blocks($text) as $block) {
            $content = [];

            foreach ($block as $line) {
                if (preg_match('/^\s*(\d{1,2}) de (\p{L}+) (\d{4})\b/u', $line[1], $match) && ($month = PdfText::month(mb_substr($match[2], 0, 3))) !== null) {
                    $date = PdfText::date((int) $match[3], $month, (int) $match[1]);
                } elseif (preg_match('/^\s*Hora\s+Tipo/u', $line[1])) {
                    $columns = [
                        'type' => (int) PdfText::column($line[1], '/Tipo/u'),
                        'party' => PdfText::column($line[1], '/Origem/u'),
                        'method' => PdfText::column($line[1], '/Forma/u'),
                    ];
                } else {
                    $content[] = $line;
                }
            }

            foreach (PdfText::records($content, fn (string $line): bool => (bool) preg_match('/^\s*\d{2}:\d{2}\s/u', $line)) as $record) {
                [$number, $anchor] = $record['anchor'];
                [$inline, $amounts] = PdfText::trailingAmounts($anchor);

                if (! $date instanceof Carbon || $amounts === []) {
                    continue;
                }

                if ($columns !== null && $columns['party'] !== null) {
                    $type = PdfText::slice($anchor, $columns['type'], $columns['party']);
                    $party = array_map(fn (string $line): string => PdfText::slice($line, $columns['party'], $columns['method']), [...$record['above'], $anchor, ...$record['below']]);
                } else {
                    $type = (string) preg_replace('/^\s*\d{2}:\d{2}\s+/u', '', $inline);
                    $party = [...$record['above'], ...$record['below']];
                }

                $party = PdfText::clean((string) preg_replace('/\b\d{11}\b/u', '', implode(' ', $party)));
                $description = PdfText::clean($type).($party !== '' ? ' - '.$party : '');

                $lines[] = new ParsedLine($number, $date, $description, end($amounts));
            }
        }

        return $lines;
    }
}
