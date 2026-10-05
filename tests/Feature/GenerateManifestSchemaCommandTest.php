<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Validation\Factory;
use Illuminate\View\Factory as ViewFactory;
use JsonException;
use RuntimeException;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\SchemaGenerator\Basic;

const MANIFEST_CLASSES = [
    Application::class,
    Router::class,
    ViewFactory::class,
    Factory::class,
    Gate::class,
];

function shippedSchemaCopy(): string
{
    $path = tempnam(sys_get_temp_dir(), 'manifest-').'.json';
    file_put_contents($path, file_get_contents(dirname(__DIR__, 2).'/manifest.schema.json'));

    return $path;
}

it('generates definitions for the passed classes into one merged write', function (): void {
    $path = shippedSchemaCopy();

    $before = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    $this->artisan('declaration:generate-manifest-schema', ['classes' => MANIFEST_CLASSES, '--out' => $path])
        ->expectsOutputToContain('Schema key: '.Application::class)
        ->expectsOutputToContain('Added [53]') // Router: 65 native − 12 curated
        ->expectsOutputToContain('Added [40]') // View\Factory
        ->expectsOutputToContain('Added [6]') // Validation\Factory
        ->expectsOutputToContain('Added [13]') // Gate
        ->expectsOutputToContain('Regenerated 5 definitions, 156 keys added')
        ->assertSuccessful()
        ->run();

    $after = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $router = $after['definitions'][Router::class];

    expect(count($router['properties']))->toBe(65) // 12 curated + 53 added
        ->and(count($after['definitions'][ViewFactory::class]['properties']))->toBe(51) // 11 curated + 40 added
        ->and($router['description'])->toBe($before['definitions'][Router::class]['description']) // curated envelope untouched
        ->and($router['properties']['pattern'])->toBe($before['definitions'][Router::class]['properties']['pattern'])
        ->and($router['properties']['bind'])->toBe($before['definitions'][Router::class]['properties']['bind'])
        ->and($after['definitions'][Gate::class]['properties']['policy'])->toBe($before['definitions'][Gate::class]['properties']['policy'])
        ->and($after['properties'])->toBe($before['properties']); // the root properties refs are untouched curation
});

it('creates a new FQCN-keyed definition for a class the schema does not declare yet', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'manifest-').'.json';
    file_put_contents($path, '{"definitions":{},"properties":[]}');

    $this->artisan('declaration:generate-manifest-schema', ['classes' => [Basic::class], '--out' => $path])
        ->expectsOutputToContain('Schema key: '.Basic::class)
        ->expectsOutputToContain('Added [4]: label, retries, handler, register')
        ->assertSuccessful()
        ->run();

    $schema = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect($schema['definitions'][Basic::class]['properties'])->toHaveCount(4)
        ->and($schema['definitions'][Basic::class]['description'])
        ->toBe(Basic::class.' methods: every key is a method name, its value the argument(s).');
});

it('is idempotent: the second run adds nothing and writes identical bytes', function (): void {
    $path = shippedSchemaCopy();

    $this->artisan('declaration:generate-manifest-schema', ['classes' => MANIFEST_CLASSES, '--out' => $path])->assertSuccessful()->run();
    $afterFirst = file_get_contents($path);

    $this->artisan('declaration:generate-manifest-schema', ['classes' => MANIFEST_CLASSES, '--out' => $path])
        ->expectsOutputToContain('Added [0]')
        ->expectsOutputToContain('Regenerated 5 definitions, 0 keys added')
        ->assertSuccessful()
        ->run();

    expect(file_get_contents($path))->toBe($afterFirst);
});

it('fails when the schema file is missing', function (): void {
    $this->artisan('declaration:generate-manifest-schema', [
        'classes' => [Basic::class],
        '--out' => sys_get_temp_dir().'/does-not-exist.json',
    ])
        ->expectsOutputToContain('Schema not found')
        ->assertFailed()
        ->run();
});

it('fails natively when the schema file is not valid json (Rule 3.3)', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'manifest-').'.json';
    file_put_contents($path, 'not json');

    expect(fn () => $this->artisan('declaration:generate-manifest-schema', ['classes' => [Basic::class], '--out' => $path])->run())
        ->toThrow(JsonException::class);
});

it('pre-checks every class and fails before mutating the file', function (): void {
    $path = shippedSchemaCopy();
    $raw = (string) file_get_contents($path);

    $this->artisan('declaration:generate-manifest-schema', ['classes' => ['ZeroToProd\Nope'], '--out' => $path])
        ->expectsOutputToContain('No source file for ZeroToProd\Nope')
        ->assertFailed()
        ->run();

    expect(file_get_contents($path))->toBe($raw); // nothing written — the pre-check precedes any mutation (§2.5)
});

it('fails natively when the write fails (Rule 3.3)', function (): void {
    $path = shippedSchemaCopy();
    chmod($path, 0444);

    // Laravel converts the underlying E_WARNING to ErrorException; silence it
    // so the native RuntimeException from the `false` check propagates.
    $previous = error_reporting(E_ALL & ~E_WARNING);

    expect(fn () => $this->artisan('declaration:generate-manifest-schema', [
        'classes' => MANIFEST_CLASSES,
        '--out' => $path,
    ])->run())
        ->toThrow(RuntimeException::class, 'Failed to write schema to `'.$path.'`.');

    error_reporting($previous);
});

it('never touches the shipped schema file', function (): void {
    $repoSchema = dirname(__DIR__, 2).'/manifest.schema.json';
    $hashBefore = hash_file('sha256', $repoSchema);

    $path = shippedSchemaCopy();

    $this->artisan('declaration:generate-manifest-schema', ['classes' => MANIFEST_CLASSES, '--out' => $path])
        ->assertSuccessful()
        ->run();

    expect(hash_file('sha256', $repoSchema))->toBe($hashBefore); // the generator only edits the file it is pointed at
});
