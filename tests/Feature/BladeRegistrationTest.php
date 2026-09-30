<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Feature;

use Illuminate\Support\Facades\Blade as BladeFacade;
use Illuminate\View\Compilers\BladeCompiler;
use ReflectionProperty;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockClass;

it('returns early when manifest has no blade block', function (): void {
    $manifest = Manifest::from([]);
    expect($manifest->blade)->toBeNull();
});

it('registers blade compiler directives, conditions, components, and stringables', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-blade-').'.yml';
    file_put_contents($file, <<<'YAML'
        blade:
          directive:
            uppercase: ZeroToProd\LaravelDeclaration\Tests\Feature\BladeTestHelper::directiveUppercase
          if:
            admin: ZeroToProd\LaravelDeclaration\Tests\Feature\BladeTestHelper::ifAdmin
          component:
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockClass: mock-component
          components:
            mock-comp: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockClass
          anonymousComponentPath:
            - path: resources/views/components
              prefix: ui
          anonymousComponentNamespace:
            - directory: resources/views/namespaced
              prefix: ns
          stringable:
            ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockClass: ZeroToProd\LaravelDeclaration\Tests\Feature\BladeTestHelper::stringableMock
          withoutDoubleEncoding: true
        YAML);

    try {
        $this->withConfig(['laravel-declaration.manifest' => $file]);

        $compiler = app('blade.compiler');
        assert($compiler instanceof BladeCompiler);

        expect(isset($compiler->getCustomDirectives()['uppercase']))->toBeTrue();

        // Trigger directive callback
        $directiveCallback = $compiler->getCustomDirectives()['uppercase'];
        expect($directiveCallback('test'))->toBe('test');

        // Trigger condition check
        expect(BladeFacade::check('admin'))->toBeTrue();

        // Trigger stringable callback
        $ref = new ReflectionProperty($compiler, 'echoHandlers');
        $handlers = $ref->getValue($compiler);
        expect(isset($handlers[MockClass::class]))->toBeTrue();
        $handler = $handlers[MockClass::class];
        expect($handler(new MockClass('test')))->toBe('mock');
    } finally {
        unlink($file);
    }
});

class BladeTestHelper
{
    public static function directiveUppercase(string $expression): string
    {
        return $expression;
    }

    public static function ifAdmin(): bool
    {
        return true;
    }

    public static function stringableMock(object $target): string
    {
        return 'mock';
    }
}
