<?php

namespace App\Domain\Import;

use App\Domain\Import\Parsers\ParsedLine;

/**
 * Hash de deduplicação: conta + data + valor + descrição normalizada + nº da ocorrência entre
 * linhas idênticas do mesmo arquivo (dois cafés iguais no mesmo dia continuam sendo dois lançamentos).
 */
class LineHasher
{
    /**
     * @param  list<ParsedLine>  $lines
     * @return list<string> hashes na mesma ordem das linhas
     */
    public static function hashes(int $accountId, array $lines): array
    {
        $seen = [];
        $hashes = [];

        foreach ($lines as $line) {
            $key = implode('|', [$accountId, $line->date->toDateString(), $line->amount, Text::normalize($line->description)]);
            $occurrence = $seen[$key] = ($seen[$key] ?? 0) + 1;

            $hashes[] = hash('sha256', $key.'|'.$occurrence);
        }

        return $hashes;
    }
}
