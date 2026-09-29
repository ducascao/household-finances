<?php

namespace App\Http\Controllers;

use App\Domain\Google\ConnectGoogleDrive;
use App\Filament\Pages\GoogleDrive;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GoogleCallbackController extends Controller
{
    public function __invoke(Request $request, ConnectGoogleDrive $connect): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->to(route('filament.app.auth.login'));
        }

        try {
            $connection = $connect->complete($user, $request->string('state')->toString() ?: null, $request->string('code')->toString() ?: null, $request->string('error')->toString() ?: null);
            Notification::make()->success()->title("Google Drive conectado ({$connection->email}).")->send();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
        }

        return redirect()->to(GoogleDrive::getUrl());
    }
}
