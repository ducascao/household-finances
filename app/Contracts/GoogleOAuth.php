<?php

namespace App\Contracts;

/**
 * Fluxo OAuth do Google (consentimento e troca do código). Implementação real em
 * App\Services\Google\GoogleClientOAuth; nos testes, um fake.
 */
interface GoogleOAuth
{
    /**
     * URL de consentimento (acesso offline, escopos drive.file + e-mail).
     */
    public function authorizationUrl(string $state): string;

    /**
     * Troca o código do callback por um refresh token e o e-mail da conta.
     *
     * @return array{refresh_token: string, email: string}
     *
     * @throws \RuntimeException quando o Google não devolve refresh token ou recusa o código
     */
    public function exchangeCode(string $code): array;

    /**
     * Revoga o token (ao desconectar). Falhas são ignoradas.
     */
    public function revoke(string $refreshToken): void;
}
