<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Blade\Components;

use Illuminate\View\Component;

/** The manually registered component class (AT-09) — lives outside `app/View/Components`. */
final class Alert extends Component
{
    public function __construct(public string $type = 'info') {}

    public function render(): string
    {
        return 'blade.components.alert';
    }
}
