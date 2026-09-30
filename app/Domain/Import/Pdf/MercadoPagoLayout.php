<?php

namespace App\Domain\Import\Pdf;

use App\Domain\Import\Parsers\ParsedLine;

/**
 * Extrato da conta Mercado Pago (conferido com o PDF do app). Colunas Data (dd-mm-aaaa), Descrição,
 * ID da operação, Valor e Saldo. Descrição longa quebra em linhas acima e abaixo da linha da data.
 */
class MercadoPagoLayout extends PdfLayout
{
    public function name(): string
    {
        return 'Mercado Pago — extrato da conta';
    }

    public function matches(string $text): bool
    {
        return (bool) preg_match('/mercado\s*pago/iu', $text)
            && (bool) preg_match('/ID da opera[çc][ãa]o|DETALHE DOS MOVIMENTOS/iu', $text);
    }

    public function parse(string $text): array
    {
        $lines = [];

        foreach (PdfText::blocks($text) as $block) {
            $content = [];

            foreach ($block as $line) {
                // Rodapé da última página: dali em diante não há lançamentos.
                if (preg_match('/^\s*Data de gera/u', $line[1])) {
                    break;
                }

                if (! preg_match('/Descri[çc][ãa]o\s+ID da opera|^\s*\d+\/\d+\s*$/u', $line[1])) {
                    $content[] = $line;
                }
            }

            foreach (PdfText::records($content, fn (string $line): bool => (bool) preg_match('/^\s*\d{2}-\d{2}-\d{4}\s/u', $line)) as $record) {
                [$number, $anchor] = $record['anchor'];
                preg_match('/^\s*(\d{2})-(\d{2})-(\d{4})(.*)$/u', $anchor, $match);
                [$inline, $amounts] = PdfText::trailingAmounts($match[4]);
                $date = PdfText::date((int) $match[3], (int) $match[2], (int) $match[1]);
                // Tira o ID da operação do fim do texto da linha.
                $inline = (string) preg_replace('/\s*\d{9,}$/u', '', $inline);
                $description = PdfText::clean(implode(' ', [...$record['above'], $inline, ...$record['below']]));

                if ($amounts === [] || $date === null || $description === '' || preg_match('/^Saldo/iu', $description)) {
                    continue;
                }

                $lines[] = new ParsedLine($number, $date, $description, $amounts[0]);
            }
        }

        return $lines;
    }
}
