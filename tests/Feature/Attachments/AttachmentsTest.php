<?php

use App\Contracts\AttachmentStorage;
use App\Domain\Accounts\CreateAccount;
use App\Domain\Attachments\AttachFile;
use App\Domain\Attachments\DeleteAttachment;
use App\Domain\CreditCard\CreateInstallmentPurchase;
use App\Domain\Household\CreateHousehold;
use App\Domain\Transactions\CreateTransaction;
use App\Domain\Transactions\DeleteTransaction;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\GoogleConnection;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    // Integração testada com o disco falso: nenhum acesso ao Google.
    config(['attachments.disk' => 'google']);
    Storage::fake('google');

    $this->household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->eduardo = User::where('email', 'eduardo@example.com')->sole();
    $this->eduardo->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $this->maria = User::factory()->withTwoFactor()->inHousehold($this->household)->create();

    $category = Category::where('household_id', $this->household->id)->where('name', 'Mercado')->sole();
    $this->private = Account::factory()->ownedBy($this->eduardo)->private()->create();
    $this->shared = Account::factory()->ownedBy($this->eduardo)->shared()->create();

    $this->transaction = fn (Account $account, string $description = 'Mercado') => app(CreateTransaction::class)->execute($this->eduardo, [
        'account_id' => $account->id, 'category_id' => $category->id, 'amount' => 1000, 'date' => '2026-09-10', 'description' => $description,
    ]);
    $this->attach = fn ($transaction, string $name = 'nota.pdf', string $mime = 'application/pdf', ?User $actor = null) => app(AttachFile::class)
        ->execute($actor ?? $this->eduardo, $transaction, '%PDF-1.4 conteúdo', $name, $mime);
});

it('grava em ano/mês com o nome datado e guarda os dados do arquivo', function () {
    $attachment = ($this->attach)(($this->transaction)($this->shared));

    expect($attachment)
        ->disk->toBe('google')
        ->path->toBe('2026/09/2026-09-10 Mercado - nota.pdf')
        ->name->toBe('nota.pdf')
        ->mime_type->toBe('application/pdf')
        ->size->toBe(strlen('%PDF-1.4 conteúdo'))
        ->uploaded_by->toBe($this->eduardo->id);

    Storage::disk('google')->assertExists('2026/09/2026-09-10 Mercado - nota.pdf');
});

it('não sobrescreve arquivo de mesmo nome', function () {
    $transaction = ($this->transaction)($this->shared);
    ($this->attach)($transaction);

    expect(($this->attach)($transaction)->path)->toBe('2026/09/2026-09-10 Mercado - nota (2).pdf');
});

it('anexo de lançamento em conta privada só é acessível ao dono', function () {
    $attachment = ($this->attach)(($this->transaction)($this->private));

    $this->actingAs($this->eduardo)
        ->get(route('attachments.show', $attachment))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertStreamedContent('%PDF-1.4 conteúdo');

    $this->actingAs($this->maria)->get(route('attachments.show', $attachment))->assertNotFound();

    expect(Attachment::find($attachment->id))->toBeNull()
        ->and($this->maria->can('view', $attachment))->toBeFalse()
        ->and(fn () => ($this->attach)(($this->transaction)($this->private), actor: $this->maria))->toThrow(ValidationException::class, 'Sem acesso')
        ->and(fn () => app(DeleteAttachment::class)->execute($this->maria, $attachment))->toThrow(ValidationException::class);
});

it('os dois acessam o anexo da conta compartilhada; download força anexo', function () {
    $attachment = ($this->attach)(($this->transaction)($this->shared));

    $this->actingAs($this->maria)->get(route('attachments.show', $attachment))->assertOk()
        ->assertHeader('Content-Disposition', "inline; filename*=UTF-8''nota.pdf");
    $this->get(route('attachments.show', ['attachment' => $attachment, 'download' => 1]))
        ->assertHeader('Content-Disposition', "attachment; filename*=UTF-8''nota.pdf");
});

it('exige login para abrir o anexo', function () {
    $attachment = ($this->attach)(($this->transaction)($this->shared));

    $this->get(route('attachments.show', $attachment))->assertRedirect(route('filament.app.auth.login'));
});

it('recusa tipo e tamanho não aceitos', function () {
    $transaction = ($this->transaction)($this->shared);

    expect(fn () => ($this->attach)($transaction, 'script.exe', 'application/x-msdownload'))->toThrow(ValidationException::class, 'Tipo de arquivo')
        ->and(fn () => app(AttachFile::class)->execute($this->eduardo, $transaction, str_repeat('x', 10 * 1024 * 1024 + 1), 'grande.pdf', 'application/pdf'))
        ->toThrow(ValidationException::class, '10 MB');
});

it('sem Drive conectado avisa com clareza', function () {
    config(['attachments.disk' => null]);

    ($this->attach)(($this->transaction)($this->shared));
})->throws(ValidationException::class, 'não está conectado');

it('com Drive conectado usa o disco google do lar', function () {
    config(['attachments.disk' => null]);
    $connection = new GoogleConnection(['email' => 'casa@gmail.com', 'refresh_token' => 'x', 'root_folder' => 'Casa']);
    $connection->household_id = $this->household->id;
    $connection->save();

    expect(app(AttachmentStorage::class)->isAvailable($this->household))->toBeTrue();
});

it('excluir o anexo, o lançamento ou a compra parcelada apaga os arquivos', function () {
    $single = ($this->transaction)($this->shared, 'Farmácia');
    $a = ($this->attach)($single, 'a.pdf');
    app(DeleteAttachment::class)->execute($this->eduardo, $a);
    Storage::disk('google')->assertMissing($a->path);

    $b = ($this->attach)($single, 'b.pdf');
    app(DeleteTransaction::class)->execute($this->eduardo, $single);
    Storage::disk('google')->assertMissing($b->path);

    $card = app(CreateAccount::class)->execute($this->eduardo, [
        'name' => 'Cartão', 'type' => 'credit_card', 'visibility' => 'shared', 'currency' => 'BRL',
        'card_closing_day' => 3, 'card_due_day' => 10, 'card_limit' => 500000,
    ]);
    $group = app(CreateInstallmentPurchase::class)->execute($this->eduardo, [
        'account_id' => $card->id, 'category_id' => Category::where('name', 'Casa')->where('household_id', $this->household->id)->value('id'),
        'amount' => 30000, 'installments' => 3, 'date' => '2026-09-25', 'description' => 'Cadeira',
    ]);
    $c = ($this->attach)($group->transactions()->first(), 'c.pdf');
    app(DeleteTransaction::class)->execute($this->eduardo, $group->transactions()->first());
    Storage::disk('google')->assertMissing($c->path);

    expect(Attachment::count())->toBe(0);
});

it('envia, lista e exclui pelo modal Comprovantes, com o clipe na lista', function () {
    $transaction = ($this->transaction)($this->shared);
    $this->actingAs($this->eduardo);

    Livewire::test(ListTransactions::class)
        ->mountTableAction('attachments', $transaction)
        ->set('mountedActions.0.data.files', [UploadedFile::fake()->createWithContent('boleto.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n")])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $attachment = Attachment::sole();

    expect($attachment->name)->toBe('boleto.pdf');

    Livewire::test(ListTransactions::class)
        ->assertTableColumnStateSet('attachments_count', 1, $transaction)
        ->call('deleteAttachment', $attachment->id);

    expect(Attachment::count())->toBe(0);
});
