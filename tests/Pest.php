<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/**
 * Conteúdo de um extrato de exemplo em tests/Fixtures/imports.
 */
function importFixture(string $name): string
{
    return (string) file_get_contents(__DIR__."/Fixtures/imports/{$name}");
}
