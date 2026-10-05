<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\Interpreter;

use Illuminate\Contracts\Foundation\Application;

/** The reference targets the `closure` vocabulary resolves. */
final class References
{
    /** @var list<array<string, mixed>> */
    public static array $seen = [];

    public function __invoke(string $expression, Application $app): string
    {
        self::$seen[] = ['invoke' => $expression, 'app' => $app::class];

        return strtoupper($expression);
    }

    public static function compile(string $expression): string
    {
        self::$seen[] = ['compile' => $expression];

        return "<?php echo $expression; ?>";
    }

    public static function spread(string $first, string ...$rest): string
    {
        self::$seen[] = ['spread' => [$first, $rest]];

        return implode(',', [$first, ...$rest]);
    }
}
