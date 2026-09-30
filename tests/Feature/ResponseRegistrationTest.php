<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Feature;

use Illuminate\Contracts\Routing\ResponseFactory;
use ZeroToProd\LaravelDeclaration\Manifest;

it('returns early when manifest has no responses block', function (): void {
    $manifest = Manifest::from([]);
    expect($manifest->responses)->toBeNull();
});

it('registers response macros from manifest', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-response-').'.yml';
    file_put_contents($file, <<<'YAML'
        responses:
          macro:
            customJson: ZeroToProd\LaravelDeclaration\Tests\Feature\ResponseTestHelper::customJsonMacro
        YAML);

    try {
        $this->withConfig(['laravel-declaration.manifest' => $file]);

        $factory = app(ResponseFactory::class);
        expect($factory->hasMacro('customJson'))->toBeTrue();

        $response = $factory->customJson();
        expect($response)->toBe('macro-called');
    } finally {
        unlink($file);
    }
});

class ResponseTestHelper
{
    public static function customJsonMacro(): string
    {
        return 'macro-called';
    }
}
