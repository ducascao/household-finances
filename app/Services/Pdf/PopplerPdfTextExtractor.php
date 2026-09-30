<?php

namespace App\Services\Pdf;

use App\Contracts\PdfTextExtractor;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Texto do PDF pelo `pdftotext -layout` (poppler-utils, instalado na imagem do app).
 * O PDF vai para um arquivo temporário apagado logo depois da leitura.
 */
class PopplerPdfTextExtractor implements PdfTextExtractor
{
    public function extract(string $pdf, ?string $password = null): string
    {
        if (! str_contains(substr($pdf, 0, 1024), '%PDF')) {
            throw new RuntimeException('O arquivo não é um PDF.');
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $pdf);

        $command = ['pdftotext', '-layout', '-enc', 'UTF-8'];

        if ($password !== null && $password !== '') {
            array_push($command, '-opw', $password, '-upw', $password);
        }

        try {
            $result = Process::timeout(60)->run([...$command, $path, '-']);
        } finally {
            @unlink($path);
        }

        if ($result->failed()) {
            throw new RuntimeException(match (true) {
                str_contains(strtolower($result->errorOutput()), 'password') => $password !== null && $password !== ''
                    ? 'Senha do PDF incorreta.'
                    : 'O PDF é protegido por senha: informe a senha.',
                $result->exitCode() === 127 => 'O leitor de PDF (pdftotext) não está instalado no servidor.',
                default => 'Não foi possível ler o PDF.',
            });
        }

        return $result->output();
    }
}
