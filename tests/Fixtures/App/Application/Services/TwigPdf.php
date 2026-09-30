<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Services;

use ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application\Contracts\Pdf;

/** The different concrete the conditional declarations name for `Pdf` (AT-04) — it must not win over the provider-registered binding. */
final class TwigPdf implements Pdf
{
    public function render(): string
    {
        return 'twig';
    }
}
