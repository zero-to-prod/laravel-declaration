<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelPackage\Internal\Mcp\Tools;

use FilesystemIterator;
use JsonException;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionEnum;
use ReflectionException;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use SplFileInfo;
use UnitEnum;

/** @internal */
class Api extends Tool
{
    protected string $name = 'api';

    protected string $description = 'Lists the public API.';

    private const string PREAMBLE = <<<'MARKDOWN'
        # Public API

        Every class below is part of the supported surface. 
        Anything not listed here is internal and may change in any release.
        MARKDOWN;

    public function handle(): Response
    {
        return Response::text(self::render(dirname(__DIR__, 3), 'ZeroToProd\\LaravelPackage'));
    }

    /**
     * @param  string  $directory  Root of a PSR-4 source tree
     * @param  string  $namespace  PSR-4 prefix that $directory maps to
     */
    public static function render(string $directory, string $namespace): string
    {
        $classes = self::classes($directory, $namespace);

        $stubs = array_map(self::stub(...), $classes);

        $total = array_sum(array_map(
            static fn (ReflectionClass $class): int => count(self::methods($class)),
            $classes,
        ));

        return implode("\n\n", [self::PREAMBLE, ...$stubs, 'Total public methods: '.$total])."\n";
    }

    /** @return list<ReflectionClass<object>> */
    private static function classes(string $directory, string $namespace): array
    {
        $names = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $names[] = $namespace.'\\'.str_replace(DIRECTORY_SEPARATOR, '\\', substr($file->getPathname(), strlen($directory) + 1, -4));
            }
        }

        sort($names);

        $classes = [];

        foreach ($names as $name) {
            if (! class_exists($name) && ! interface_exists($name) && ! trait_exists($name)) {
                continue;
            }

            $class = new ReflectionClass($name);

            if (str_starts_with($name, $namespace.'\\Internal\\') || str_contains((string) $class->getDocComment(), '@internal')) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * @param  ReflectionClass<object>  $ReflectionClass
     *
     * @throws ReflectionException
     */
    private static function stub(ReflectionClass $ReflectionClass): string
    {
        $members = [
            ...array_map(self::property(...), self::properties($ReflectionClass)),
            ...array_map(self::method(...), self::methods($ReflectionClass)),
        ];

        return sprintf(
            "## %s\n\n%s```php\n%s\n{\n%s}\n```",
            $ReflectionClass->getName(),
            self::summary($ReflectionClass->getDocComment()),
            self::declaration($ReflectionClass),
            implode('', array_map(static fn (string $member): string => self::indent($member)."\n", $members)),
        );
    }

    /**
     * @param  ReflectionClass<object>  $ReflectionClass
     *
     * @throws ReflectionException
     */
    private static function declaration(ReflectionClass $ReflectionClass): string
    {
        $parent = $ReflectionClass->getParentClass();

        return implode(' ', array_filter([
            $ReflectionClass->isFinal() ? 'final' : '',
            $ReflectionClass->isAbstract() && ! $ReflectionClass->isInterface() ? 'abstract' : '',
            $ReflectionClass->isReadOnly() ? 'readonly' : '',
            self::keyword($ReflectionClass),
            self::shortName($ReflectionClass),
            $parent === false ? '' : 'extends '.$parent->getName(),
            $ReflectionClass->getInterfaceNames() === [] ? '' : 'implements '.implode(', ', $ReflectionClass->getInterfaceNames()),
        ]));
    }

    /** @param  ReflectionClass<object>  $ReflectionClass */
    private static function keyword(ReflectionClass $ReflectionClass): string
    {
        return match (true) {
            $ReflectionClass->isInterface() => 'interface',
            $ReflectionClass->isEnum() => 'enum',
            $ReflectionClass->isTrait() => 'trait',
            default => 'class',
        };
    }

    /**
     * @param  ReflectionClass<object>  $ReflectionClass
     *
     * @throws ReflectionException
     */
    private static function shortName(ReflectionClass $ReflectionClass): string
    {
        $name = $ReflectionClass->getName();
        $backing = is_a($name, UnitEnum::class, true) ? new ReflectionEnum($name)->getBackingType() : null;

        return $backing instanceof ReflectionType ? $ReflectionClass->getShortName().': '.$backing : $ReflectionClass->getShortName();
    }

    /**
     * @param  ReflectionClass<object>  $ReflectionClass
     * @return list<ReflectionProperty>
     */
    private static function properties(ReflectionClass $ReflectionClass): array
    {
        return array_values(array_filter(
            $ReflectionClass->getProperties(ReflectionProperty::IS_PUBLIC),
            static fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === $ReflectionClass->getName()
                && ! str_contains((string) $property->getDocComment(), '@internal'),
        ));
    }

    /**
     * @param  ReflectionClass<object>  $ReflectionClass
     * @return list<ReflectionMethod>
     */
    private static function methods(ReflectionClass $ReflectionClass): array
    {
        $filter = $ReflectionClass->isTrait()
            ? ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED
            : ReflectionMethod::IS_PUBLIC;

        return array_values(array_filter(
            $ReflectionClass->getMethods($filter),
            static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $ReflectionClass->getName()
                && ! str_contains((string) $method->getDocComment(), '@internal'),
        ));
    }

    private static function property(ReflectionProperty $ReflectionProperty): string
    {
        return self::doc($ReflectionProperty->getDocComment()).sprintf(
            'public %s%s%s$%s;',
            $ReflectionProperty->isStatic() ? 'static ' : '',
            $ReflectionProperty->isReadOnly() ? 'readonly ' : '',
            self::type($ReflectionProperty->getType()),
            $ReflectionProperty->getName(),
        );
    }

    private static function method(ReflectionMethod $ReflectionMethod): string
    {
        $returnType = $ReflectionMethod->getReturnType();

        return self::doc($ReflectionMethod->getDocComment()).sprintf(
            '%s %sfunction %s(%s)%s;',
            $ReflectionMethod->isProtected() ? 'protected' : 'public',
            $ReflectionMethod->isStatic() ? 'static ' : '',
            $ReflectionMethod->getName(),
            implode(', ', array_map(self::parameter(...), $ReflectionMethod->getParameters())),
            $returnType instanceof ReflectionType ? ': '.$returnType : '',
        );
    }

    private static function parameter(ReflectionParameter $ReflectionParameter): string
    {
        return sprintf(
            '%s%s%s$%s%s',
            self::type($ReflectionParameter->getType()),
            $ReflectionParameter->isPassedByReference() ? '&' : '',
            $ReflectionParameter->isVariadic() ? '...' : '',
            $ReflectionParameter->getName(),
            $ReflectionParameter->isDefaultValueAvailable() ? ' = '.self::value($ReflectionParameter->getDefaultValue()) : '',
        );
    }

    private static function type(?ReflectionType $ReflectionType): string
    {
        return $ReflectionType instanceof ReflectionType ? $ReflectionType.' ' : '';
    }

    /** @throws JsonException */
    private static function value(mixed $value): string
    {
        return is_object($value)
            ? var_export($value, true)
            : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function doc(string|false $comment): string
    {
        if ($comment === false) {
            return '';
        }

        return preg_replace(['/\s*\n[ \t]*\*\/$/', '/\s*\n[ \t]*\*[ \t]?/'], [' */', ' '], $comment)."\n";
    }

    private static function summary(string|false $comment): string
    {
        $summary = trim(explode(' @', self::doc($comment))[0], "/* \n");

        return $summary === '' ? '' : $summary."\n\n";
    }

    private static function indent(string $member): string
    {
        return implode("\n", array_map(static fn (string $line): string => '    '.$line, explode("\n", $member)));
    }
}
