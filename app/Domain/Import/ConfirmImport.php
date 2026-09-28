<?php

namespace App\Domain\Import;

use App\Domain\Transactions\CreateTransaction;
use App\Domain\Transactions\MarkAsPaid;
use App\Enums\CategoryType;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportLineAction;
use App\Enums\ImportLineStatus;
use App\Enums\TransactionStatus;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\ImportLine;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grava o lote: cria os lançamentos novos, baixa os previstos correspondentes e ignora o resto.
 * Tudo numa transação; cada lançamento criado ou baixado recebe o hash da linha.
 */
class ConfirmImport
{
    public function __construct(
        private readonly CreateTransaction $createTransaction,
        private readonly MarkAsPaid $markAsPaid,
    ) {}

    /**
     * @return array{imported: int, settled: int, skipped: int}
     */
    public function execute(User $actor, ImportBatch $batch): array
    {
        ReviewGuard::batchFor($actor, $batch);

        return DB::transaction(function () use ($actor, $batch): array {
            $counts = ['imported' => 0, 'settled' => 0, 'skipped' => 0];
            $lines = ImportLine::withoutGlobalScopes()->where('import_batch_id', $batch->id)->orderBy('line_number')->get();

            foreach ($lines as $line) {
                $alreadyImported = Transaction::withoutGlobalScopes()
                    ->where('account_id', $batch->account_id)
                    ->where('import_hash', $line->hash)
                    ->exists();

                if ($line->action === ImportLineAction::Skip || $alreadyImported) {
                    if ($alreadyImported && $line->status !== ImportLineStatus::Duplicate) {
                        $line->forceFill(['status' => ImportLineStatus::Duplicate, 'action' => ImportLineAction::Skip])->save();
                    }

                    $counts['skipped']++;

                    continue;
                }

                $transaction = $line->action === ImportLineAction::Settle
                    ? $this->settle($actor, $line)
                    : $this->import($actor, $batch, $line);

                $transaction->forceFill(['import_hash' => $line->hash])->save();
                $line->forceFill(['transaction_id' => $transaction->id])->save();
                $counts[$line->action === ImportLineAction::Settle ? 'settled' : 'imported']++;
            }

            $batch->forceFill(['status' => ImportBatchStatus::Completed, 'confirmed_at' => now()])->save();

            return $counts;
        });
    }

    private function settle(User $actor, ImportLine $line): Transaction
    {
        $scheduled = Transaction::withoutGlobalScopes()->find($line->matched_transaction_id);

        if ($scheduled === null || $scheduled->status !== TransactionStatus::Scheduled) {
            throw ValidationException::withMessages([
                'lines' => "Linha {$line->line_number}: o previsto correspondente não está mais em aberto. Mude a linha para \"Importar\".",
            ]);
        }

        $this->markAsPaid->execute($actor, $scheduled, $line->date, $line->amount->abs()->getMinorAmount()->toInt());

        return $scheduled->refresh();
    }

    private function import(User $actor, ImportBatch $batch, ImportLine $line): Transaction
    {
        $category = $line->category_id !== null ? Category::withoutGlobalScopes()->find($line->category_id) : null;

        if ($category === null) {
            throw ValidationException::withMessages(['lines' => "Linha {$line->line_number}: escolha uma categoria."]);
        }

        $positive = ! $line->amount->isNegative();

        if ($category->type === CategoryType::Income && ! $positive) {
            throw ValidationException::withMessages([
                'lines' => "Linha {$line->line_number}: valor negativo com categoria de receita. Escolha uma categoria de despesa.",
            ]);
        }

        return $this->createTransaction->execute($actor, [
            'account_id' => $batch->account_id,
            'category_id' => $category->id,
            'amount' => $line->amount->abs()->getMinorAmount()->toInt(),
            'date' => $line->date->toDateString(),
            'description' => $line->final_description ?: $line->description,
            'paid_by' => $actor->id,
            // Valor positivo em categoria de despesa é estorno.
            'is_refund' => $category->type === CategoryType::Expense && $positive,
        ]);
    }
}
