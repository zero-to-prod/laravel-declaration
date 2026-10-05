<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Feature;

use Illuminate\Support\Facades\Blade as BladeFacade;
use Illuminate\View\Compilers\BladeCompiler;
use ReflectionProperty;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockClass;

it('registers blade compiler directives, conditions, components, and stringables', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-blade-').'.yml';
    file_put_contents($file, <<<'YAML'
        calls:
          - method: afterResolving
            args:
              - Illuminate\View\Compilers\BladeCompiler
              - - method: directive
                  args: [uppercase, ZeroToProd\LaravelDeclaration\Tests\Feature\BladeTestHelper::directiveUppercase]
                - {method: if, args: [admin, ZeroToProd\LaravelDeclaration\Tests\Feature\BladeTestHelper::ifAdmin]}
                - {method: component, args: [ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockClass, mock-component]}
                - {method: components, args: [{mock-comp: ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockClass}]}
                - {method: anonymousComponentPath, args: {path: resources/views/components, prefix: ui}}
                - {method: anonymousComponentNamespace, args: {directory: resources/views/namespaced, prefix: ns}}
                - method: stringable
                  args:
                    - ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\MockClass
                    - ZeroToProd\LaravelDeclaration\Tests\Feature\BladeTestHelper::stringableMock
                - {method: withoutDoubleEncoding}
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
