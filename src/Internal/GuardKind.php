<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

use ReflectionAttribute;
use ReflectionEnum;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\ColumnExists;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\ColumnMissing;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\ForeignKeyExists;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\ForeignKeyMissing;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\IndexExists;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\IndexMissing;
use ZeroToProd\LaravelDeclaration\Attributes\Attributes\None;

/** @internal */
enum GuardKind: string
{
    #[None]
    case None = 'none';

    #[ColumnMissing]
    case ColumnMissing = 'column-missing';

    #[ColumnExists]
    case ColumnExists = 'column-exists';

    #[IndexMissing]
    case IndexMissing = 'index-missing';

    #[IndexExists]
    case IndexExists = 'index-exists';

    #[ForeignKeyMissing]
    case ForeignKeyMissing = 'foreignKey-missing';

    #[ForeignKeyExists]
    case ForeignKeyExists = 'foreignKey-exists';

    /** Guard strategy attribute declared on this case. */
    public function guard(): Guard
    {
        static $cache = [];

        return $cache[$this->value] ??= new ReflectionEnum(self::class)
            ->getCase($this->name)
            ->getAttributes(Guard::class, ReflectionAttribute::IS_INSTANCEOF)[0]
            ->newInstance();
    }
}
