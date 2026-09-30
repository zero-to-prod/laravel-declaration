<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Response;
use Illuminate\Routing\ViewController;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use LogicException;

class DeclaredView extends ViewController
{
    /**
     * @param  string  $method
     * @param  array<string, mixed>  $parameters
     */
    public function callAction($method, $parameters): Response
    {
        return $this->{$method}(...$parameters);
    }

    public function __invoke(mixed ...$args): Response
    {
        $args += ['data' => [], 'status' => 200, 'headers' => []];

        $route = request()->route();
        /** @var array<string, mixed> $routeParameters */
        $routeParameters = array_filter($args, static fn (string|int $key): bool => ! in_array(
            $key,
            ['template', 'view', 'data', 'status', 'headers', 'deleteCachedView'],
            true
        ), ARRAY_FILTER_USE_KEY);

        /** @var array<string, mixed> $parameters */
        $parameters = [
            ...$routeParameters,
            'request' => $route->getMetadata('request') === null && ! isset($route->defaults['request'])
                ? request()
                : app(DeclaredRequest::class),
        ];

        /** @var array<string, mixed> $data */
        $data = $args['data'];
        $Manifest = app(Manifest::class);

        $resolvedData = array_map(
            static function (mixed $value) use ($parameters, $Manifest): mixed {
                if (is_string($value) && $Manifest->queries->has($value)) {
                    return DeclaredQuery::run($value, $parameters);
                }

                return is_string($value) && str_contains(Str::before($value, '@'), '\\')
                    ? app()->call($value, $parameters)
                    : $value;
            },
            $data,
        );

        $mergedData = array_merge($resolvedData, $routeParameters);

        if (isset($args['template']) && is_string($args['template'])) {
            if ($routeName = $route->getName()) {
                event("composing: $routeName", [$mergedData]);
            }

            $deleteCachedView = ! isset($args['deleteCachedView']) || (bool) $args['deleteCachedView'];
            $content = Blade::render($args['template'], $mergedData, deleteCachedView: $deleteCachedView);

            /** @var ResponseFactory $responseFactory */
            $responseFactory = $this->response;
            $status = is_int($args['status']) || is_string($args['status']) ? (int) $args['status'] : 200;

            return $responseFactory->make($content, $status, (array) $args['headers']);
        }

        if (isset($args['view'])) {
            return parent::__invoke(...[
                ...$args,
                'data' => $mergedData,
            ]);
        }

        throw new LogicException(
            "DeclaredView requires either 'template' or 'view' to be specified in setDefaults."
        );
    }
}
