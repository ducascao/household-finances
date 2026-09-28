<?php

use App\Domain\Household\CreateHousehold;
use App\Domain\Import\ImportStatement;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportFormat;
use App\Enums\ImportLineAction;
use App\Filament\Resources\ImportBatches\Pages\ListImportBatches;
use App\Filament\Resources\ImportBatches\Pages\ViewImportBatch;
use App\Filament\Resources\ImportBatches\RelationManagers\LinesRelationManager;
use App\Filament\Resources\ImportProfiles\Pages\CreateImportProfile;
use App\Filament\Resources\ImportRules\Pages\CreateImportRule;
use App\Models\Account;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\ImportLine;
use App\Models\ImportProfile;
use App\Models\ImportRule;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    // Sem Carbon::setTestNow aqui: com o relógio congelado o upload falso do Livewire quebra nos testes.
    Storage::fake('local');

    $this->household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->eduardo = User::where('email', 'eduardo@example.com')->sole();
    $this->eduardo->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $this->maria = User::factory()->withTwoFactor()->inHousehold($this->household)->create();
    $this->checking = Account::factory()->ownedBy($this->eduardo)->private()->create(['name' => 'Bradesco']);
    $this->category = fn (string $name) => Category::where('household_id', $this->household->id)->where('name', $name)->sole();
});

it('envia o OFX, revisa e confirma pela tela; o arquivo não fica guardado', function () {
    $this->actingAs($this->eduardo);

    Livewire::test(ListImportBatches::class)
        ->mountAction('importStatement')
        ->set('mountedActions.0.data.account_id', $this->checking->id)
        ->set('mountedActions.0.data.file', UploadedFile::fake()->createWithContent('extrato.ofx', importFixture('bradesco-conta.ofx')))
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $batch = ImportBatch::sole();

    expect($batch->file_name)->toBe('extrato.ofx')
        ->and($batch->status)->toBe(ImportBatchStatus::Reviewing)
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    $lines = ImportLine::where('import_batch_id', $batch->id)->orderBy('line_number')->get();

    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $batch, 'pageClass' => ViewImportBatch::class])
        ->assertCanSeeTableRecords($lines)
        ->call('updateTableColumnState', 'category_id', (string) $lines[2]->getKey(), (string) ($this->category)('Mercado')->id)
        ->call('updateTableColumnState', 'final_description', (string) $lines[2]->getKey(), 'Padaria')
        ->call('updateTableColumnState', 'action', (string) $lines[3]->getKey(), ImportLineAction::Skip->value)
        ->callTableAction('createRule', $lines[0], ['pattern' => 'salario', 'category_id' => ($this->category)('Salário')->id]);

    Livewire::test(ViewImportBatch::class, ['record' => $batch->getRouteKey()])
        ->callAction('confirmImport')
        ->assertHasNoActionErrors();

    expect($batch->refresh()->status)->toBe(ImportBatchStatus::Completed)
        ->and(Transaction::with('category')->orderBy('date')->get()->map(fn ($t) => [$t->description, $t->category->name])->all())->toBe([
            ['TRANSF SALARIO EMPRESA LTDA', 'Salário'],
            ['PIX ENVIADO IMOBILIARIA ALUGUEL', 'Outras despesas'],
            ['Padaria', 'Mercado'],
        ])
        ->and(ImportRule::where('pattern', 'salario')->exists())->toBeTrue();
});

it('mostra o erro de leitura sem criar lote', function () {
    $this->actingAs($this->eduardo);

    Livewire::test(ListImportBatches::class)
        ->mountAction('importStatement')
        ->set('mountedActions.0.data.account_id', $this->checking->id)
        ->set('mountedActions.0.data.file', UploadedFile::fake()->createWithContent('extrato.csv', importFixture('picpay.csv')))
        ->callMountedAction()
        ->assertNotified('Não foi possível importar');

    expect(ImportBatch::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('cria perfil de CSV a partir do modelo do banco', function () {
    $this->actingAs($this->eduardo);

    Livewire::test(CreateImportProfile::class)
        ->fillForm(['account_id' => $this->checking->id])
        ->fillForm(['preset' => 'bradesco_account'])
        ->assertFormSet(['delimiter' => ';', 'credit_column' => 4, 'debit_column' => 5, 'amount_column' => null, 'skip_lines' => 1])
        ->fillForm(['sample' => "Data;Histórico\n05/09/2026;SALARIO"])
        ->assertSee('SALARIO')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ImportProfile::where('account_id', $this->checking->id)->sole())
        ->name->toBe('Bradesco — conta')
        ->decimal_separator->toBe(',');
});

it('cria regra de ignorar pela tela', function () {
    $this->actingAs($this->eduardo);

    Livewire::test(CreateImportRule::class)
        ->fillForm(['pattern' => 'Pagamento recebido', 'ignore' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ImportRule::sole())->ignore->toBeTrue()->category_id->toBeNull();
});

it('usuário A não vê a importação da conta privada de B', function () {
    $batch = app(ImportStatement::class)->execute($this->eduardo, $this->checking->id, importFixture('bradesco-conta.ofx'), 'x.ofx', ImportFormat::Ofx);
    $this->actingAs($this->maria);

    Livewire::test(ListImportBatches::class)->assertCanNotSeeTableRecords([$batch]);
    $this->get(route('filament.app.resources.import-batches.view', $batch))->assertNotFound();
});

it('abre as telas de importação', function () {
    $this->actingAs($this->eduardo);

    foreach (['import-batches.index', 'import-rules.index', 'import-rules.create', 'import-profiles.index', 'import-profiles.create'] as $route) {
        $this->get(route("filament.app.resources.{$route}"))->assertSuccessful();
    }
});
