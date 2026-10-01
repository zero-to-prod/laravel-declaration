<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Blade;

/**
 * The documented `Money` object (AT-04). `__toString` deliberately renders a
 * different value than the declared echo handler, so the test can prove the
 * handler ran instead of `__toString`.
 */
final readonly class Money implements \Stringable
{
    public function __construct(public int $minorUnits) {}

    public function __toString(): string
    {
        return 'raw:'.$this->minorUnits;
    }

    public function formatTo(string $locale): string
    {
        return $locale === 'en_GB'
            ? '£'.number_format($this->minorUnits / 100, 2)
            : 'unsupported locale';
    }
}
