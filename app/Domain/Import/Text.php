<?php

namespace App\Domain\Import;

use Illuminate\Support\Str;

class Text
{
    /**
     * Converte para UTF-8 arquivos em Latin-1/Windows-1252 (comuns em bancos brasileiros).
     */
    public static function toUtf8(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    /**
     * Descrição para comparação: minúsculas, sem acentos, espaços simples.
     */
    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii($text))));
    }

    /**
     * Descrição para exibição: espaços simples, sem espaços nas pontas.
     */
    public static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
