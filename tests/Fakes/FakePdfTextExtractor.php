<?php

namespace Tests\Fakes;

use App\Contracts\PdfTextExtractor;
use RuntimeException;

/**
 * Nos testes o "PDF" já é o texto (fixtures em tests/Fixtures/imports/pdf, como sai do pdftotext -layout).
 */
class FakePdfTextExtractor implements PdfTextExtractor
{
    /** @var list<string|null> */
    public array $passwords = [];

    public function __construct(
        private readonly ?string $password = null,
    ) {}

    public function extract(string $pdf, ?string $password = null): string
    {
        $this->passwords[] = $password;

        if ($this->password !== null && $password !== $this->password) {
            throw new RuntimeException($password === null ? 'O PDF é protegido por senha: informe a senha.' : 'Senha do PDF incorreta.');
        }

        return $pdf;
    }
}
