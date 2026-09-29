<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

use Illuminate\Support\Collection;
use ReflectionClass;
use ReflectionProperty;
use Zerotoprod\DataModelHelper\DataModelHelper;

/** @internal */
trait DataModel
{
    use DataModelHelper;
    use \Zerotoprod\DataModel\DataModel;

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @return Collection<string, mixed> */
    public function collect(): Collection
    {
        return collect($this->toArray());
    }

    /**
     * @param  class-string  $attribute
     * @return list<string>
     */
    private static function selected(string $attribute): array
    {
        return array_values(array_map(
            static fn (ReflectionProperty $Property): string => $Property->getName(),
            array_filter(
                new ReflectionClass(static::class)->getProperties(),
                static fn (ReflectionProperty $Property): bool => $Property->getAttributes($attribute) !== [],
            ),
        ));
    }
}
