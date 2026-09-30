<?php

use App\Contracts\PdfTextExtractor;
use App\Domain\Accounts\CreateAccount;
use App\Domain\Household\CreateHousehold;
use App\Domain\Import\ConfirmImport;
use App\Domain\Import\ImportStatement;
use App\Domain\Import\Pdf\PdfParser;
use App\Enums\ImportFormat;
use App\Enums\ImportLineStatus;
use App\Filament\Resources\ImportBatches\Pages\ListImportBatches;
use App\Models\Account;
use App\Models\ImportBatch;
use App\Models\ImportLine;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Pdf\PopplerPdfTextExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Fakes\FakePdfTextExtractor;

beforeEach(function () {
    Storage::fake('local');

    $this->household = app(CreateHousehold::class)->execute('Casa', 'Eduardo', 'eduardo@example.com', 'senha-forte');
    $this->eduardo = User::where('email', 'eduardo@example.com')->sole();
    $this->eduardo->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    $this->checking = Account::factory()->ownedBy($this->eduardo)->private()->create(['name' => 'Nubank']);
    $this->card = app(CreateAccount::class)->execute($this->eduardo, [
        'name' => 'Nubank cartão', 'type' => 'credit_card', 'visibility' => 'private', 'currency' => 'BRL',
        'card_closing_day' => 3, 'card_due_day' => 10, 'card_limit' => 500000,
    ]);

    $this->extractor = new FakePdfTextExtractor;
    app()->instance(PdfTextExtractor::class, $this->extractor);

    $this->import = fn (string $fixture, ?Account $account = null, ?string $password = null) => app(ImportStatement::class)->execute(
        $this->eduardo, ($account ?? $this->card)->id, importFixture("pdf/{$fixture}"), 'fatura.pdf', ImportFormat::Pdf, $password,
    );
});

it('importa a fatura em PDF para a revisão e guarda o leitor usado', function () {
    $batch = ($this->import)('nubank-fatura.txt');

    expect($batch->format)->toBe(ImportFormat::Pdf)
        ->and($batch->reader)->toBe('Nubank — fatura do cartão')
        ->and(ImportLine::where('import_batch_id', $batch->id)->count())->toBe(8)
        ->and(ImportLine::where('import_batch_id', $batch->id)->sum('amount'))->toEqual(-24975 - 2399 - 1495 - 1495 - 1330 - 37989 + 3500 + 62564);
});

it('o mesmo PDF importado duas vezes não duplica', function () {
    $first = ($this->import)('nubank-fatura.txt');
    app(ConfirmImport::class)->execute($this->eduardo, $first);
    expect(Transaction::where('account_id', $this->card->id)->count())->toBe(8);

    $second = ($this->import)('nubank-fatura.txt');
    expect(ImportLine::where('import_batch_id', $second->id)->pluck('status')->unique()->all())->toBe([ImportLineStatus::Duplicate]);

    app(ConfirmImport::class)->execute($this->eduardo, $second);
    expect(Transaction::where('account_id', $this->card->id)->count())->toBe(8);
});

it('fatura em conta corrente é recusada com mensagem clara', function () {
    expect(fn () => ($this->import)('nubank-fatura.txt', $this->checking))
        ->toThrow(ValidationException::class, 'fatura de cartão');
});

it('PDF com senha: sem senha ou com senha errada recusa; a senha não é guardada', function () {
    app()->instance(PdfTextExtractor::class, $extractor = new FakePdfTextExtractor('12345678900'));

    expect(fn () => ($this->import)('nubank-conta.txt', $this->checking))->toThrow(ValidationException::class, 'informe a senha')
        ->and(fn () => ($this->import)('nubank-conta.txt', $this->checking, 'errada'))->toThrow(ValidationException::class, 'Senha do PDF incorreta');

    $batch = ($this->import)('nubank-conta.txt', $this->checking, '12345678900');

    expect($extractor->passwords)->toBe([null, 'errada', '12345678900'])
        ->and(json_encode(ImportBatch::withoutGlobalScopes()->findOrFail($batch->id)->getAttributes()))->not->toContain('12345678900');
});

it('pdftotext: monta o comando com a senha e traduz os erros', function () {
    Process::fake([
        '*' => Process::sequence()
            ->push(Process::result(output: 'texto do pdf'))
            ->push(Process::result(errorOutput: 'Command Line Error: Incorrect password', exitCode: 1))
            ->push(Process::result(errorOutput: 'Syntax Error', exitCode: 1)),
    ]);
    $extractor = new PopplerPdfTextExtractor;

    expect(trim($extractor->extract('%PDF-1.4 conteúdo', 'segredo')))->toBe('texto do pdf');
    Process::assertRan(fn ($process): bool => is_array($process->command)
        && array_slice($process->command, 0, 8) === ['pdftotext', '-layout', '-enc', 'UTF-8', '-opw', 'segredo', '-upw', 'segredo']
        && end($process->command) === '-');

    expect(fn () => $extractor->extract('%PDF-1.4'))->toThrow(RuntimeException::class, 'informe a senha')
        ->and(fn () => $extractor->extract('%PDF-1.4'))->toThrow(RuntimeException::class, 'Não foi possível ler o PDF')
        ->and(fn () => $extractor->extract('não é pdf'))->toThrow(RuntimeException::class, 'não é um PDF');
});

it('pdftotext de verdade: lê um PDF simples com o leitor do PicPay', function () {
    if (! is_executable('/usr/bin/pdftotext')) {
        $this->markTestSkipped('pdftotext não instalado');
    }

    $text = (new PopplerPdfTextExtractor)->extract(minimalPdf([
        'PicPay',
        'Extrato da conta',
        '02/09/2026 10:15   Pix recebido de Maria      + R$ 200,00',
        '03/09/2026 18:40   Boleto Internet            - R$ 99,90',
    ]));

    expect(array_map(fn ($line): int => $line->amount, (new PdfParser(false))->parse($text)))->toBe([20000, -9990]);
});

it('envia o PDF com senha pela tela e abre a revisão', function () {
    $this->actingAs($this->eduardo);
    app()->instance(PdfTextExtractor::class, $extractor = new FakePdfTextExtractor('12345678900'));

    Livewire::test(ListImportBatches::class)
        ->mountAction('importStatement')
        ->set('mountedActions.0.data.account_id', $this->card->id)
        ->set('mountedActions.0.data.file', UploadedFile::fake()->createWithContent('fatura.pdf', importFixture('pdf/nubank-fatura.txt')))
        ->set('mountedActions.0.data.password', '12345678900')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $batch = ImportBatch::sole();

    expect($batch->format)->toBe(ImportFormat::Pdf)
        ->and($batch->reader)->toBe('Nubank — fatura do cartão')
        ->and(Storage::disk('local')->allFiles('imports'))->toBe([]);

    $this->get(route('filament.app.resources.import-batches.view', $batch))->assertSuccessful()->assertSee('PDF lido como: Nubank — fatura do cartão');
});

/**
 * PDF mínimo (uma página, fonte Courier) com as linhas dadas, só ASCII.
 *
 * @param  list<string>  $lines
 */
function minimalPdf(array $lines): string
{
    $stream = "BT /F1 9 Tf 20 800 Td 12 TL\n";

    foreach ($lines as $line) {
        $stream .= '('.addcslashes($line, '()\\').") Tj T*\n";
    }

    $stream .= 'ET';
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>',
        '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];

    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
}
