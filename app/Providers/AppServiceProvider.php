<?php

namespace App\Providers;

use App\Contracts\AttachmentStorage;
use App\Contracts\ExchangeRateProvider;
use App\Contracts\ForeignQuoteProvider;
use App\Contracts\GoogleOAuth;
use App\Contracts\InterestRateProvider;
use App\Contracts\PdfTextExtractor;
use App\Contracts\QuoteProvider;
use App\Services\Google\FilesystemAttachmentStorage;
use App\Services\Google\GoogleClientOAuth;
use App\Services\Pdf\PopplerPdfTextExtractor;
use App\Services\Quotes\BrapiQuoteProvider;
use App\Services\Quotes\FinnhubQuoteProvider;
use App\Services\Rates\BcbPtaxProvider;
use App\Services\Rates\BcbSgsProvider;
use Google\Service\Drive;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GoogleOAuth::class, GoogleClientOAuth::class);
        $this->app->bind(AttachmentStorage::class, FilesystemAttachmentStorage::class);
        $this->app->bind(QuoteProvider::class, BrapiQuoteProvider::class);
        $this->app->bind(ForeignQuoteProvider::class, FinnhubQuoteProvider::class);
        $this->app->bind(InterestRateProvider::class, BcbSgsProvider::class);
        $this->app->bind(ExchangeRateProvider::class, BcbPtaxProvider::class);
        $this->app->bind(PdfTextExtractor::class, PopplerPdfTextExtractor::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Falha alto em lazy loading, atributos descartados e atributos inexistentes.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Disco "google": Google Drive da conta conectada do lar (refresh_token e pasta raiz vêm da conexão).
        Storage::extend('google', function ($app, array $config): FilesystemAdapter {
            $client = GoogleClientOAuth::client();
            $client->fetchAccessTokenWithRefreshToken((string) $config['refresh_token']);

            $adapter = new GoogleDriveAdapter(new Drive($client), (string) $config['folder']);

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
    }
}
