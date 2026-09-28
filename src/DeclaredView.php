<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Http\Response;
use Illuminate\Routing\ViewController;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class DeclaredView extends ViewController
{
    public function __invoke(mixed ...$args): Response
    {
        $args += ['data' => [], 'status' => 200, 'headers' => []];

        $parameters = [
            ...Arr::except($args, ['view', 'data', 'status', 'headers']),
            'request' => request()->route()->getMetadata('request') === null ? request() : app(DeclaredRequest::class),
        ];

        /** @var array<string, mixed> $data */
        $data = $args['data'];

        $args['data'] = array_map(
            static fn (mixed $value): mixed => is_string($value) && str_contains(Str::before($value, '@'), '\\')
                ? app()->call($value, $parameters)
                : $value,
            $data,
        );

        return parent::__invoke(...$args);
    }
}
