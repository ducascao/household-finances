<?php

namespace App\Domain\Import;

/**
 * Perfis de CSV prontos para os bancos do lar. Colunas numeradas a partir de 1.
 * Baseados nos formatos conhecidos de exportação; os marcados "a conferir" ainda não foram
 * validados com um arquivo real e podem precisar de ajuste na tela do perfil.
 */
class BankPresets
{
    /**
     * @var array<string, array{label: string, profile: array<string, mixed>}>
     */
    public const PRESETS = [
        'nubank_account' => [
            'label' => 'Nubank — conta (CSV: Data, Valor, Identificador, Descrição)',
            'profile' => [
                'delimiter' => ',', 'skip_lines' => 0, 'has_header' => true,
                'date_column' => 1, 'amount_column' => 2, 'description_column' => 4,
                'date_format' => 'd/m/Y', 'decimal_separator' => '.', 'thousands_separator' => null, 'invert_sign' => false,
            ],
        ],
        'nubank_card' => [
            'label' => 'Nubank — cartão (CSV: date, title, amount; compras positivas)',
            'profile' => [
                'delimiter' => ',', 'skip_lines' => 0, 'has_header' => true,
                'date_column' => 1, 'description_column' => 2, 'amount_column' => 3,
                'date_format' => 'Y-m-d', 'decimal_separator' => '.', 'thousands_separator' => null, 'invert_sign' => true,
            ],
        ],
        'bradesco_account' => [
            'label' => 'Bradesco — conta (CSV: Data; Histórico; Docto.; Crédito; Débito; Saldo) — a conferir',
            'profile' => [
                'delimiter' => ';', 'skip_lines' => 1, 'has_header' => true,
                'date_column' => 1, 'description_column' => 2, 'credit_column' => 4, 'debit_column' => 5,
                'date_format' => 'd/m/Y', 'decimal_separator' => ',', 'thousands_separator' => '.', 'invert_sign' => false,
            ],
        ],
        'mercado_pago' => [
            'label' => 'Mercado Pago — conta (CSV: RELEASE_DATE; TRANSACTION_TYPE; REFERENCE_ID; TRANSACTION_NET_AMOUNT; …) — a conferir',
            'profile' => [
                'delimiter' => ';', 'skip_lines' => 0, 'has_header' => true,
                'date_column' => 1, 'description_column' => 2, 'amount_column' => 4,
                'date_format' => 'd-m-Y', 'decimal_separator' => ',', 'thousands_separator' => '.', 'invert_sign' => false,
            ],
        ],
        'picpay' => [
            'label' => 'PicPay — conta (CSV: Data; Descrição; Valor) — a conferir',
            'profile' => [
                'delimiter' => ';', 'skip_lines' => 0, 'has_header' => true,
                'date_column' => 1, 'description_column' => 2, 'amount_column' => 3,
                'date_format' => 'd/m/Y', 'decimal_separator' => ',', 'thousands_separator' => '.', 'invert_sign' => false,
            ],
        ],
    ];

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_map(fn (array $preset): string => $preset['label'], self::PRESETS);
    }
}
