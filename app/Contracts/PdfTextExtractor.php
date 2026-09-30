<?php

namespace App\Contracts;

/**
 * Extrai o texto de um PDF preservando o layout das colunas.
 * Implementação real: App\Services\Pdf\PopplerPdfTextExtractor (pdftotext).
 */
interface PdfTextExtractor
{
    /**
     * @param  string|null  $password  senha do PDF protegido; usada só na leitura, nunca guardada
     *
     * @throws \RuntimeException com mensagem para o usuário (senha incorreta, arquivo inválido…)
     */
    public function extract(string $pdf, ?string $password = null): string;
}
