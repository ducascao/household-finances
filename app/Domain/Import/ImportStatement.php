<?php

namespace App\Domain\Import;

use App\Domain\Import\Parsers\CsvParser;
use App\Domain\Import\Parsers\OfxParser;
use App\Domain\Import\Parsers\ParsedLine;
use App\Domain\Import\Parsers\StatementParseException;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportFormat;
use App\Enums\ImportLineAction;
use App\Enums\ImportLineStatus;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\ImportBatch;
use App\Models\ImportLine;
use App\Models\ImportProfile;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lê o extrato e cria um lote em revisão. Nada entra nos lançamentos até a confirmação.
 *
 * Cada linha vira: duplicada (hash já importado na conta), correspondente a um previsto
 * (mesmo valor, vencimento a ±3 dias) ou nova (categorizada pelas regras).
 */
class ImportStatement
{
    public const MATCH_DAYS = 3;

    public function execute(User $actor, int $accountId, string $content, string $fileName, ImportFormat $format): ImportBatch
    {
        $account = Account::withoutGlobalScopes()->find($accountId);

        if ($account === null || ! $account->isVisibleTo($actor)) {
            throw ValidationException::withMessages(['account_id' => 'Conta não encontrada.']);
        }

        if ($account->isArchived()) {
            throw ValidationException::withMessages(['account_id' => 'A conta está arquivada.']);
        }

        try {
            $parsed = $this->parser($account, $format)->parse($content);
        } catch (StatementParseException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return DB::transaction(fn (): ImportBatch => $this->createBatch($actor, $account, $parsed, $fileName, $format));
    }

    /**
     * @param  list<ParsedLine>  $parsed
     */
    private function createBatch(User $actor, Account $account, array $parsed, string $fileName, ImportFormat $format): ImportBatch
    {
        $batch = new ImportBatch([
            'account_id' => $account->id,
            'user_id' => $actor->id,
            'file_name' => $fileName,
            'format' => $format,
            'status' => ImportBatchStatus::Reviewing,
        ]);
        $batch->household_id = $account->household_id;
        $batch->save();

        $hashes = LineHasher::hashes($account->id, $parsed);
        $imported = Transaction::withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->whereIn('import_hash', $hashes)
            ->pluck('import_hash')
            ->flip();
        $classifier = new LineClassifier($account->household_id);
        $matchedIds = [];

        foreach ($parsed as $index => $row) {
            $line = new ImportLine([
                'import_batch_id' => $batch->id,
                'line_number' => $row->lineNumber,
                'date' => $row->date,
                'description' => $row->description !== '' ? $row->description : '(sem descrição)',
                'amount' => $row->amount,
                'currency' => $account->currency,
                'hash' => $hashes[$index],
            ]);
            $line->household_id = $account->household_id;

            if ($imported->has($hashes[$index])) {
                $line->status = ImportLineStatus::Duplicate;
                $line->action = ImportLineAction::Skip;
            } elseif (($match = $this->scheduledMatch($account, $row, $matchedIds)) !== null) {
                $matchedIds[] = $match->id;
                $line->status = ImportLineStatus::Match;
                $line->action = ImportLineAction::Settle;
                $line->matched_transaction_id = $match->id;
                $line->category_id = $match->category_id;
                $line->final_description = $match->description;
            } else {
                $line->status = ImportLineStatus::New;
                $classifier->classify($line);
            }

            $line->save();
        }

        return $batch;
    }

    /**
     * Previsto da mesma conta, com o mesmo valor e vencimento a ±3 dias (o mais próximo), ainda não usado no lote.
     *
     * @param  list<int>  $alreadyMatched
     */
    private function scheduledMatch(Account $account, ParsedLine $row, array $alreadyMatched): ?Transaction
    {
        return Transaction::withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->where('status', TransactionStatus::Scheduled->value)
            ->where('amount', $row->amount)
            ->whereNull('transfer_id')
            ->whereBetween('due_date', [
                $row->date->copy()->subDays(self::MATCH_DAYS)->toDateString(),
                $row->date->copy()->addDays(self::MATCH_DAYS)->toDateString(),
            ])
            ->whereNotIn('id', $alreadyMatched)
            ->orderByRaw('abs(due_date - ?::date)', [$row->date->toDateString()])
            ->orderBy('id')
            ->first();
    }

    private function parser(Account $account, ImportFormat $format): OfxParser|CsvParser
    {
        if ($format === ImportFormat::Ofx) {
            return new OfxParser;
        }

        $profile = ImportProfile::withoutGlobalScopes()->where('account_id', $account->id)->first();

        if ($profile === null) {
            throw ValidationException::withMessages([
                'file' => 'A conta ainda não tem perfil de CSV. Cadastre em "Perfis de CSV" (há modelos prontos para os bancos) ou use OFX.',
            ]);
        }

        return new CsvParser($profile);
    }
}
