<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Blade;

/** The directive whose logic is "updated" between renders for the compiled view cache test (AT-02). */
final class VersionedDatetimeDirective
{
    public static string $format = 'm/d/Y H:i';

    public static function compile(string $expression): string
    {
        return "<?php echo ($expression)->format('".self::$format."'); ?>";
    }
}
