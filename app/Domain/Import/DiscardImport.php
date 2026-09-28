<?php

namespace App\Domain\Import;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use App\Models\ImportLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DiscardImport
{
    /**
     * Descarta o lote em revisão; nenhum lançamento é tocado.
     */
    public function execute(User $actor, ImportBatch $batch): void
    {
        ReviewGuard::batchFor($actor, $batch);

        DB::transaction(function () use ($batch): void {
            ImportLine::withoutGlobalScopes()->where('import_batch_id', $batch->id)->delete();
            $batch->forceFill(['status' => ImportBatchStatus::Discarded])->save();
        });
    }
}
