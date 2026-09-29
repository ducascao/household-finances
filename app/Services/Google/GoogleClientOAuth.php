<?php

namespace App\Services\Google;

use App\Contracts\GoogleOAuth;
use Google\Client;
use Google\Service\Drive;
use RuntimeException;
use Throwable;

class GoogleClientOAuth implements GoogleOAuth
{
    public function authorizationUrl(string $state): string
    {
        $client = self::client();
        $client->setState($state);

        return $client->createAuthUrl();
    }

    public function exchangeCode(string $code): array
    {
        $client = self::client();
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new RuntimeException('O Google recusou a autorização: '.($token['error_description'] ?? $token['error']));
        }

        if (empty($token['refresh_token'])) {
            throw new RuntimeException('O Google não devolveu o token de acesso offline. Remova o acesso do app na sua conta Google e conecte de novo.');
        }

        $email = self::email($token);

        return ['refresh_token' => (string) $token['refresh_token'], 'email' => $email];
    }

    public function revoke(string $refreshToken): void
    {
        try {
            self::client()->revokeToken($refreshToken);
        } catch (Throwable) {
            // Desconectar localmente vale mesmo se o Google não responder.
        }
    }

    public static function client(): Client
    {
        $client = new Client;
        $client->setClientId((string) config('services.google.client_id'));
        $client->setClientSecret((string) config('services.google.client_secret'));
        $client->setRedirectUri((string) config('services.google.redirect_uri'));
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);
        $client->setScopes([Drive::DRIVE_FILE, 'email', 'openid']);

        return $client;
    }

    /**
     * @param  array<string, mixed>  $token
     */
    private static function email(array $token): string
    {
        // id_token (escopo openid) traz o e-mail, sem precisar de outro serviço do Google.
        $payload = self::client()->verifyIdToken((string) ($token['id_token'] ?? ''));

        return is_array($payload) ? (string) ($payload['email'] ?? '') : '';
    }
}
