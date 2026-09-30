<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Feature;

use Illuminate\Pagination\Paginator;
use ReflectionProperty;
use ZeroToProd\LaravelDeclaration\Manifest;

it('returns early when manifest has no pagination block', function (): void {
    $manifest = Manifest::from([]);
    expect($manifest->pagination)->toBeNull();
});

it('configures paginator styles and views from manifest', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-pagination-').'.yml';
    file_put_contents($file, <<<'YAML'
        pagination:
          useTailwind: true
          useBootstrapFive: true
          defaultView: pagination::custom
          defaultSimpleView: pagination::simple-custom
        YAML);

    try {
        $this->withConfig(['laravel-declaration.manifest' => $file]);

        $defaultView = new ReflectionProperty(Paginator::class, 'defaultView')->getValue();
        $defaultSimpleView = new ReflectionProperty(Paginator::class, 'defaultSimpleView')->getValue();

        expect($defaultView)->toBe('pagination::custom')
            ->and($defaultSimpleView)->toBe('pagination::simple-custom');
    } finally {
        unlink($file);
    }
});
