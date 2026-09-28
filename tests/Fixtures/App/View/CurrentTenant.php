<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View;

use Illuminate\View\View;

/** `composer: {CurrentTenant: '*'}`: runs for every view. */
final class CurrentTenant
{
    public function compose(View $view): void
    {
        $view->with('tenant', 'acme');
    }
}
