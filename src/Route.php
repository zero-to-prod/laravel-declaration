<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class Route
{
    use DataModel;

    public const string uri = 'uri';

    #[Describe([Describe::required => true])]
    public string $uri;

    public const string methods = 'methods';

    /** @var string|list<string> */
    #[Describe([Describe::default => 'GET'])]
    public string|array $methods;

    public const string action = 'action';

    /** @var string|list<string>|null */
    #[Describe([Describe::nullable => true])]
    public string|array|null $action;

    public const string builders = 'builders';

    /** @var array<string, mixed> */
    #[Describe([Describe::default => [], Describe::assign => [self::class, 'extractBuilders']])]
    public array $builders;

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function extractBuilders(mixed $val, array $context): array
    {
        return array_diff_key($context, [
            self::uri => true,
            self::methods => true,
            self::action => true,
            'path' => true,
        ]);
    }
}
