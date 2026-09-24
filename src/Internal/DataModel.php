<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

use Illuminate\Support\Collection;
use Zerotoprod\DataModelHelper\DataModelHelper;

/**
 * The published configuration file, as the install command and the install
 * tool both write it.
 *
 * @internal
 */
trait DataModel
{
    use DataModelHelper;
    use \Zerotoprod\DataModel\DataModel;

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->collect()->toArray();
    }

    /** @return Collection<string, mixed> */
    public function collect(): Collection
    {
        return collect($this->toArray());
    }
}
