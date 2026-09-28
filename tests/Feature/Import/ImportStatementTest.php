<?php

use App\Domain\Accounts\CreateAccount;
use App\Domain\Household\CreateHousehold;
use App\Domain\Import\BankPresets;
use App\Domain\Import\ConfirmImport;
use App\Domain\Import\CreateRuleFromLine;
use App\Domain\Import\DiscardImport;
use App\Domain\Import\ImportStatement;
use App\Domain\Import\SaveImportProfile;
use App\Domain\Import\SaveImportRule;
use App\Domain\Import\UpdateImportLine;
use App\Domain\Transactions\CreateTransaction;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportFormat;
use App\Enums\ImportLineAction;
use App\Enums\ImportLineStatus;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\ImportLine;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-10-01 10:00:00');

    $this->household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->eduardo = User::where('email', 'eduardo@example.com')->sole();
    $this->maria = User::factory()->inHousehold($this->household)->create();
    $this->checking = Account::factory()->ownedBy($this->eduardo)->create(['name' => 'Nubank']);
    $this->category = fn (string $name) => Category::where('household_id', $this->household->id)->where('name', $name)->sole();

    $this->profile = fn (Account $account, string $preset) => app(SaveImportProfile::class)->execute($this->eduardo, [
        ...BankPresets::PRESETS[$preset]['profile'], 'account_id' => $account->id, 'name' => $preset,
    ]);

    $this->import = fn (string $file, ?Account $account = null, ?User $actor = null) => app(ImportStatement::class)->execute(
        $actor ?? $this->eduardo,
        ($account ?? $this->checking)->id,
        importFixture($file),
        $file,
        str_ends_with($file, '.ofx') ? ImportFormat::Ofx : ImportFormat::Csv,
    );

    $this->lines = fn (ImportBatch $batch) => ImportLine::where('import_batch_id', $batch->id)->with('category')->orderBy('line_number')->get();
});

it('importar o mesmo arquivo duas vezes não duplica nada', function () {
    ($this->profile)($this->checking, 'nubank_account');

    $first = ($this->import)('nubank-conta.csv');
    expect(($this->lines)($first)->pluck('status')->unique()->all())->toBe([ImportLineStatus::New]);

    app(ConfirmImport::class)->execute($this->eduardo, $first);
    expect(Transaction::where('account_id', $this->checking->id)->count())->toBe(5); // inclui os 2 cafés iguais

    $second = ($this->import)('nubank-conta.csv');
    expect(($this->lines)($second)->pluck('status')->unique()->all())->toBe([ImportLineStatus::Duplicate])
        ->and(($this->lines)($second)->pluck('action')->unique()->all())->toBe([ImportLineAction::Skip]);

    app(ConfirmImport::class)->execute($this->eduardo, $second);

    expect(Transaction::where('account_id', $this->checking->id)->count())->toBe(5);
});

it('dois lotes do mesmo arquivo confirmados não duplicam (checagem na confirmação)', function () {
    $a = ($this->import)('bradesco-conta.ofx');
    $b = ($this->import)('bradesco-conta.ofx');

    app(ConfirmImport::class)->execute($this->eduardo, $a);
    $counts = app(ConfirmImport::class)->execute($this->eduardo, $b);

    expect($counts)->toBe(['imported' => 0, 'settled' => 0, 'skipped' => 4])
        ->and(Transaction::count())->toBe(4);
});

it('extrato com período sobreposto só traz as linhas novas', function () {
    ($this->profile)($this->checking, 'nubank_account');
    $partial = app(ImportStatement::class)->execute($this->eduardo, $this->checking->id,
        implode("\n", array_slice(explode("\n", importFixture('nubank-conta.csv')), 0, 4)), 'parcial.csv', ImportFormat::Csv);
    app(ConfirmImport::class)->execute($this->eduardo, $partial);

    $full = ($this->import)('nubank-conta.csv');

    expect(($this->lines)($full)->map(fn ($l) => $l->status->value)->all())
        ->toBe(['duplicate', 'duplicate', 'duplicate', 'new', 'new']);
});

