<?php

declare(strict_types=1);

use ZeroToProd\LaravelDeclaration\Internal\ManifestStore;

it('exposes the raw manifest and its blocks', function (): void {
    $store = new ManifestStore(['requests' => [['name' => 'a', 'rules' => []], ['name' => 'b'], 'not an item', ['rules' => []]], 'extra' => ['x' => 1]]);

    expect($store->all())->toHaveKeys(['requests', 'extra'])
        ->and($store->block('extra'))->toBe(['x' => 1])
        ->and($store->block('missing'))->toBeNull()
        ->and($store->items('requests', 'name'))->toBe(['a' => ['name' => 'a', 'rules' => []], 'b' => ['name' => 'b']])
        ->and($store->items('extra', 'name'))->toBeEmpty()
        ->and($store->items('missing', 'name'))->toBeEmpty()
        ->and($store->item('requests', 'name', 'a'))->toBe(['name' => 'a', 'rules' => []])
        ->and($store->item('requests', 'name', 'z'))->toBeNull()
        ->and((new ManifestStore)->all())->toBeEmpty();
});

it('strips exactly the data keys from the body the interpreter applies', function (): void {
    $store = new ManifestStore([
        'registered' => ['bind' => []],
        'requests' => [['name' => 'a']],
        'models' => [['class' => 'X']],
        'queries' => [['name' => 'q']],
        'schema' => ['create' => []],
        'extra' => ['x' => 1],
    ]);

    expect($store->body())->toBe(['registered' => ['bind' => []]])
        ->and($store->all())->toHaveKeys(['registered', 'requests', 'models', 'queries', 'schema', 'extra']);
});

it('is bound from the configured manifest and empty without one', function (): void {
    expect(app(ManifestStore::class)->all())->toBeEmpty();

    $this->withConfig(['laravel-declaration.manifest' => $this->manifest("extra:\n  sitemap: {path: /sitemap.xml}\n")]);

    expect(app(ManifestStore::class)->block('extra'))->toBe(['sitemap' => ['path' => '/sitemap.xml']]);

    $this->withConfig(['laravel-declaration.manifest' => $this->manifest("just a scalar\n")]);

    expect(app(ManifestStore::class)->all())->toBeEmpty();   // a YAML document that is not a map is an empty manifest
});
