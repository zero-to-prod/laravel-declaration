<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models;

final readonly class FlightCreated
{
    public function __construct(public Flight $flight) {}
}
