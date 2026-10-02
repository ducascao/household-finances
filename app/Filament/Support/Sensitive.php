<?php

namespace App\Filament\Support;

use Illuminate\Support\HtmlString;

/**
 * Modo privacidade: marca valores em R$ dos painéis para serem borrados quando o modo está ligado.
 * O estado fica no navegador (classe fc-private no <html>); veja resources/views/filament/privacy-*.blade.php.
 */
class Sensitive
{
    public const CSS_CLASS = 'fc-sensitive';

    /**
     * Atributos para colunas de tabela cujo conteúdo (valor e descrição) é sensível:
     * ->extraAttributes(Sensitive::ATTRIBUTES, merge: true).
     */
    public const ATTRIBUTES = ['class' => self::CSS_CLASS];

    /**
     * Texto marcado como sensível (indicadores, cabeçalhos e descrições).
     */
    public static function html(string $text): HtmlString
    {
        return new HtmlString('<span class="'.self::CSS_CLASS.'">'.e($text).'</span>');
    }
}
