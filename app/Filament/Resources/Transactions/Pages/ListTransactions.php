<?php

namespace App\Filament\Resources\Transactions\Pages;

use App\Filament\Resources\Transactions\Actions\InstallmentActions;
use App\Filament\Resources\Transactions\Actions\TransferActions;
use App\Filament\Resources\Transactions\Concerns\ManagesAttachments;
use App\Filament\Resources\Transactions\TransactionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTransactions extends ListRecords
{
    use ManagesAttachments;

    protected static string $resource = TransactionResource::class;

    /**
     * Ao abrir a lista (ordem crescente de data), vai direto à página com os lançamentos de hoje, respeitando os
     * filtros já aplicados. Só na primeira renderização e quando a URL não pede uma página nem outra ordenação.
     */
    public bool $openOnToday = true;

    protected function getHeaderActions(): array
    {
        return [
            TransferActions::create(),
            InstallmentActions::create(),
            CreateAction::make()
                ->label('Novo lançamento'),
        ];
    }

    public function rendering(): void
    {
        if (! $this->openOnToday) {
            return;
        }

        $this->openOnToday = false;
        $pageName = $this->getTablePaginationPageName();
        $perPage = $this->getTableRecordsPerPage();

        if (request()->has($pageName) || filled($this->tableSort) || ! is_numeric($perPage) || ($query = $this->getFilteredTableQuery()) === null) {
            return;
        }

        $before = $query->whereDate('date', '<', today())->count();
        $this->setPage(intdiv($before, (int) $perPage) + 1, $pageName);
    }
}
