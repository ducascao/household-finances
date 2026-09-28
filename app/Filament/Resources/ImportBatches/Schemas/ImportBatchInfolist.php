<?php

namespace App\Filament\Resources\ImportBatches\Schemas;

use App\Enums\ImportLineAction;
use App\Enums\ImportLineStatus;
use App\Models\ImportBatch;
use App\Models\ImportLine;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ImportBatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $count = fn (ImportBatch $batch, string $column, string $value): int => ImportLine::where('import_batch_id', $batch->id)->where($column, $value)->count();

        return $schema
            ->components([
                Section::make()
                    ->columns(6)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('account.name')->label('Conta'),
                        TextEntry::make('file_name')->label('Arquivo'),
                        TextEntry::make('status')->label('Situação')->badge()
                            ->formatStateUsing(fn (ImportBatch $record): string => $record->status->label()),
                        TextEntry::make('new')->label(ImportLineStatus::New->label().'s')
                            ->state(fn (ImportBatch $record): int => $count($record, 'status', ImportLineStatus::New->value)),
                        TextEntry::make('match')->label('Correspondem a previstos')
                            ->state(fn (ImportBatch $record): int => $count($record, 'status', ImportLineStatus::Match->value)),
                        TextEntry::make('duplicate')->label(ImportLineStatus::Duplicate->label().'s')
                            ->state(fn (ImportBatch $record): int => $count($record, 'status', ImportLineStatus::Duplicate->value)),
                        TextEntry::make('skip')->label('Serão ignoradas')
                            ->state(fn (ImportBatch $record): int => $count($record, 'action', ImportLineAction::Skip->value))
                            ->visible(fn (ImportBatch $record): bool => $record->isReviewing()),
                    ]),
            ]);
    }
}
