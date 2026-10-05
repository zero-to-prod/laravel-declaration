<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Feature;

use Illuminate\Support\Facades\DB;

it('registers database query listeners from manifest', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'manifest-db-').'.yml';
    file_put_contents($file, <<<'YAML'
        calls:
          - method: booted
            args:
              - - method: make
                  args: {abstract: Illuminate\Database\Connection}
                  then: [{method: listen, args: [ZeroToProd\LaravelDeclaration\Tests\Feature\DatabaseTestHelper::listenQuery]}]
        YAML);

    try {
        $this->withConfig(['laravel-declaration.manifest' => $file]);

        DatabaseTestHelper::$recordedQuery = null;
        DB::select('select 1 as val');

        expect(DatabaseTestHelper::$recordedQuery)->not->toBeNull();
    } finally {
        unlink($file);
    }
});

class DatabaseTestHelper
{
    public static ?object $recordedQuery = null;

    public static function listenQuery(object $query): void
    {
        self::$recordedQuery = $query;
    }
}