it('previsto correspondente é baixado, não duplicado', function () {
    $rent = app(CreateTransaction::class)->execute($this->eduardo, [
        'account_id' => $this->checking->id, 'category_id' => ($this->category)('Aluguel')->id,
        'amount' => 280000, 'status' => 'scheduled', 'due_date' => '2026-09-08', 'description' => 'Aluguel',
    ]);
    $farRent = app(CreateTransaction::class)->execute($this->eduardo, [
        'account_id' => $this->checking->id, 'category_id' => ($this->category)('Aluguel')->id,
        'amount' => 280000, 'status' => 'scheduled', 'due_date' => '2026-10-08', 'description' => 'Aluguel outubro',
    ]);

    $batch = ($this->import)('bradesco-conta.ofx');
    $line = ($this->lines)($batch)->first(fn ($l) => $l->amount->getMinorAmount()->toInt() === -280000);

    expect($line->status)->toBe(ImportLineStatus::Match)
        ->and($line->action)->toBe(ImportLineAction::Settle)
        ->and($line->matched_transaction_id)->toBe($rent->id);

    app(ConfirmImport::class)->execute($this->eduardo, $batch);

    expect($rent->refresh()->status)->toBe(TransactionStatus::Paid)
        ->and($rent->date->toDateString())->toBe('2026-09-10')
        ->and($rent->import_hash)->toBe($line->hash)
        ->and($farRent->refresh()->status)->toBe(TransactionStatus::Scheduled)
        ->and(Transaction::where('amount', -280000)->count())->toBe(2);
});

it('pode importar como nova em vez de baixar o previsto', function () {
    $rent = app(CreateTransaction::class)->execute($this->eduardo, [
        'account_id' => $this->checking->id, 'category_id' => ($this->category)('Aluguel')->id,
        'amount' => 280000, 'status' => 'scheduled', 'due_date' => '2026-09-10', 'description' => 'Aluguel',
    ]);
    $batch = ($this->import)('bradesco-conta.ofx');
    $line = ($this->lines)($batch)->firstWhere('status', ImportLineStatus::Match);

    app(UpdateImportLine::class)->execute($this->eduardo, $line, ['action' => 'import']);
    app(ConfirmImport::class)->execute($this->eduardo, $batch);

    expect($rent->refresh()->status)->toBe(TransactionStatus::Scheduled)
        ->and(Transaction::where('amount', -280000)->count())->toBe(2);
});

it('aplica regras sem diferenciar maiúsculas e acentos, com ignorar e categoria padrão', function () {
    app(SaveImportRule::class)->execute($this->household->id, ['pattern' => 'padaria sao joao', 'category_id' => ($this->category)('Mercado')->id, 'description' => 'Padaria']);
    app(SaveImportRule::class)->execute($this->household->id, ['pattern' => 'SALARIO', 'category_id' => ($this->category)('Salário')->id]);
    app(SaveImportRule::class)->execute($this->household->id, ['pattern' => 'internet', 'ignore' => true]);

    $lines = ($this->lines)(($this->import)('bradesco-conta.ofx'));

    expect($lines->map(fn ($l) => [$l->action->value, $l->category?->name, $l->final_description])->all())->toBe([
        ['import', 'Salário', 'TRANSF SALARIO EMPRESA LTDA'],
        ['import', 'Outras despesas', 'PIX ENVIADO IMOBILIARIA ALUGUEL'],
        ['import', 'Mercado', 'Padaria'],
        ['skip', null, null],
    ]);
});

it('criar regra pela revisão reaplica às linhas pendentes do lote', function () {
    ($this->profile)($this->checking, 'nubank_account');
    $batch = ($this->import)('nubank-conta.csv');
    $coffee = ($this->lines)($batch)->firstWhere('description', 'Compra no débito - CAFETERIA');

    app(CreateRuleFromLine::class)->execute($this->eduardo, $coffee, [
        'pattern' => 'cafeteria', 'category_id' => ($this->category)('Restaurante')->id, 'description' => 'Café',
    ]);

    expect(($this->lines)($batch)->where('description', 'Compra no débito - CAFETERIA')
        ->map(fn ($l) => [$l->category->name, $l->final_description])->values()->all())
        ->toBe([['Restaurante', 'Café'], ['Restaurante', 'Café']]);
});

