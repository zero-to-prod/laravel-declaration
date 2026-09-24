<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelPackage\Tests\Fixtures\PublicApi;

/**
 * A widget.
 *
 * @phpstan-type Ignored array{}
 */
final class Widget implements Shape
{
    use Named;

    public static int $made = 0;

    /** @param  list<string>  $tags */
    public function __construct(public readonly array $tags = [], protected string $secret = 'sealed') {}

    public function area(): float
    {
        return 1.0;
    }

    /**
     * @param  array<string, int>  $carry
     * @param  array<string, int>  $options
     * @param  mixed  $extra
     */
    public static function tally(
        array &$carry,
        ?string $label = null,
        int|float $weight = 1,
        bool $strict = true,
        array $options = ['depth' => 1],
        $extra = null,
        Status $status = Status::Draft,
        string ...$tags,
    ): static {
        return new self;
    }

    /** @internal */
    public function debug(): string
    {
        return $this->concealed();
    }

    protected function concealed(): string
    {
        return $this->secret;
    }
}
