<?php

namespace Tests\Fakes;

use App\Contracts\GoogleOAuth;
use RuntimeException;

class FakeGoogleOAuth implements GoogleOAuth
{
    /** @var list<string> */
    public array $revoked = [];

    public function __construct(
        private readonly ?string $refreshToken = 'refresh-token-fake',
        private readonly string $email = 'casa@gmail.com',
    ) {}

    public function authorizationUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/auth?state='.$state;
    }

    public function exchangeCode(string $code): array
    {
        if ($code === 'invalid' || $this->refreshToken === null) {
            throw new RuntimeException('O Google recusou a autorização: invalid_grant');
        }

        return ['refresh_token' => $this->refreshToken, 'email' => $this->email];
    }

    public function revoke(string $refreshToken): void
    {
        $this->revoked[] = $refreshToken;
    }
}
