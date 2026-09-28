<?php

namespace App\Domain\Import;

use App\Enums\ImportLineStatus;
use App\Models\ImportLine;
use App\Models\ImportRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateRuleFromLine
{
    public function __construct(
        private readonly SaveImportRule $saveImportRule,
    ) {}

    /**
     * Cria a regra e já a aplica às linhas novas do lote que ainda não tinham regra e que ela casa.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(User $actor, ImportLine $line, array $data): ImportRule
    {
        $batch = ReviewGuard::batchFor($actor, $line);

        return DB::transaction(function () use ($batch, $data): ImportRule {
            $rule = $this->saveImportRule->execute($batch->household_id, $data);
            $classifier = new LineClassifier($batch->household_id);

            ImportLine::withoutGlobalScopes()
                ->where('import_batch_id', $batch->id)
                ->where('status', ImportLineStatus::New->value)
                ->whereNull('import_rule_id')
                ->get()
                ->filter(fn (ImportLine $pending): bool => $classifier->matches($rule, $pending))
                ->each(function (ImportLine $pending) use ($classifier): void {
                    $classifier->classify($pending);
                    $pending->save();
                });

            return $rule;
        });
    }
}
