<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use JsonException;
use ReflectionException;
use RuntimeException;
use ZeroToProd\LaravelDeclaration\Internal\SchemaGenerator;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator\Basic;

it('prints the block key, skipped methods and the fragment', function (): void {
    $this->artisan('declaration:generate-schema', ['class' => Basic::class])
        ->expectsOutputToContain('Block: basic')
        ->expectsOutputToContain('Skipped: version, attach')
        ->expectsOutputToContain('-> label($label) when the key is present')
        ->expectsOutputToContain('TODO(register: $name, $class, $callback)')
        ->assertSuccessful()
        ->run();
});

it('derives the block key from the class basename', function (): void {
    $this->artisan('declaration:generate-schema', ['class' => Router::class])
        ->expectsOutputToContain('Block: router')
        ->assertSuccessful()
        ->run();
});

it('excludes parent methods from the projection (Rule 0.4)', function (): void {
    // Illuminate\Foundation\Application extends Container: bind/singleton declare Container.
    $fragment = SchemaGenerator::render(Application::class);

    expect($fragment['properties'])
        ->not->toHaveKey('bind')
        ->not->toHaveKey('singleton')
        ->toHaveKey('basePath');
});

it('includes trait-provided methods (declaring class is the using class)', function (): void {
    $properties = SchemaGenerator::render(Router::class)['properties'];

    expect($properties)->toHaveKey('macro');
});

it('fails natively for an unknown class (Rule 3.3)', function (): void {
    expect(fn (): array => SchemaGenerator::render('ZeroToProd\Nope'))
        ->toThrow(ReflectionException::class);
});

function tempSchema(string $json): string
{
    $path = tempnam(sys_get_temp_dir(), 'schema-').'.json';

    file_put_contents($path, $json);

    return $path;
}

const MINIMAL_SCHEMA = <<<'JSON'
    {
      "$schema": "http://json-schema.org/draft-07/schema#",
      "type": "object",
      "additionalProperties": false,
      "properties": [],
      "definitions": {
        "basic": {
          "description": "curated basic prose",
          "type": ["object", "null"],
          "additionalProperties": false,
          "properties": {
            "label": {
              "description": "curated label prose: it wins over the stub",
              "type": "string"
            }
          }
        }
      }
    }

    JSON;

it('merges the fragment into the schema file in place', function (): void {
    $path = tempSchema(MINIMAL_SCHEMA);

    $this->artisan('declaration:generate-schema', ['class' => Basic::class, '--out' => $path])
        ->expectsOutputToContain('Block: basic')
        ->expectsOutputToContain('Added [3]: retries, handler, register')
        ->assertSuccessful()
        ->run();

    $schema = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect($schema['definitions']['basic']['properties']['label']['description'])
        ->toBe('curated label prose: it wins over the stub') // curation preserved
        ->and($schema['definitions']['basic']['properties'])->toHaveKey('handler')
        ->and(file_get_contents($path))->toContain('  "type": ['); // 2-space canonical style
});

it('is idempotent: the second write changes nothing', function (): void {
    $path = tempSchema(MINIMAL_SCHEMA);

    $this->artisan('declaration:generate-schema', ['class' => Basic::class, '--out' => $path])
        ->assertSuccessful()
        ->run();

    $afterFirst = file_get_contents($path);

    $this->artisan('declaration:generate-schema', ['class' => Basic::class, '--out' => $path])
        ->expectsOutputToContain('Added [0]')
        ->assertSuccessful()
        ->run();

    expect(file_get_contents($path))->toBe($afterFirst);
});

it('fails when the schema file is missing', function (): void {
    $this->artisan('declaration:generate-schema', [
        'class' => Basic::class,
        '--out' => sys_get_temp_dir().'/does-not-exist.json',
    ])
        ->expectsOutputToContain('Schema not found')
        ->assertFailed()
        ->run();
});

it('fails natively when the schema file is not valid json (Rule 3.3)', function (): void {
    $path = tempSchema('not json');

    expect(fn () => $this->artisan('declaration:generate-schema', ['class' => Basic::class, '--out' => $path])->run())
        ->toThrow(JsonException::class);
});

it('leaves the file untouched in print mode', function (): void {
    $path = tempSchema(MINIMAL_SCHEMA);

    $this->artisan('declaration:generate-schema', ['class' => Basic::class])
        ->expectsOutputToContain('-> label($label) when the key is present')
        ->assertSuccessful()
        ->run();

    expect(file_get_contents($path))->toBe(MINIMAL_SCHEMA);
});

it('fails natively when the write fails (Rule 3.3)', function (): void {
    $path = tempSchema(MINIMAL_SCHEMA);
    chmod($path, 0444);

    // Laravel converts the underlying E_WARNING to ErrorException; silence it
    // so the native RuntimeException from the `false` check propagates.
    $previous = error_reporting(E_ALL & ~E_WARNING);

    expect(fn () => $this->artisan('declaration:generate-schema', ['class' => Basic::class, '--out' => $path])->run())
        ->toThrow(RuntimeException::class);

    error_reporting($previous);
});
