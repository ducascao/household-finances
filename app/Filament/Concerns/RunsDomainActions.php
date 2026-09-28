<?php

namespace App\Filament\Concerns;

use Closure;
use Illuminate\Validation\ValidationException;

/**
 * Executa uma ação de domínio e mostra os erros de validação dela nos campos do formulário.
 */
trait RunsDomainActions
{
    /**
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T
     */
    protected function runDomainAction(Closure $action, string $statePath = 'data'): mixed
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            $messages = [];

            foreach ($e->errors() as $key => $errors) {
                $messages["{$statePath}.{$key}"] = $errors;
            }

            throw ValidationException::withMessages($messages);
        }
    }
}
