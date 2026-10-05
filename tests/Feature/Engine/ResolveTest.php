<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use ZeroToProd\LaravelDeclaration\Internal\Engine\Resolve;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine\References;

function resolveWith(mixed $value, string $param = 'p', array $curation = []): mixed
{
    return (new Resolve(app()))($value, ['name' => $param, 'type' => null, 'variadic' => false], $curation);
}

function phpFile(string $code): string
{
    $file = tempnam(sys_get_temp_dir(), 'reference-').'.php';
    file_put_contents($file, $code);

    return $file;
}

beforeEach(function (): void {
    References::$seen = [];
});

it('passes a value through untouched when no vocabulary applies', function (): void {
    expect(resolveWith('App\Thing'))->toBe('App\Thing')
        ->and(resolveWith(['a' => 1]))->toBe(['a' => 1])
        ->and(resolveWith(null))->toBeNull();
});

it('rejects a curation value outside the vocabulary', function (): void {
    resolveWith('x', 'p', ['resolve' => ['p' => 'bogus']]);
})->throws(LogicException::class, 'Unknown resolve vocabulary [bogus].');

it('resolves a relative path under the base path and leaves absolute paths and non-strings alone', function (): void {
    expect(resolveWith('resources/views', 'p', ['resolve' => ['p' => 'path']]))->toBe(base_path('resources/views'))
        ->and(resolveWith('/abs', 'p', ['resolve' => ['p' => 'path']]))->toBe('/abs')
        ->and(resolveWith('\\\\share', 'p', ['resolve' => ['p' => 'path']]))->toBe('\\\\share')
        ->and(resolveWith(5, 'p', ['resolve' => ['p' => 'path']]))->toBe(5);
});

it('requires a .php file once and returns its value for phpFile and concrete, anything else untouched', function (): void {
    $file = phpFile('<?php return (object) ["n" => mt_rand()];');

    $first = resolveWith($file, 'p', ['resolve' => ['p' => 'phpFile']]);

    expect($first)->toBeObject()
        ->and(resolveWith($file, 'p', ['resolve' => ['p' => 'concrete']]))->toBe($first)   // required once per process
        ->and(resolveWith('App\Thing', 'p', ['resolve' => ['p' => 'phpFile']]))->toBe('App\Thing')
        ->and(resolveWith(null, 'p', ['resolve' => ['p' => 'concrete']]))->toBeNull()      // `~` = self-binding
        ->and(resolveWith('App\Thing', 'p', ['resolve' => ['p' => 'concrete']]))->toBe('App\Thing');
});

it('returns the Closure a .php closure reference holds and rejects any other return', function (): void {
    $closure = resolveWith(phpFile('<?php return static fn (): string => "from file";'), 'p', ['resolve' => ['p' => 'closure']]);

    expect($closure)->toBeInstanceOf(Closure::class)
        ->and($closure())->toBe('from file');

    $int = phpFile('<?php return 42;');

    expect(fn (): mixed => resolveWith($int, 'p', ['resolve' => ['p' => 'closure']]))
        ->toThrow(LogicException::class, "The reference [$int] must return a Closure, int returned.");
});

it('defaults a closure parameter to the closure vocabulary, and wraps references pairing positional arguments by name', function (): void {
    $typed = ['name' => 'callback', 'type' => 'closure', 'variadic' => false];
    $curated = ['name' => 'hook', 'type' => null, 'variadic' => false];
    $resolve = new Resolve(app());

    $static = $resolve(References::class.'::compile', $typed, []);
    $at = $resolve(References::class.'@handle', $curated, ['closure' => ['hook']]);
    $invokable = $resolve(References::class, $typed, []);
    $function = $resolve('ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine\engine_reference', $typed, []);
    $spread = $resolve(References::class.'::spread', $typed, []);

    expect($static)->toBeInstanceOf(Closure::class)
        ->and($static('$x'))->toBe('<?php echo $x; ?>')
        ->and($at('a'))->toBe('a:2')                                        // the second parameter keeps its default
        ->and($at('a', 7))->toBe('a:7')
        ->and($invokable('hello'))->toBe('HELLO')                            // Application injected by the container
        ->and($function('v'))->toBe('fn:v')
        ->and($spread('a', 'b', 'c'))->toBe('a,b,c')                         // positional arguments reach a variadic target
        ->and(References::$seen[3])->toBe(['invoke' => 'hello', 'app' => Application::class])
        ->and($resolve(5, $typed, []))->toBe(5)                              // a non-string value is untouched
        ->and($resolve('App\Thing', $curated, []))->toBe('App\Thing');       // without the curation the parameter is plain
});
