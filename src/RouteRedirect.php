<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class RouteRedirect
{
    use DataModel;

    public const string uri = 'uri';

    public const string destination = 'destination';

    public const string status = 'status';

    public const string builders = 'builders';

    #[Describe([Describe::required => true])]
    public string $uri;

    #[Describe([Describe::required => true])]
    public string $destination;

    #[Describe([Describe::default => 302])]
    public int $status;

    /** @var array<string, mixed> */
    #[Describe([Describe::default => [], Describe::assign => [self::class, 'extractBuilders']])]
    public array $builders;

    /** @return list<mixed> */
    public function arguments(): array
    {
        return [$this->uri, $this->destination, $this->status];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function extractBuilders(mixed $val, array $context): array
    {
        return array_diff_key($context, [self::uri => true, self::destination => true, self::status => true]);
    }
}
