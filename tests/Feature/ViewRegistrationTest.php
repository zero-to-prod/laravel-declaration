<?php

declare(strict_types=1);

$manifest = __DIR__.'/../Fixtures/manifest/view.yml';

it('applies the block when Laravel first resolves the view factory', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    expect(app()->resolved('view'))->toBeFalse()
        ->and(app('view')->getShared())->toMatchArray(['brand' => 'Tenant Console', 'title' => 'shared']);
});

it('adds and prepends locations under basePath', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    expect(app('view')->getFinder()->getPaths())->toBe([
        base_path('resources/declared-views/theme'),
        resource_path('views'),
        base_path('resources/declared-views'),
    ])->and(view('greeting')->render())->toBe("theme\n");
});

it('adds, prepends and replaces namespace hints', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    expect(app('view')->getFinder()->getHints())->toMatchArray([
        'admin' => [base_path('resources/declared-views/admin-theme'), base_path('resources/declared-views/admin')],
        'legacy' => [base_path('resources/declared-views/admin')],
    ])->and(view('admin::dashboard')->render())->toBe("admin-theme\n");
});

it('renders a declared extension with its engine', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    expect(view('page')->render())->toBe("Tenant Console\n");
});

it('layers shared data, route data, creators and composers on a ViewController route', function () use ($manifest): void {
    $this->withConfig(['laravel-declaration.manifest' => $manifest]);

    $this->get('/users/7')->assertOk()->assertSeeText('Tenant Console|creator|7|users|primary|users|acme');
});

it('applies nothing without a view block', function (): void {
    $this->withConfig(['laravel-declaration.manifest' => __DIR__.'/../Fixtures/manifest/requests.yml']);

    expect(array_keys(app('view')->getShared()))->toBe(['__env', 'app'])
        ->and(app('view')->getFinder()->getPaths())->toBe([resource_path('views')]);
});

it('ignores unknown view keys', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-').'.yml';
    file_put_contents($file, <<<'YAML'
        view:
          composers:
            App\View\Composers\UserMenu: users.*
        YAML);

    expect($this->withConfig(['laravel-declaration.manifest' => $file]))->not->toBeNull();
});
