<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Contracts;

interface Pdf
{
    public function render(): string;
}
