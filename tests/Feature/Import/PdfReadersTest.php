<?php

use App\Domain\Import\Parsers\ParsedLine;
use App\Domain\Import\Parsers\StatementParseException;
use App\Domain\Import\Pdf\PdfParser;

/**
 * Leitores de PDF com fixtures fictícias (texto como sai do pdftotext -layout).
 *
 * @return list<array{0: string, 1: string, 2: int}>
 */
function readPdf(string $fixture, bool $card = false, ?PdfParser &$parser = null): array
{
    $parser = new PdfParser($card);

    return array_map(
        fn (ParsedLine $line): array => [$line->date->toDateString(), $line->description, $line->amount],
        $parser->parse(importFixture("pdf/{$fixture}")),
    );
}

it('Nubank conta: sinal pelos blocos de entradas e saídas e descrição quebrada em duas linhas', function () {
    expect(readPdf('nubank-conta.txt', parser: $parser))->toBe([
        ['2026-09-02', 'Transferência recebida pelo Pix EMPRESA FICTICIA LTDA - 12.345.678/0001-90', 500000],
        ['2026-09-02', 'Compra no débito PADARIA PAO QUENTE', -3240],
        ['2026-09-02', 'Transferência enviada pelo Pix JOAO DA SILVA - •••.123.456-••', -10000],
        ['2026-09-05', 'Pagamento de fatura', -135000],
        ['2026-09-20', 'Transferência recebida pelo Pix MARIA EXEMPLO', 35000],
    ])->and($parser->layout?->name())->toBe('Nubank — extrato da conta');
});

it('Nubank fatura: compras negativas, pagamento e estorno positivos, ano pelo vencimento', function () {
    expect(readPdf('nubank-fatura.txt', card: true, parser: $parser))->toBe([
        ['2026-09-03', 'Supermercado Bom Preço', -24530],
        ['2026-09-07', 'Uber* Trip', -2390],
        ['2026-09-15', 'Loja Fictícia - Parcela 2/5', -9990],
        ['2026-09-20', 'Pagamento em 20 SET', 120000],
        ['2026-09-28', 'Estorno Loja X', 3500],
        ['2026-10-02', 'Posto Combustível', -107705],
    ])->and($parser->layout?->name())->toBe('Nubank — fatura do cartão');
});

it('data sem ano na virada do ano: dezembro fica no ano anterior ao vencimento', function () {
    expect(readPdf('nubank-fatura-virada.txt', card: true))->toBe([
        ['2026-12-20', 'Presentes de Natal', -31000],
        ['2026-12-28', 'Restaurante Réveillon', -18000],
        ['2027-01-02', 'Farmácia', -4250],
    ]);

    expect(readPdf('generico.txt'))->toBe([
        ['2026-12-28', 'Compra cartão débito Mercado Central', -15000],
        ['2026-12-30', 'Pix recebido Ana', 30000],
        ['2027-01-02', 'Tarifa pacote de serviços', -2990],
    ]);
});

it('Bradesco: data do dia repetida, complemento na linha de baixo e documento fora da descrição', function () {
    expect(readPdf('bradesco-conta.txt', parser: $parser))->toBe([
        ['2026-09-01', 'TRANSFERENCIA PIX REM: JOAO DA SILVA 01/09', -15000],
        ['2026-09-01', 'PAGTO ELETRON COBRANCA CONTA DE LUZ', -8990],
        ['2026-09-05', 'CREDITO DE SALARIO EMPRESA FICTICIA', 500000],
        ['2026-09-10', 'TARIFA BANCARIA CESTA FACIL', -4500],
    ])->and($parser->layout?->name())->toBe('Bradesco — extrato da conta');
});

it('Bradesco sem sinal impresso: usa a coluna do valor (crédito ou débito)', function () {
    $row = fn (string ...$cells): string => sprintf('%-12s%-20s%-10s%15s%15s%15s', ...$cells);
    $text = implode("\n", [
        'Bradesco',
        $row('Data', 'Lancamento', 'Dcto.', 'Credito (R$)', 'Debito (R$)', 'Saldo (R$)'),
        $row('01/09/2026', 'COMPRA ELO', '111', '', '150,00', '850,00'),
        $row('02/09/2026', 'PIX RECEBIDO', '222', '300,00', '', '1.150,00'),
    ]);

    $lines = (new PdfParser(false))->parse($text);

    expect(array_map(fn (ParsedLine $line): int => $line->amount, $lines))->toBe([-15000, 30000]);
});

it('PicPay: data com hora e sinal impresso', function () {
    expect(readPdf('picpay.txt', parser: $parser))->toBe([
        ['2026-09-02', 'Pix recebido de Maria Exemplo', 20000],
        ['2026-09-03', 'Pagamento de boleto - Internet Fibra', -9990],
        ['2026-09-05', 'Rendimento da conta', 125],
        ['2026-09-06', 'Compra no cartão de débito - Restaurante Sabor', -4500],
    ])->and($parser->layout?->name())->toBe('PicPay — extrato da conta');
});

it('Mercado Pago: valor antes do saldo e ID da operação fora da descrição', function () {
    expect(readPdf('mercado-pago.txt', parser: $parser))->toBe([
        ['2026-09-01', 'Transferência Pix recebida Maria Exemplo', 150000],
        ['2026-09-03', 'Pagamento Conta de água', -12050],
        ['2026-09-10', 'Transferência Pix enviada Joao da Silva', -30000],
    ])->and($parser->layout?->name())->toBe('Mercado Pago — extrato da conta');
});

it('genérico: banco desconhecido; numa conta de cartão as compras viram negativas', function () {
    expect(readPdf('generico.txt', parser: $parser))->toHaveCount(3)
        ->and($parser->layout?->name())->toBe('Genérico')
        ->and(array_column(readPdf('generico.txt', card: true), 2))->toBe([15000, -30000, 2990]);
});

it('recusa fatura em conta corrente, extrato em cartão, PDF sem texto e PDF sem lançamentos', function () {
    expect(fn () => readPdf('nubank-fatura.txt'))->toThrow(StatementParseException::class, 'fatura de cartão')
        ->and(fn () => readPdf('picpay.txt', card: true))->toThrow(StatementParseException::class, 'extrato de conta')
        ->and(fn () => (new PdfParser(false))->parse("  \n\f "))->toThrow(StatementParseException::class, 'imagem escaneada')
        ->and(fn () => (new PdfParser(false))->parse("Um texto qualquer\nsem datas"))->toThrow(StatementParseException::class, 'Nenhum lançamento');
});
