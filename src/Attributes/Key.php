<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes;

use Attribute;
use ZeroToProd\LaravelDeclaration\Internal\DataModel;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Key {}
