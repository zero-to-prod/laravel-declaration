<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Blade;

/** The documented `@datetime` directive handler (AT-01) — generates `<?php echo ($var)->format('m/d/Y H:i'); ?>`. */
final class DatetimeDirective
{
    public static function compile(string $expression): string
    {
        return "<?php echo ($expression)->format('m/d/Y H:i'); ?>";
    }
}
