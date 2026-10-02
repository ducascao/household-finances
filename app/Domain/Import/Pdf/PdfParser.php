<?php

namespace App\Domain\Import\Pdf;

use App\Domain\Import\Parsers\ParsedLine;
use App\Domain\Import\Parsers\StatementParseException;
use App\Domain\Import\Parsers\StatementParser;
use App\Domain\Import\Text;

/**
 * Lê o texto de um PDF de extrato ou fatura: reconhece o banco e usa o leitor dele; sem leitor
 * reconhecido (ou se o do banco não achar nada), usa o genérico.
 */
class PdfParser implements StatementParser
{
    public ?PdfLayout $layout = null;

    public function __construct(
        private readonly bool $cardAccount,
    ) {}

    /**
     * @return list<PdfLayout>
     */
    public static function layouts(): array
    {
        return [new NubankCardLayout, new NubankAccountLayout, new BradescoCardLayout, new BradescoLayout, new ItauCardLayout, new PicPayLayout, new MercadoPagoLayout];
    }

    public function parse(string $content): array
    {
        $text = Text::toUtf8($content);

        if (! preg_match('/\S/u', $text)) {
            throw new StatementParseException('O PDF não tem texto para ler (pode ser uma imagem escaneada).');
        }

        $layout = collect(self::layouts())->first(fn (PdfLayout $layout): bool => $layout->matches($text)) ?? new GenericLayout;

        if ($layout->isCard() && ! $this->cardAccount) {
            throw new StatementParseException("O PDF parece ser uma fatura de cartão ({$layout->name()}), mas a conta escolhida não é um cartão.");
        }

        if (! $layout->isCard() && ! $layout instanceof GenericLayout && $this->cardAccount) {
            throw new StatementParseException("O PDF parece ser um extrato de conta ({$layout->name()}), mas a conta escolhida é um cartão.");
        }

        $lines = $layout->parse($text);

        if ($lines === [] && ! $layout instanceof GenericLayout) {
            $layout = new GenericLayout;
            $lines = $layout->parse($text);
        }

        if ($lines === []) {
            throw new StatementParseException('Nenhum lançamento encontrado no PDF.');
        }

        // No genérico, numa fatura as compras vêm positivas: no cartão viram negativas.
        if ($layout instanceof GenericLayout && $this->cardAccount) {
            $lines = array_map(fn (ParsedLine $line): ParsedLine => new ParsedLine($line->lineNumber, $line->date, $line->description, -$line->amount), $lines);
        }

        $this->layout = $layout;

        return $lines;
    }
}
