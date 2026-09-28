<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View;

use Illuminate\View\View;

/** `composer: {Menus@primary: [...]}`: called positionally with the View. */
final class Menus
{
    public function primary(View $view): void
    {
        $view->with('nav', 'primary');
    }
}
