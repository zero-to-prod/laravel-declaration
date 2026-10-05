<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Routing\ResponseFactory;
use ZeroToProd\LaravelDeclaration\Internal\Resolve;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Contracts\Pdf;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\HookLog;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Hooks\WarmConnections;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services\DomPdf;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Interpreter\References;

function phpFile(string $code): string
{
    $file = tempnam(sys_get_temp_dir(), 'reference-').'.php';
    file_put_contents($file, $code);

    return $file;
}

beforeEach(function (): void {
    $this->interpreter = Resolve::interpreter($this->app);
    HookLog::reset();
});

it('binds a class-string concrete untouched', function (): void {
    $this->interpreter->body($this->app, ['bind' => [Pdf::class => DomPdf::class]]);

    expect(app(Pdf::class))->toBeInstanceOf(DomPdf::class);
});

it('runs a map under an untyped callback as a body on the application', function (): void {
    $this->interpreter->body($this->app, ['booted' => ['bind' => [Pdf::class => DomPdf::class]]]);

    expect(app(Pdf::class))->toBeInstanceOf(DomPdf::class);
});

it('calls an invokable Class hook with the container-injected application', function (): void {
    $this->interpreter->body($this->app, ['booted' => [WarmConnections::class]]);

    expect(HookLog::entries())->toContain('WarmConnections:booted');
});

it('binds the value of a .php instance reference', function (): void {
    $file = phpFile('<?php return (object) ["capacity" => 60];');

    $this->interpreter->body($this->app, ['instance' => ['app.rate_limiter' => $file]]);

    expect($this->app->make('app.rate_limiter'))->toBeObject();
});

it('resolves a relative path under the base path', function (): void {
    $this->interpreter->body($this->app, ['useAppPath' => 'src']);

    expect($this->app->path())->toBe(base_path('src'));
});

it('rejects a .php closure reference that does not return a Closure', function (): void {
    $file = phpFile('<?php return "not a closure";');

    $this->interpreter->body($this->app, ['booted' => [$file]]);
})->throws(LogicException::class, 'must return a Closure, string returned.');

it('wraps Class@method, Class::method, invokable and function references into container-called closures', function (): void {
    $this->interpreter->body($this->app, ['afterResolving' => [
        ResponseFactory::class => ['macro' => [
            'tsv' => References::class.'@spread',
            'csv' => References::class.'::compile',
            'yaml' => References::class,
            'ini' => 'ZeroToProd\LaravelDeclaration\Tests\Fixtures\Interpreter\interpreter_reference',
        ]],
    ]]);

    expect(response()->tsv('a', 'b'))->toBe('a,b')
        ->and(response()->csv('x'))->toBe('<?php echo x; ?>')
        ->and(response()->yaml('hello'))->toBe('HELLO')
        ->and(response()->ini('v'))->toBe('fn:v')
        ->and(References::$seen)->toBe([
            ['spread' => ['a', ['b']]],
            ['compile' => 'x'],
            ['invoke' => 'hello', 'app' => Application::class],
        ]);
});

it('passes a non-string under a closure vocabulary untouched and lets PHP reject it', function (): void {
    $this->interpreter->body($this->app, ['booted' => [5]]);
})->throws(Error::class);
