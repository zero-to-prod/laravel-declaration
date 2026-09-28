<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\View;

use Illuminate\View\View;

/** `creator: {Breadcrumbs: users.show}`: Laravel calls the default method, `create`, at make(). */
final class Breadcrumbs
{
    public function create(View $view): void
    {
        $view->with('crumb', 'users')->with('title', 'creator');
    }
}
