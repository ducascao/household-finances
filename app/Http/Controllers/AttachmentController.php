<?php

namespace App\Http\Controllers;

use App\Contracts\AttachmentStorage;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serve o comprovante pelo próprio app (nunca por link público do Drive), depois de checar a Policy.
 * ?download=1 força o download; sem ele, PDF e imagem abrem no navegador.
 */
class AttachmentController extends Controller
{
    public function __invoke(Request $request, int $attachment, AttachmentStorage $storage): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->to(route('filament.app.auth.login'));
        }

        $record = Attachment::withoutGlobalScopes()->find($attachment);

        abort_if($record === null || $record->household_id !== $user->current_household_id || $user->cannot('view', $record), 404);

        $disposition = $request->boolean('download') || ! $record->isPreviewable() ? 'attachment' : 'inline';

        return new StreamedResponse(function () use ($storage, $record): void {
            $stream = $storage->readStream($record);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $record->mime_type,
            'Content-Length' => (string) $record->size,
            'Content-Disposition' => $disposition.'; filename*=UTF-8\'\''.rawurlencode($record->name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
