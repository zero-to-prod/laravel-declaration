<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes;

use Attribute;
use ZeroToProd\LaravelDeclaration\DeclaredModel;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class ClassDefault {}