it('no cartão, compras caem na fatura e valor positivo em despesa vira estorno', function () {
    $card = app(CreateAccount::class)->execute($this->eduardo, [
        'name' => 'Cartão', 'type' => 'credit_card', 'visibility' => 'private', 'currency' => 'BRL',
        'card_closing_day' => 21, 'card_due_day' => 28, 'card_limit' => 500000,
    ]);
    ($this->profile)($card, 'nubank_card');
    app(SaveImportRule::class)->execute($this->household->id, ['pattern' => 'pagamento recebido', 'ignore' => true]);

    $batch = ($this->import)('nubank-cartao.csv', $card);
    $lines = ($this->lines)($batch);
    app(UpdateImportLine::class)->execute($this->eduardo, $lines[2], ['category_id' => ($this->category)('Roupas')->id]);
    app(ConfirmImport::class)->execute($this->eduardo, $batch);

    $imported = Transaction::where('account_id', $card->id)->with('invoice')->orderBy('date')->get();

    expect($imported->map(fn ($t) => [$t->description, $t->amount->getMinorAmount()->toInt(), $t->invoice->reference_month->format('Y-m')])->all())->toBe([
        ['Farmácia Drogaria', -8990, '2026-09'],
        ['Posto Shell', -25000, '2026-10'],
        ['Loja X - Estorno', 3500, '2026-10'],
    ]);
});

it('confirmação exige categoria compatível com o sinal', function () {
    $batch = ($this->import)('bradesco-conta.ofx');
    app(UpdateImportLine::class)->execute($this->eduardo, ($this->lines)($batch)[1], ['category_id' => ($this->category)('Salário')->id]);

    expect(fn () => app(ConfirmImport::class)->execute($this->eduardo, $batch))
        ->toThrow(ValidationException::class, 'Linha 2: valor negativo com categoria de receita')
        ->and(Transaction::count())->toBe(0)
        ->and($batch->refresh()->status)->toBe(ImportBatchStatus::Reviewing);
});

it('linha duplicada só pode ser ignorada', function () {
    app(ConfirmImport::class)->execute($this->eduardo, ($this->import)('bradesco-conta.ofx'));
    $line = ($this->lines)(($this->import)('bradesco-conta.ofx'))->first();

    app(UpdateImportLine::class)->execute($this->eduardo, $line, ['action' => 'import']);
})->throws(ValidationException::class, 'só pode ser ignorada');

it('CSV sem perfil pede para cadastrar o perfil', function () {
    ($this->import)('picpay.csv');
})->throws(ValidationException::class, 'perfil de CSV');

it('descartar não toca nos lançamentos e trava o lote', function () {
    $batch = ($this->import)('bradesco-conta.ofx');
    app(DiscardImport::class)->execute($this->eduardo, $batch);

    expect($batch->refresh()->status)->toBe(ImportBatchStatus::Discarded)
        ->and(Transaction::count())->toBe(0)
        ->and(fn () => app(ConfirmImport::class)->execute($this->eduardo, $batch))->toThrow(ValidationException::class, 'concluída ou descartada');
});

it('usuário A não importa nem vê lotes da conta privada de B', function () {
    $batch = ($this->import)('bradesco-conta.ofx');

    expect(fn () => ($this->import)('bradesco-conta.ofx', null, $this->maria))->toThrow(ValidationException::class, 'Conta não encontrada')
        ->and(fn () => app(ConfirmImport::class)->execute($this->maria, $batch))->toThrow(ValidationException::class, 'Sem acesso');

    $this->actingAs($this->maria);

    expect(ImportBatch::count())->toBe(0)->and(ImportLine::count())->toBe(0);
});
