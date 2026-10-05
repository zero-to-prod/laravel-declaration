<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Engine;

use Closure;
use Illuminate\Contracts\Container\Container;
use LogicException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;

/**
 * ρ(value, p) — a manifest value becomes an argument (§1.5). The vocabulary names are the shared schema
 * definitions; the curated `resolve: {param: vocabulary}` wins, a closure parameter defaults to `closure`,
 * everything else passes through untouched.
 *
 * @phpstan-import-type Param from Signature
 *
 * @internal
 */
final class Resolve
{
    /** @var array<string, mixed> every `.php` reference is required once per process */
    private static array $files = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  Param  $param
     * @param  array<string, mixed>  $curation
     *
     * @throws LogicException a curation value outside the vocabulary (§6 row 4), or a `.php` closure reference that returned something else (§6 row 5)
     */
    public function __invoke(mixed $value, array $param, array $curation): mixed
    {
        $resolve = $curation['resolve'] ?? [];
        $vocabulary = is_array($resolve) ? ($resolve[$param['name']] ?? null) : null;

        if ($vocabulary === null && Forms::isClosure($param, $curation)) {
            $vocabulary = 'closure';
        }

        return match ($vocabulary) {
            null => $value,
            'closure' => $this->closure($value),
            'phpFile' => $this->phpFile($value),
            'concrete' => $this->phpFile($value),                                   // `~` is already null (self-binding); a class-string is untouched
            'path' => is_string($value) ? $this->path($value) : $value,
            default => throw new LogicException('Unknown resolve vocabulary ['.(is_scalar($vocabulary) ? (string) $vocabulary : get_debug_type($vocabulary)).'].'),
        };
    }

    /**
     * `Class@method` | `Class::method` | invokable `Class` | `function` → a Closure that pairs its positional
     * arguments with the target's parameter names and lets the container inject the rest; `*.php` → the file's
     * Closure.
     */
    private function closure(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        if (str_ends_with($value, '.php')) {
            $closure = $this->phpFile($value);

            return $closure instanceof Closure
                ? $closure
                : throw new LogicException("The reference [$value] must return a Closure, ".get_debug_type($closure).' returned.');
        }

        $container = $this->container;
        $reflect = $this->reflect(...);                                                   // bound here: Macroable rebinds the wrapper's scope to the macro host

        return static function (mixed ...$arguments) use ($container, $reflect, $value): mixed {
            $named = [];

            foreach ($reflect($value)->getParameters() as $position => $parameter) {
                if ($parameter->isVariadic()) {
                    break;
                }

                if (array_key_exists($position, $arguments)) {
                    $named[$parameter->getName()] = $arguments[$position];

                    unset($arguments[$position]);
                }
            }

            return $container->call($value, [...$named, ...array_values($arguments)]);
        };
    }

    private function reflect(string $reference): ReflectionFunctionAbstract
    {
        if (str_contains($reference, '@')) {
            [$class, $method] = explode('@', $reference, 2);

            return new ReflectionMethod($class, $method);
        }

        if (str_contains($reference, '::')) {
            return ReflectionMethod::createFromMethodName($reference);
        }

        return class_exists($reference) ? new ReflectionMethod($reference, '__invoke') : new ReflectionFunction($reference);
    }

    /** `*.php` → required once, its return value (any type); anything else untouched. */
    private function phpFile(mixed $value): mixed
    {
        if (! is_string($value) || ! str_ends_with($value, '.php')) {
            return $value;
        }

        $path = $this->path($value);

        if (! array_key_exists($path, self::$files)) {
            self::$files[$path] = require $path;
        }

        return self::$files[$path];
    }

    /** A relative path resolves under the base path; one starting with `/` or `\` is used as-is. */
    private function path(string $value): string
    {
        if (str_starts_with($value, '/') || str_starts_with($value, '\\')) {
            return $value;
        }

        $base = $this->container->make('path.base');

        return (is_string($base) ? $base : '').'/'.$value;
    }
}
