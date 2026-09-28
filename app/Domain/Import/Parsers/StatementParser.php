<?php

namespace App\Domain\Import\Parsers;

interface StatementParser
{
    /**
     * @return list<ParsedLine>
     *
     * @throws StatementParseException
     */
    public function parse(string $content): array;
}
