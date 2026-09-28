<?php

use App\Domain\Import\BankPresets;
use App\Domain\Import\LineHasher;
use App\Domain\Import\Parsers\CsvParser;
use App\Domain\Import\Parsers\OfxParser;
use App\Domain\Import\Parsers\ParsedLine;
use App\Domain\Import\Parsers\StatementParseException;
use App\Domain\Import\Text;
use App\Models\ImportProfile;
use Illuminate\Support\Carbon;

/**
 * @param  list<ParsedLine>  $lines
 * @return list<array{string, string, int}>
 */
function rows(array $lines): array
{
    return array_map(fn (ParsedLine $l) => [$l->date->toDateString(), $l->description, $l->amount], $lines);
}

function presetProfile(string $preset, array $overrides = []): ImportProfile
{
    return new ImportProfile([...BankPresets::PRESETS[$preset]['profile'], 'name' => $preset, ...$overrides]);
}

it('lê OFX em SGML com Latin-1, datas com fuso, vírgula decimal e NAME sem MEMO', function () {
    expect(rows((new OfxParser)->parse(importFixture('bradesco-conta.ofx'))))->toBe([
        ['2026-09-05', 'TRANSF SALARIO EMPRESA LTDA', 850000],
        ['2026-09-10', 'PIX ENVIADO IMOBILIARIA ALUGUEL', -280000],
        ['2026-09-12', 'COMPRA DEBITO PADARIA SÃO JOÃO', -4590],
        ['2026-09-15', 'INTERNET FIBRA', -11990],
    ]);
});

it('lê OFX em XML de cartão com entidades', function () {
    expect(rows((new OfxParser)->parse(importFixture('nubank-cartao.ofx'))))->toBe([
        ['2026-09-20', 'Farmácia & Drogaria', -8990],
        ['2026-09-22', 'Posto Shell', -25000],
        ['2026-09-25', 'Pagamento recebido', 120000],
    ]);
});

it('recusa OFX sem lançamentos', function () {
    (new OfxParser)->parse('<OFX></OFX>');
})->throws(StatementParseException::class);

it('lê CSV da conta Nubank', function () {
    expect(rows((new CsvParser(presetProfile('nubank_account')))->parse(importFixture('nubank-conta.csv'))))->toBe([
        ['2026-09-05', 'Transferência recebida - EMPRESA LTDA', 850000],
        ['2026-09-10', 'Transferência enviada pelo Pix - IMOBILIARIA', -280000],
        ['2026-09-12', 'Compra no débito - CAFETERIA', -750],
        ['2026-09-12', 'Compra no débito - CAFETERIA', -750],
        ['2026-09-15', 'Pagamento de boleto efetuado - INTERNET FIBRA', -11990],
    ]);
});

it('lê CSV do cartão Nubank invertendo o sinal', function () {
    expect(rows((new CsvParser(presetProfile('nubank_card')))->parse(importFixture('nubank-cartao.csv'))))->toBe([
        ['2026-09-20', 'Farmácia Drogaria', -8990],
        ['2026-09-22', 'Posto Shell', -25000],
        ['2026-09-23', 'Loja X - Estorno', 3500],
        ['2026-09-25', 'Pagamento recebido', 120000],
    ]);
});

it('lê CSV do Bradesco com crédito e débito separados, milhar e Latin-1, ignorando saldo e total', function () {
    expect(rows((new CsvParser(presetProfile('bradesco_account')))->parse(importFixture('bradesco-conta.csv'))))->toBe([
        ['2026-09-05', 'TRANSF SALARIO EMPRESA LTDA', 850000],
        ['2026-09-10', 'PIX ENVIADO IMOBILIARIA', -280000],
        ['2026-09-12', 'COMPRA DEBITO PADARIA SÃO JOÃO', -4590],
    ]);
});

it('lê CSV do Mercado Pago e do PicPay', function () {
    expect(rows((new CsvParser(presetProfile('mercado_pago')))->parse(importFixture('mercado-pago.csv'))))->toBe([
        ['2026-09-05', 'Rendimentos', 1234],
        ['2026-09-18', 'Pagamento com QR Pix MERCADINHO', -5820],
    ])->and(rows((new CsvParser(presetProfile('picpay')))->parse(importFixture('picpay.csv'))))->toBe([
        ['2026-09-03', 'Pix recebido - FULANO', 15000],
        ['2026-09-14', 'Pagamento de conta - AGUA E ESGOTO', -8977],
    ]);
});

it('avisa quando o perfil não encontra nenhum lançamento', function () {
    (new CsvParser(presetProfile('nubank_card')))->parse(importFixture('bradesco-conta.csv'));
})->throws(StatementParseException::class, 'Confira o perfil');

it('mostra prévia das primeiras linhas em colunas', function () {
    expect(CsvParser::preview(importFixture('picpay.csv'), ';', 2))->toBe([
        ['Data', 'Descrição', 'Valor'],
        ['03/09/2026', 'Pix recebido - FULANO', '150,00'],
    ]);
});

it('normaliza descrição sem acentos, maiúsculas e espaços extras', function () {
    expect(Text::normalize('  Padaria   SÃO João '))->toBe('padaria sao joao');
});

it('hash diferencia linhas idênticas pela ocorrência e é estável ao reimportar', function () {
    $line = fn () => new ParsedLine(1, Carbon::parse('2026-09-12'), 'Cafeteria', -750);
    $first = LineHasher::hashes(1, [$line(), $line()]);
    $again = LineHasher::hashes(1, [$line(), $line()]);

    expect($first[0])->not->toBe($first[1])
        ->and($again)->toBe($first)
        ->and(LineHasher::hashes(2, [$line()])[0])->not->toBe($first[0])
        ->and(LineHasher::hashes(1, [new ParsedLine(1, Carbon::parse('2026-09-12'), '  CAFETERIA ', -750)])[0])->toBe($first[0]);
});
