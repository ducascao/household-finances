<?php

namespace App\Domain\Google;

use App\Contracts\GoogleOAuth;
use App\Models\GoogleConnection;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Conexão da conta Google do lar (só administradores). O "state" guardado na sessão protege o callback contra CSRF.
 */
class ConnectGoogleDrive
{
    public const SESSION_KEY = 'google_oauth_state';

    public function __construct(
        private readonly GoogleOAuth $oauth,
    ) {}

    public function start(User $actor): string
    {
        $household = $this->adminHousehold($actor);
        $state = Str::random(40);

        session()->put(self::SESSION_KEY, ['state' => $state, 'household_id' => $household->id]);

        return $this->oauth->authorizationUrl($state);
    }

    public function complete(User $actor, ?string $state, ?string $code, ?string $error = null): GoogleConnection
    {
        $household = $this->adminHousehold($actor);
        $expected = session()->pull(self::SESSION_KEY);

        if (! is_array($expected) || ! hash_equals((string) $expected['state'], (string) $state) || $expected['household_id'] !== $household->id) {
            throw ValidationException::withMessages(['google' => 'Sessão de conexão inválida ou expirada. Tente conectar de novo.']);
        }

        if ($error !== null || $code === null || $code === '') {
            throw ValidationException::withMessages(['google' => 'A conexão com o Google foi cancelada.']);
        }

        try {
            $token = $this->oauth->exchangeCode($code);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['google' => $e->getMessage()]);
        }

        $connection = GoogleConnection::withoutGlobalScopes()->where('household_id', $household->id)->first() ?? new GoogleConnection;
        $connection->household_id = $household->id;
        $connection->fill([
            'email' => $token['email'],
            'refresh_token' => $token['refresh_token'],
            'root_folder' => $connection->root_folder ?? (string) config('attachments.default_root_folder'),
            'connected_by' => $actor->id,
        ])->save();

        return $connection;
    }

    public function disconnect(User $actor): void
    {
        $household = $this->adminHousehold($actor);
        $connection = GoogleConnection::withoutGlobalScopes()->where('household_id', $household->id)->first();

        if ($connection !== null) {
            $this->oauth->revoke($connection->refresh_token);
            $connection->delete();
        }
    }

    public function renameRootFolder(User $actor, string $folder): GoogleConnection
    {
        $household = $this->adminHousehold($actor);
        $folder = trim($folder);

        if ($folder === '' || mb_strlen($folder) > 100 || str_contains($folder, '/')) {
            throw ValidationException::withMessages(['root_folder' => 'Informe um nome de pasta válido (sem "/").']);
        }

        $connection = GoogleConnection::withoutGlobalScopes()->where('household_id', $household->id)->firstOrFail();
        $connection->root_folder = $folder;
        $connection->save();

        return $connection;
    }

    private function adminHousehold(User $actor): Household
    {
        $household = $actor->currentHousehold;

        if ($household === null || ! $actor->isAdminOf($household)) {
            throw ValidationException::withMessages(['google' => 'Só um administrador do lar pode conectar o Google Drive.']);
        }

        return $household;
    }
}
