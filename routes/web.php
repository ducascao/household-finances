<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\GoogleCallbackController;
use Illuminate\Support\Facades\Route;

// As telas ficam no painel Filament, servido na raiz (ver AppPanelProvider).

Route::get('/google/callback', GoogleCallbackController::class)->name('google.callback');
Route::get('/anexos/{attachment}', AttachmentController::class)->whereNumber('attachment')->name('attachments.show');
