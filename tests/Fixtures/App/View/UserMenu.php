<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View;

use Illuminate\View\View;

/** `composer: {UserMenu: users.*}`: Laravel calls the default method, `compose`. */
final class UserMenu
{
    public function compose(View $view): void
    {
        $view->with('menu', 'users');
    }
}
