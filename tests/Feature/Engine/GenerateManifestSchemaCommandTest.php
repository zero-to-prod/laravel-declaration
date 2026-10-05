<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\Internal\SchemaGenerator;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine\Arbitrary;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine\Child;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator\Basic;

function tempSchema(?string $contents = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'manifest-').'.json';   // the .json path itself does not exist yet

    if ($contents !== null) {
        file_put_contents($path, $contents);
    }

    return $path;
}

it('bootstraps a schema from the given classes into a new file and records the scope', function (): void {
    $out = tempSchema();

    $this->artisan('declaration:generate-manifest-schema', ['classes' => [Arbitrary::class, Child::class], '--out' => $out])
        ->expectsOutputToContain('Generated 2 definitions')
        ->assertSuccessful()
        ->run();

    $schema = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);

    expect($schema['x-manifest']['classes'])->toBe([Arbitrary::class, Child::class])
        ->and(array_keys($schema['definitions']))->toBe([Arbitrary::class, Child::class, 'bodies'])
        ->and($schema['definitions'][Arbitrary::class]['properties'])->toHaveKey('route')
        ->and($schema['properties'])->toHaveKey(Arbitrary::class);   // a static receiver
});

it('regenerates from the file\'s own scope when no classes are given, byte-identically', function (): void {
    $out = tempSchema();

    $this->artisan('declaration:generate-manifest-schema', ['classes' => [Basic::class], '--out' => $out])->assertSuccessful()->run();
    $first = file_get_contents($out);

    $this->artisan('declaration:generate-manifest-schema', ['--out' => $out])
        ->expectsOutputToContain('Generated 1 definitions')
        ->assertSuccessful()
        ->run();

    expect(file_get_contents($out))->toBe($first);
});

it('carries the curation of a --from prior into the --out file', function (): void {
    $from = tempSchema(SchemaGenerator::encode([
        'definitions' => [Basic::class => ['properties' => ['label' => ['description' => 'curated label', 'x-manifest' => ['resolve' => ['label' => 'path']]]]]],
        'properties' => ['extra' => ['type' => 'object', 'x-manifest' => ['data' => true]]],
    ]));
    $out = tempSchema();

    $this->artisan('declaration:generate-manifest-schema', ['classes' => [Basic::class], '--from' => $from, '--out' => $out])->assertSuccessful()->run();

    $schema = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);

    expect($schema['definitions'][Basic::class]['properties']['label']['description'])->toBe('curated label')
        ->and($schema['definitions'][Basic::class]['properties']['label']['x-manifest']['resolve'])->toBe(['label' => 'path'])
        ->and($schema['properties']['extra']['x-manifest'])->toBe(['data' => true]);
});

it('checks the file against a regeneration without writing', function (): void {
    $out = tempSchema();

    $this->artisan('declaration:generate-manifest-schema', ['classes' => [Basic::class], '--out' => $out])->assertSuccessful()->run();

    $this->artisan('declaration:generate-manifest-schema', ['--out' => $out, '--check' => true])
        ->expectsOutputToContain('is up to date')
        ->assertSuccessful()
        ->run();

    $edited = (string) preg_replace('/\{"type": "string"\}/', '{"type": "integer"}', (string) file_get_contents($out), 1);   // a structural edit; prose edits are curation and would re-merge
    file_put_contents($out, $edited);

    $this->artisan('declaration:generate-manifest-schema', ['--out' => $out, '--check' => true])
        ->expectsOutputToContain('is out of date')
        ->assertFailed()
        ->run();

    expect(file_get_contents($out))->toBe($edited);   // --check never writes

    $this->artisan('declaration:generate-manifest-schema', ['classes' => [Basic::class], '--out' => tempSchema(), '--check' => true])
        ->expectsOutputToContain('is out of date')   // a missing --out file is out of date
        ->assertFailed()
        ->run();
});

it('reports the curation-only and TODO keys without writing', function (): void {
    $out = tempSchema(SchemaGenerator::encode([
        'x-manifest' => ['classes' => [Basic::class]],
        'definitions' => [Basic::class => ['properties' => ['stale' => ['description' => 'a __call surface']]]],
    ]));
    $before = file_get_contents($out);

    $this->artisan('declaration:generate-manifest-schema', ['--out' => $out, '--report' => true])
        ->expectsOutputToContain('curation-only')
        ->expectsOutputToContain(Basic::class.'::stale')
        ->expectsOutputToContain('todo')
        ->expectsOutputToContain(Basic::class.'::handler')
        ->assertSuccessful()
        ->run();

    expect(file_get_contents($out))->toBe($before);
});

it('pre-flights every class and fails before writing', function (): void {
    $out = tempSchema();

    $this->artisan('declaration:generate-manifest-schema', ['classes' => [Basic::class, 'ZeroToProd\Nope'], '--out' => $out])
        ->expectsOutputToContain('No source file for ZeroToProd\Nope')
        ->assertFailed()
        ->run();

    expect(file_exists($out))->toBeFalse();
});

it('fails when neither the arguments nor the prior declare a scope', function (): void {
    $out = tempSchema();

    $this->artisan('declaration:generate-manifest-schema', ['--out' => $out])
        ->expectsOutputToContain('declares none under x-manifest.classes')
        ->assertFailed()
        ->run();

    $empty = tempSchema('{"definitions": {}}');

    $this->artisan('declaration:generate-manifest-schema', ['--out' => $empty])
        ->expectsOutputToContain('declares none under x-manifest.classes')
        ->assertFailed()
        ->run();
});

it('fails natively when the prior is not valid JSON', function (): void {
    $out = tempSchema('not json');

    expect(fn () => $this->artisan('declaration:generate-manifest-schema', ['classes' => [Basic::class], '--out' => $out])->run())
        ->toThrow(JsonException::class);
});

it('fails natively when the write fails', function (): void {
    $out = tempSchema('{}');
    chmod($out, 0444);

    $previous = error_reporting(E_ALL & ~E_WARNING);   // Laravel converts the E_WARNING to ErrorException; the native RuntimeException is the one under test

    try {
        expect(fn () => $this->artisan('declaration:generate-manifest-schema', ['classes' => [Basic::class], '--out' => $out])->run())
            ->toThrow(RuntimeException::class, 'Failed to write schema to `'.$out.'`.');
    } finally {
        error_reporting($previous);
        chmod($out, 0644);
    }
});

it('keeps the shipped schema a fixed point of its own scope', function (): void {
    $shipped = dirname(__DIR__, 3).'/manifest.schema.json';
    $hash = hash_file('sha256', $shipped);

    $this->artisan('declaration:generate-manifest-schema', ['--check' => true])
        ->expectsOutputToContain('is up to date')
        ->assertSuccessful()
        ->run();

    expect(hash_file('sha256', $shipped))->toBe($hash);
});
