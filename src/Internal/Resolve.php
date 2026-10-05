<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

use Closure;
use Illuminate\Contracts\Container\Container;
use LogicException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use ZeroToProd\Manifest\Interpreter;

/**
 * ρ — the only place a Laravel opinion about a manifest value lives. A parameter PHP declares as Closure/callable
 * resolves references to closures; the parameters Laravel leaves untyped are named in UNTYPED; everything else
 * reaches Laravel untouched.
 *
 * @internal
 */
final class Resolve
{
    /**
     * Vocabularies for parameters whose declared type says nothing: method → parameter → vocabulary.
     *
     *   closure   `Class@method` | `Class::method` | invokable `Class` | `function` → a container-called Closure;
     *             `*.php` → the file's Closure; a map → λ (a body on the closure's first argument)
     *   phpFile   `*.php` → the file's return value (any type); anything else untouched
     *   concrete  `*.php` → phpFile; `~` and class-strings untouched
     *   path      a relative path → under `path.base`; an absolute path untouched
     */
    private const array UNTYPED = [
        'registered' => ['callback' => 'closure'],
        'booting' => ['callback' => 'closure'],
        'booted' => ['callback' => 'closure'],
        'terminating' => ['callback' => 'closure'],
        'group' => ['routes' => 'closure'],                                  // Router::group(array $attributes, $routes)
        'missing' => ['missing' => 'closure', 'callback' => 'closure'],     // Route::missing($missing); PendingResourceRegistration::missing($callback)
        'whenRequestLifecycleIsLongerThan' => ['handler' => 'closure'],
        'macro' => ['macro' => 'closure'],
        'bind' => ['abstract' => 'concrete', 'concrete' => 'concrete'],
        'bindIf' => ['abstract' => 'concrete', 'concrete' => 'concrete'],
        'singleton' => ['abstract' => 'concrete', 'concrete' => 'concrete'],
        'singletonIf' => ['abstract' => 'concrete', 'concrete' => 'concrete'],
        'scoped' => ['abstract' => 'concrete', 'concrete' => 'concrete'],
        'scopedIf' => ['abstract' => 'concrete', 'concrete' => 'concrete'],
        'instance' => ['instance' => 'phpFile'],
        'give' => ['implementation' => 'concrete'],
        'useAppPath' => ['path' => 'path'],
        'useBootstrapPath' => ['path' => 'path'],
        'useConfigPath' => ['path' => 'path'],
        'useDatabasePath' => ['path' => 'path'],
        'useLangPath' => ['path' => 'path'],
        'usePublicPath' => ['path' => 'path'],
        'useStoragePath' => ['path' => 'path'],
        'useEnvironmentPath' => ['path' => 'path'],
        'addLocation' => ['location' => 'path'],
        'prependLocation' => ['location' => 'path'],
        'addNamespace' => ['hints' => 'path'],
        'prependNamespace' => ['hints' => 'path'],
        'replaceNamespace' => ['hints' => 'path'],
        'anonymousComponentPath' => ['path' => 'path'],
        'anonymousComponentNamespace' => ['directory' => 'path'],
    ];

    /** @var array<string, mixed> every `.php` reference is required once per process */
    private static array $files = [];

    private Interpreter $interpreter;

    private function __construct(private readonly Container $container) {}

    /** The interpreter wired to this ρ — the host's one construction site. */
    public static function interpreter(Container $container): Interpreter
    {
        $resolve = new self($container);

        return $resolve->interpreter = new Interpreter($resolve(...));
    }

    public function __invoke(mixed $value, ?ReflectionParameter $parameter): mixed
    {
        if (! $parameter instanceof ReflectionParameter) {
            return $value;                                                                        // a __call surface: PHP decides
        }

        $vocabulary = self::UNTYPED[$parameter->getDeclaringFunction()->getName()][$parameter->getName()]
            ?? ($this->acceptsClosure($parameter) ? 'closure' : null);

        return match ($vocabulary) {
            null => $value,
            'closure' => is_array($value) && ! array_is_list($value) ? $this->interpreter->closure($value) : $this->closure($value),
            'phpFile', 'concrete' => $this->phpFile($value),
            'path' => is_string($value) ? $this->path($value) : $value,
        };
    }

    private function acceptsClosure(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();
        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

        return array_any($types, static fn (mixed $type): bool => $type instanceof ReflectionNamedType && in_array($type->getName(), [Closure::class, 'callable'], true));
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
        $reflect = $this->reflect(...);                                                          // bound here: Macroable rebinds the wrapper's scope to the macro host

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
