<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models;

final class FlightObserver
{
    public function creating(Flight $flight): void
    {
        $flight->secret = 'observed';
    }

    public function boarding(Flight $flight): void
    {
        $flight->status = 'boarding';
    }
}
