<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services;

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Contracts\Pdf;

class DomPdf implements Pdf
{
    public function render(): string
    {
        return 'dompdf';
    }
}
