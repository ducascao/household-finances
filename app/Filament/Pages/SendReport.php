<?php

namespace App\Filament\Pages;

use App\Domain\Reports\Email\SendFinancialReport;
use App\Domain\Reports\MonthlySummary as Summary;
use App\Enums\ReportSection;
use App\Enums\ReportView;
use App\Models\Household;
use App\Models\ReportEmail;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Envia por e-mail o resumo do mês, o patrimônio e/ou a carteira para pessoas do lar ou outro e-mail,
 * na visão "só o compartilhado" (padrão) ou com as contas pessoais de quem envia. Lista os envios feitos.
 */
class SendReport extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;

    protected static ?string $slug = 'enviar-por-email';

    protected static ?string $title = 'Enviar por e-mail';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.send-report.page';

    public static function isMailConfigured(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Envios feitos por você')
            ->query(fn () => ReportEmail::query()->where('sender_id', auth()->id())->latest('id'))
            ->emptyStateHeading('Nenhum envio ainda')
            ->emptyStateDescription('Use "Novo envio" para mandar um resumo por e-mail.')
            ->columns([
                TextColumn::make('created_at')->label('Enviado em')->dateTime('d/m/Y H:i'),
                TextColumn::make('recipients')->label('Para')->state(fn (ReportEmail $record): string => implode(', ', $record->recipients))->wrap(),
                TextColumn::make('sections')->label('Partes')
                    ->state(fn (ReportEmail $record): string => collect($record->sections)
                        ->map(fn (string $section): string => ReportSection::tryFrom($section)?->label() ?? $section)
                        ->implode(', ').($record->month !== null ? ' · '.$record->month->format('m/Y') : '')),
                TextColumn::make('view')->label('Visão')->badge()
                    ->state(fn (ReportEmail $record): string => $record->view === ReportView::Shared ? 'Compartilhado' : 'Completa')
                    ->color(fn (ReportEmail $record): string => $record->view === ReportView::Shared ? 'gray' : 'warning'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send')
                ->label('Novo envio')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->modalHeading('Enviar resumo por e-mail')
                ->modalSubmitActionLabel('Enviar')
                ->schema([
                    Section::make('Para quem')->schema([
                        CheckboxList::make('members')
                            ->label('Pessoas do lar')
                            ->options(fn (): array => self::memberOptions(self::sender()))
                            ->columns(1),
                        TagsInput::make('emails')
                            ->label('Outros e-mails')
                            ->placeholder('nome@exemplo.com')
                            ->helperText('Digite o e-mail e tecle Enter.')
                            ->nestedRecursiveRules(['email:rfc']),
                    ]),
                    CheckboxList::make('sections')
                        ->label('O que enviar')
                        ->options(ReportSection::options())
                        ->default([ReportSection::MonthlySummary->value])
                        ->required()
                        ->live(),
                    Select::make('month')
                        ->label('Mês do resumo')
                        ->options(fn (): array => Summary::monthsWithEntries()
                            ->mapWithKeys(fn (Carbon $month): array => [$month->format('Y-m') => ucfirst($month->locale('pt_BR')->translatedFormat('F/Y'))])
                            ->all())
                        ->default(today()->format('Y-m'))
                        ->selectablePlaceholder(false)
                        ->visible(fn (Get $get): bool => in_array(ReportSection::MonthlySummary->value, (array) $get('sections'), true))
                        ->required(fn (Get $get): bool => in_array(ReportSection::MonthlySummary->value, (array) $get('sections'), true)),
                    Radio::make('view')
                        ->label('Visão')
                        ->options(ReportView::options())
                        ->default(ReportView::Shared->value)
                        ->live()
                        ->helperText(fn (Get $get): string => $get('view') === ReportView::Mine->value
                            ? 'Atenção: quem receber vai ver os valores das suas contas pessoais.'
                            : 'Só contas, bens e investimentos compartilhados do lar.')
                        ->required(),
                    Textarea::make('message')
                        ->label('Mensagem (opcional)')
                        ->rows(3)
                        ->maxLength(2000),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        $sent = app(SendFinancialReport::class)->execute(self::sender(), [
                            'recipients' => [...($data['members'] ?? []), ...($data['emails'] ?? [])],
                            'sections' => $data['sections'] ?? [],
                            'view' => $data['view'] ?? null,
                            'month' => $data['month'] ?? null,
                            'message' => $data['message'] ?? null,
                        ]);
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title('Não foi possível enviar')->body($e->getMessage())->send();
                        $action->halt();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('Resumo enviado para '.count($sent->recipients).' destinatário(s)')
                        ->body(self::isMailConfigured() ? 'Chega em instantes.' : 'O e-mail ainda não está configurado: a mensagem ficou só no log do sistema.')
                        ->send();
                }),
        ];
    }

    /**
     * Pessoas do lar atual (inclusive quem envia), por e-mail.
     *
     * @return array<string, string>
     */
    public static function memberOptions(User $user): array
    {
        return Household::find($user->current_household_id)?->users()->orderBy('name')->get()
            ->mapWithKeys(fn (User $member): array => [$member->email => $member->name.' ('.$member->email.')'.($member->is($user) ? ' · você' : '')])
            ->all() ?? [];
    }

    private static function sender(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
