<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Requests\Gates;

use Illuminate\Auth\Access\Response;

final class ResponseGate
{
    public function __invoke(): Response
    {
        return Response::allow();
    }
}
