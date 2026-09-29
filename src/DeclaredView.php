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
        $manifest = app(Manifest::class);

        $args['data'] = array_map(
            static function (mixed $value) use ($parameters, $manifest): mixed {
                if (is_string($value) && $manifest->queries->has($value)) {
                    return DeclaredQuery::run($value, $parameters);
                }

                return is_string($value) && str_contains(Str::before($value, '@'), '\\')
                    ? app()->call($value, $parameters)
                    : $value;
            },
            $data,
        );

        return parent::__invoke(...$args);
    }
}
