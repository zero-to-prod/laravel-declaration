<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Zerotoprod\DataModel\Describe;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

final readonly class RouteView
{
    use DataModel;

    public const string uri = 'uri';

    public const string view = 'view';

    public const string data = 'data';

    public const string status = 'status';

    public const string headers = 'headers';

    public const string builders = 'builders';

    #[Describe([Describe::required => true])]
    public string $uri;

    #[Describe([Describe::required => true])]
    public string $view;

    /** @var array<string, mixed> */
    #[Describe([Describe::default => []])]
    public array $data;

    #[Describe([Describe::default => 200])]
    public int $status;

    /** @var array<string, mixed> */
    #[Describe([Describe::default => []])]
    public array $headers;

    /** @var array<string, mixed> */
    #[Describe([Describe::default => [], Describe::assign => [self::class, 'extractBuilders']])]
    public array $builders;

    /** @return list<mixed> */
    public function arguments(): array
    {
        return [$this->uri, $this->view, $this->data, $this->status, $this->headers];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function extractBuilders(mixed $val, array $context): array
    {
        return array_diff_key($context, [
            self::uri => true, self::view => true, self::data => true,
            self::status => true, self::headers => true,
        ]);
    }
}
