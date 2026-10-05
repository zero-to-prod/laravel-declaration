<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Feature\Engine;

use Closure;
use Composer\Autoload\ClassLoader;
use JsonSchema\Constraints\BaseConstraint;
use JsonSchema\Validator;
use LogicException;
use ZeroToProd\LaravelDeclaration\Internal\Engine\Signature;
use ZeroToProd\LaravelDeclaration\Internal\SchemaGenerator;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine\Arbitrary;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine\Child;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine\Contract;
use ZeroToProd\LaravelDeclaration\Tests\Fixtures\Engine\Grandchild;

/** The engine suites' shared harness: the fixture scope, its curation, the generated schema and a validator. */
final class Support
{
    public const array CLASSES = [Arbitrary::class, Child::class, Grandchild::class, Contract::class];

    /** The per-key curation the fixture suites exercise (§2.2). */
    public const array CURATION = [
        'when' => ['form' => 'entries'],
        'booted' => ['closure' => ['hook']],
        'tags' => ['order' => 'reverse'],
        'set' => ['order' => 'reverse'],
        'label' => ['resolve' => ['label' => 'path']],
        'scale' => ['list' => 'argument'],
    ];

    /** The command's locate/read seam: findFile resolves fixtures and vendor sources alike. */
    public static function source(): Closure
    {
        return static function (string $fqcn): ?string {
            foreach (ClassLoader::getRegisteredLoaders() as $loader) {
                $path = $loader->findFile($fqcn);

                if (is_string($path) && is_file($path)) {
                    return (string) file_get_contents($path);
                }
            }

            return null;
        };
    }

    /**
     * The shipped vocabulary plus the fixture curation as a prior, so the generated fixture schema is self-contained.
     *
     * @return array<string, mixed>
     */
    public static function prior(): array
    {
        /** @var array<string, mixed> $shipped */
        $shipped = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/manifest.schema.json'), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $definitions */
        $definitions = $shipped['definitions'];

        $properties = [];

        foreach (self::CURATION as $method => $curation) {
            $properties[$method] = ['x-manifest' => $curation];
        }

        return [
            'definitions' => [
                ...array_intersect_key($definitions, array_flip(['classString', 'reference', 'phpFile', 'closure', 'concrete', 'path'])),
                Arbitrary::class => ['properties' => $properties],
            ],
            'properties' => [
                'requests' => ['type' => 'array', 'x-manifest' => ['data' => true]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        static $schema = null;

        if ($schema === null) {
            [$schema] = SchemaGenerator::generate(self::CLASSES, self::prior(), self::source());
            file_put_contents(self::path(), SchemaGenerator::encode($schema));
        }

        /** @var array<string, mixed> $schema */
        return $schema;
    }

    public static function path(): string
    {
        return sys_get_temp_dir().'/laravel-declaration-engine-fixture.schema.json';
    }

    public static function signature(string $method, string $class = Arbitrary::class): Signature
    {
        foreach (SchemaGenerator::signatures($class, self::source()) as $signature) {
            if ($signature->name === $method) {
                return $signature;
            }
        }

        throw new LogicException("No signature for $method");
    }

    /** Validates a value against one key of a projected definition. */
    public static function validates(string $method, mixed $value, string $class = Arbitrary::class): bool
    {
        self::schema();

        $validator = new Validator;
        $data = is_array($value) ? BaseConstraint::arrayToObjectRecursive([$method => $value])->{$method} : $value;

        $validator->validate($data, (object) ['$ref' => 'file://'.self::path().'#/definitions/'.$class.'/properties/'.$method]);

        return $validator->isValid();
    }
}
