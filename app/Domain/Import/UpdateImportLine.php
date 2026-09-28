<?php

namespace App\Domain\Import;

use App\Enums\ImportLineAction;
use App\Enums\ImportLineStatus;
use App\Models\Category;
use App\Models\ImportLine;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Ajustes na revisão: o que fazer com a linha, categoria e descrição.
 */
class UpdateImportLine
{
    /**
     * @param  array{action?: string|ImportLineAction, category_id?: int|string|null, final_description?: string|null}  $data
     */
    public function execute(User $actor, ImportLine $line, array $data): ImportLine
    {
        $batch = ReviewGuard::batchFor($actor, $line);

        if (array_key_exists('action', $data)) {
            $action = $data['action'] instanceof ImportLineAction ? $data['action'] : ImportLineAction::from((string) $data['action']);

            $error = match (true) {
                $line->status === ImportLineStatus::Duplicate && $action !== ImportLineAction::Skip => 'Linha já importada antes: só pode ser ignorada.',
                $action === ImportLineAction::Settle && $line->matched_transaction_id === null => 'Não há previsto correspondente a esta linha.',
                default => null,
            };

            if ($error !== null) {
                throw ValidationException::withMessages(['action' => $error]);
            }

            $line->action = $action;
        }

        if (array_key_exists('category_id', $data)) {
            $categoryId = $data['category_id'] !== null && $data['category_id'] !== '' ? (int) $data['category_id'] : null;

            if ($categoryId !== null && ! Category::withoutGlobalScopes()->where('household_id', $batch->household_id)->whereKey($categoryId)->exists()) {
                throw ValidationException::withMessages(['category_id' => 'Categoria não encontrada.']);
            }

            $line->category_id = $categoryId;
        }

        if (array_key_exists('final_description', $data)) {
            $line->final_description = Text::clean((string) $data['final_description']) ?: $line->description;
        }

        $line->save();

        return $line;
    }
}
