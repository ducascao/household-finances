<?php

namespace App\Providers\Filament;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('')
            ->brandName('Finanças de Casa')
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): View => view('filament.styles'))
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): View => view('filament.theme'))
            ->renderHook(PanelsRenderHook::HEAD_START, fn (): View => view('filament.privacy-head'))
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): View => view('filament.privacy-toggle'))
            ->maxContentWidth(Width::Full)
            // Menu lateral que recolhe para só os ícones (o navegador lembra a escolha).
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('17rem')
            ->login()
            ->profile(isSimple: false)
            // 2FA obrigatório; desligável só em desenvolvimento (AUTH_MFA_ENABLED=false) até a entrega final.
            ->multiFactorAuthentication(
                config('auth.mfa_enabled') ? [AppAuthentication::make()->recoverable()] : [],
                isRequired: (bool) config('auth.mfa_enabled'),
            )
            // Paleta no estilo do Organizze: verde como cor principal e das receitas, vermelho nas despesas.
            ->colors([
                'primary' => Color::hex('#1f9d55'),
                'success' => Color::hex('#1f9d55'),
                'danger' => Color::hex('#e5484d'),
                'warning' => Color::Amber,
                'info' => Color::Sky,
                'gray' => Color::Slate,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
