<?php

namespace App\Filament\Pages;

use App\Domain\Google\ConnectGoogleDrive;
use App\Models\GoogleConnection;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Conexão da conta Google do lar para guardar comprovantes (e a cópia do backup). Só administradores.
 */
class GoogleDrive extends Page
{
    protected static ?string $slug = 'google-drive';

    protected static ?string $title = 'Google Drive';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloud;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.google-drive';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->currentHousehold !== null && $user->isAdminOf($user->currentHousehold);
    }

    public function connection(): ?GoogleConnection
    {
        return GoogleConnection::query()->first();
    }

    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connect')
                ->label(fn (): string => $this->connection() === null ? 'Conectar Google Drive' : 'Reconectar')
                ->icon(Heroicon::OutlinedLink)
                ->visible(fn (): bool => $this->isConfigured())
                ->action(function (): void {
                    $this->redirect(app(ConnectGoogleDrive::class)->start($this->user()));
                }),
            Action::make('renameFolder')
                ->label('Pasta raiz')
                ->icon(Heroicon::OutlinedFolder)
                ->color('gray')
                ->visible(fn (): bool => $this->connection() !== null)
                ->fillForm(fn (): array => ['root_folder' => $this->connection()?->root_folder])
                ->schema([
                    TextInput::make('root_folder')
                        ->label('Nome da pasta no Drive')
                        ->helperText('Novos comprovantes vão para esta pasta (criada pelo app). Os já enviados continuam onde estão.')
                        ->required()
                        ->maxLength(100),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        app(ConnectGoogleDrive::class)->renameRootFolder($this->user(), (string) $data['root_folder']);
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                        $action->halt();
                    }
                }),
            Action::make('disconnect')
                ->label('Desconectar')
                ->icon(Heroicon::OutlinedXMark)
                ->color('danger')
                ->visible(fn (): bool => $this->connection() !== null)
                ->requiresConfirmation()
                ->modalDescription('Os comprovantes já enviados continuam no Drive, mas não poderão ser abertos pelo app até conectar de novo a mesma conta.')
                ->action(function (): void {
                    app(ConnectGoogleDrive::class)->disconnect($this->user());
                    Notification::make()->success()->title('Google Drive desconectado.')->send();
                }),
        ];
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
