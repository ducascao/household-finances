<?php

namespace App\Providers;

use App\Contracts\AttachmentStorage;
use App\Contracts\GoogleOAuth;
use App\Services\Google\FilesystemAttachmentStorage;
use App\Services\Google\GoogleClientOAuth;
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
