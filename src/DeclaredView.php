<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Response;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Routing\ViewController;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Illuminate\View\Factory;
use LogicException;
use ReflectionMethod;
use ZeroToProd\LaravelDeclaration\Internal\ManifestStore;

class DeclaredView extends ViewController
{
    /** @var array<string, ReflectionMethod> */
    private static array $signatures = [];

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
            ['template', 'view', 'data', 'status', 'headers', 'deleteCachedView', 'factory'],
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
        $Store = app(ManifestStore::class);

        $resolvedData = array_map(
            static fn (mixed $value): mixed => self::resolveReference($value, $parameters, $Store),
            $data,
        );

        $mergedData = array_merge($resolvedData, $routeParameters);

        if (isset($args['factory'])) {
            if (! is_array($args['factory'])) {
                throw new LogicException('The `factory` dispatch must map a Factory method name to its arguments.');
            }

            if (isset($args['template']) || isset($args['view'])) {
                throw new LogicException("DeclaredView accepts one render source: 'template', 'view' or 'factory'.");
            }

            /** @var array<string, mixed> $factory */
            $factory = $args['factory'];

            return $this->renderFactory($factory, $parameters, $Store, $mergedData, $args);
        }

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
            "DeclaredView requires either 'template', 'view' or 'factory' to be specified in setDefaults."
        );
    }

    /**
     * @param  array<string, mixed>  $factory
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $mergedData
     * @param  array<int|string, mixed>  $args
     */
    private function renderFactory(
        array $factory,
        array $parameters,
        ManifestStore $Store,
        array $mergedData,
        array $args,
    ): Response {
        if (count($factory) !== 1) {
            throw new LogicException('The `factory` dispatch accepts exactly one Factory method per render.');
        }

        $method = array_key_first($factory);
        $Factory = app(Factory::class);

        $arguments = $this->arguments($method, $factory[$method], $parameters, $Store, $mergedData);

        $result = $Factory->{$method}(...$arguments);

        $content = is_string($result) || $result instanceof Renderable ? $result : throw new LogicException(sprintf(
            'Factory::%s() returned %s; the `factory` dispatch renders a View or a string.',
            $method,
            get_debug_type($result),
        ));

        /** @var ResponseFactory $responseFactory */
        $responseFactory = $this->response;
        $status = is_int($args['status']) || is_string($args['status']) ? (int) $args['status'] : 200;

        return $responseFactory->make($content, $status, (array) $args['headers']);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $mergedData
     * @return array<int|string, mixed>
     */
    private function arguments(
        string $method,
        mixed $callArgs,
        array $parameters,
        ManifestStore $Store,
        array $mergedData,
    ): array {
        if ($callArgs === true || $callArgs === null) {
            return [];
        }

        if (! is_array($callArgs)) {
            $callArgs = [$callArgs];
        }

        /** @var array<int|string, mixed> $callArgs */
        $callArgs = array_map(
            static fn (mixed $value): mixed => self::resolveReference($value, $parameters, $Store),
            $callArgs,
        );

        if ($method === 'file') {
            $path = $callArgs['path'] ?? $callArgs[0] ?? null;

            if (is_string($path)) {
                $callArgs[array_key_exists('path', $callArgs) ? 'path' : 0] = $this->absolute($path);
            }
        }

        if (! method_exists(Factory::class, $method)) {
            return $callArgs;
        }

        $Reflection = self::$signatures[$method] ??= new ReflectionMethod(Factory::class, $method);

        foreach ($Reflection->getParameters() as $position => $Parameter) {
            if ($Parameter->getName() !== 'data') {
                continue;
            }

            if (array_key_exists('data', $callArgs)
                || (array_is_list($callArgs) && count($callArgs) > $position)) {
                break;
            }

            if ($Parameter->isDefaultValueAvailable()) {
                $callArgs['data'] = $mergedData;
            }

            break;
        }

        return $callArgs;
    }

    /** @param  array<string, mixed>  $parameters */
    private static function resolveReference(mixed $value, array $parameters, ManifestStore $Store): mixed
    {
        if (is_string($value) && $Store->item('queries', 'name', $value) !== null) {
            return DeclaredQuery::run($value, $parameters);
        }

        return is_string($value) && str_contains(Str::before($value, '@'), '\\')
            ? app()->call($value, $parameters)
            : $value;
    }

    private function absolute(string $path): string
    {
        return Str::startsWith($path, ['/', '\\']) ? $path : app()->basePath($path);
    }
}
