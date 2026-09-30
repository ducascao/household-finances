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

it('Nubank conta: sinal pelos blocos de entradas e saídas e origem/destino continuando abaixo do valor', function () {
    expect(readPdf('nubank-conta.txt', parser: $parser))->toBe([
        ['2026-09-02', 'Transferência recebida pelo Pix EMPRESA FICTICIA LTDA - 12.345.678/0001-90 - BANCO EXEMPLO S.A. (0001) Agência: 1 Conta: 1234567-8', 500000],
        ['2026-09-02', 'Compra no débito PADARIA PAO QUENTE', -3240],
        ['2026-09-02', 'Transferência enviada pelo Pix JOAO DA SILVA - •••.987.654-•• - BANCO EXEMPLO (0001) Agência: 1 Conta: 99-9', -10000],
        ['2026-09-05', 'Pagamento de fatura', -135000],
        ['2026-09-20', 'Transferência recebida pelo Pix MARIA EXEMPLO - •••.111.222-•• - BANCO EXEMPLO', 35000],
    ])->and($parser->layout?->name())->toBe('Nubank — extrato da conta');
});

it('Nubank fatura: compras e IOF negativos, pagamento e estorno positivos, ano pelo vencimento', function () {
    expect(readPdf('nubank-fatura.txt', card: true, parser: $parser))->toBe([
        ['2026-08-31', 'Curso Exemplo - Parcela 8/12', -24975],
        ['2026-09-04', 'Google One', -2399],
        ['2026-09-17', 'Dl*Uberrides', -1495],
        ['2026-09-17', 'Dl*Uberrides', -1495],
        ['2026-09-18', 'IOF de "Wl *Steam Purchase"', -1330],
        ['2026-09-18', 'Wl *Steam Purchase', -37989],
        ['2026-09-28', 'Estorno Loja X', 3500],
        ['2026-09-08', 'Pagamento em 08 SET', 62564],
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

it('Bradesco conta: histórico acima, complemento abaixo e sinal pela coluna Crédito/Débito (que muda de lugar por folha)', function () {
    expect(readPdf('bradesco-conta.txt', parser: $parser))->toBe([
        ['2026-09-22', 'PIX RECEBIDO REM: Maria Exemplo 22/09', 186688],
        ['2026-09-23', 'PRESTACAO DE CRED IMOB PRESTACAO', -186688],
        ['2026-09-30', 'CREDITO DE SALARIO EMPRESA FICTICIA', 500000],
        ['2026-09-30', 'PIX ENVIADO DES: JOAO DA SILVA 30/09', -9500],
    ])->and($parser->layout?->name())->toBe('Bradesco — extrato da conta');
});

it('Bradesco fatura: só a coluna de lançamentos, crédito com "-", parcela no ciclo da fatura e virada do ano', function () {
    // Vencimento 05/01/2027: dezembro fica em 2026, janeiro em 2027; "03/06" comprada em 24/10 cai em 24/12
    expect(readPdf('bradesco-fatura.txt', card: true, parser: $parser))->toBe([
        ['2026-12-06', 'PAGTO. POR DEB EM C/C', 90000],
        ['2026-12-24', 'LOJA ESPORTES 03/06', -7999],
        ['2026-12-20', 'SUPERMERCADO EXEMPLO', -24530],
        ['2026-12-28', 'POSTO COMBUSTIVEL', -13180],
        ['2026-12-30', 'LOJA FERRAGENS 01/03', -29168],
        ['2027-01-02', 'FARMACIA EXEMPLO', -3439],
        ['2027-01-03', 'PADARIA DO BAIRRO', -30001],
    ])->and($parser->layout?->name())->toBe('Bradesco — fatura do cartão')
        ->and(array_sum(array_filter(array_column(readPdf('bradesco-fatura.txt', card: true), 2), fn (int $amount): bool => $amount < 0)))->toBe(-108317);
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

it('PicPay: dia no cabeçalho, origem/destino quebrado acima e abaixo da hora, sem CPF', function () {
    expect(readPdf('picpay.txt', parser: $parser))->toBe([
        ['2026-09-08', 'Pix enviado - MARIA EXEMPLO', -36720],
        ['2026-09-04', 'Pix enviado - JOAO DA SILVA PEREIRA', -34032],
        ['2026-08-15', 'Dinheiro guardado - No cofrinho Cofrinho Turbinado', -1000000],
        ['2026-08-13', 'Pix recebido - FULANO EXEMPLO', 1090620],
    ])->and($parser->layout?->name())->toBe('PicPay — extrato da conta');
});

it('Mercado Pago: descrição quebrada acima e abaixo da data, valor antes do saldo, sem ID da operação', function () {
    $lines = readPdf('mercado-pago.txt', parser: $parser);

    expect($lines)->toBe([
        ['2026-08-01', 'Pix enviado Maria dos Santos', -11900],
        ['2026-08-03', 'Rendimentos', 126],
        ['2026-08-05', 'Pagamento de conta ESCOLA EXEMPLO DE ENSINO LTDA', -179080],
        ['2026-08-05', 'Pix recebido FULANO EXEMPLO', 560000],
        ['2026-08-10', 'Pagamento de conta Sanasa', -32149],
        ['2026-08-31', 'Pagamento com QR Pix COMPANHIA DE LUZ EXEMPLO', -11611],
    ])->and($parser->layout?->name())->toBe('Mercado Pago — extrato da conta')
        // Confere com o resumo do extrato: entradas 5.601,26, saídas −2.347,40
        ->and(array_sum(array_filter(array_column($lines, 2), fn (int $amount): bool => $amount > 0)))->toBe(560126)
        ->and(array_sum(array_filter(array_column($lines, 2), fn (int $amount): bool => $amount < 0)))->toBe(-234740);
});

it('genérico: banco desconhecido; numa conta de cartão as compras viram negativas', function () {
    expect(readPdf('generico.txt', parser: $parser))->toHaveCount(3)
        ->and($parser->layout?->name())->toBe('Genérico')
        ->and(array_column(readPdf('generico.txt', card: true), 2))->toBe([15000, -30000, 2990]);
});

it('recusa fatura em conta corrente, extrato em cartão, PDF sem texto e PDF sem lançamentos', function () {
    expect(fn () => readPdf('nubank-fatura.txt'))->toThrow(StatementParseException::class, 'fatura de cartão')
        ->and(fn () => readPdf('picpay.txt', card: true))->toThrow(StatementParseException::class, 'extrato de conta')
        ->and(fn () => readPdf('bradesco-fatura.txt'))->toThrow(StatementParseException::class, 'fatura de cartão')
        ->and(fn () => (new PdfParser(false))->parse("  \n\f "))->toThrow(StatementParseException::class, 'imagem escaneada')
        ->and(fn () => (new PdfParser(false))->parse("Um texto qualquer\nsem datas"))->toThrow(StatementParseException::class, 'Nenhum lançamento');
});
