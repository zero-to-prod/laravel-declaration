<?php

declare(strict_types=1);

use ZeroToProd\LaravelPackage\Internal\Mcp\Server;
use ZeroToProd\LaravelPackage\Internal\Mcp\Tools\Api;

function renderFixtures(): string
{
    return Api::render(
        dirname(__DIR__).'/Fixtures/PublicApi',
        'ZeroToProd\\LaravelPackage\\Tests\\Fixtures\\PublicApi',
    );
}

it('reports the packages own public api', function (): void {
    Server::tool(Api::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertName('api')
        ->assertSee('# Public API');
});

it('omits internal classes from the packages own public api', function (): void {
    expect(Api::render(dirname(__DIR__, 2).'/src', 'ZeroToProd\\LaravelPackage'))
        ->not->toContain('ServiceProvider')
        ->not->toContain('Internal');
});

it('describes the tool so an agent knows when to call it', function (): void {
    $tool = new Api;

    expect($tool->name())->toBe('api')
        ->and($tool->description())->toContain('public API');
});

it('renders a class declaration with its summary', function (): void {
    expect(renderFixtures())
        ->toContain("## ZeroToProd\LaravelPackage\Tests\Fixtures\PublicApi\Widget\n\nA widget.\n\n```php\nfinal class Widget implements ZeroToProd\LaravelPackage\Tests\Fixtures\PublicApi\Shape\n{");
});

it('renders interfaces, traits and enums', function (): void {
    expect(renderFixtures())
        ->toContain('interface Shape')
        ->toContain('public function area(): float;')
        ->toContain('trait Named')
        ->toContain('final enum Status: string implements UnitEnum, BackedEnum')
        ->toContain('public function label(): string;');
});

it('renders public properties and signatures in full', function (): void {
    expect(renderFixtures())
        ->toContain('public static int $made;')
        ->toContain('public readonly array $tags;')
        ->toContain('/** @param  list<string>  $tags */')
        ->toContain('public function __construct(array $tags = [], string $secret = "sealed");')
        ->toContain('public static function tally(array &$carry, ?string $label = null, int|float $weight = 1, bool $strict = true, array $options = {"depth":1}, $extra = null, ZeroToProd\LaravelPackage\Tests\Fixtures\PublicApi\Status $status = \ZeroToProd\LaravelPackage\Tests\Fixtures\PublicApi\Status::Draft, string ...$tags): static;');
});

it('collapses a multi line doc comment onto one line', function (): void {
    expect(renderFixtures())
        ->toContain('/** @param  array<string, int>  $carry @param  array<string, int>  $options @param  mixed  $extra */');
});

it('omits members that are not public api', function (): void {
    expect(renderFixtures())
        ->not->toContain('protected')
        ->not->toContain('concealed')
        ->not->toContain('debug');
});

it('totals the public methods at the bottom of the output', function (): void {
    expect(renderFixtures())->toEndWith("\n\nTotal public methods: 10\n");
});

it('omits internal classes and files that declare no class', function (): void {
    expect(renderFixtures())
        ->not->toContain('Hidden')
        ->not->toContain('Buried')
        ->not->toContain('NoClass');
});
