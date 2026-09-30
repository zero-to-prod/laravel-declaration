<?php

declare(strict_types=1);

// Section 5 of docs/declarative-application-acceptance-test-plan.md — path helpers,
// sourced from docs/repos/laravel/docs/helpers.md and docs/repos/laravel/docs/structure.md.
// Relative values resolve under basePath() — the Testbench skeleton.

// AT-18 — helpers.md — app_path() / structure.md — The App Directory.
it('moves the app directory', function (): void {
    $file = $this->manifest(<<<'YAML'
        app:
          useAppPath: src
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(app_path())->toBe(app()->basePath('src'))
        ->and(app_path('Models/User.php'))->toBe(app()->basePath('src/Models/User.php'));
});

// AT-19 — helpers.md — database_path() / structure.md — The Database Directory.
it('moves the database directory', function (): void {
    $file = $this->manifest(<<<'YAML'
        app:
          useDatabasePath: database
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(database_path())->toBe(app()->basePath('database'))
        ->and(database_path('factories/UserFactory.php'))->toBe(app()->basePath('database/factories/UserFactory.php'));
});

// AT-20 — helpers.md — lang_path() / localization.md — Publishing the Language Files.
it('moves the lang directory', function (): void {
    $file = $this->manifest(<<<'YAML'
        app:
          useLangPath: resources/lang
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(lang_path())->toBe(app()->basePath('resources/lang'))
        ->and(lang_path('en/messages.php'))->toBe(app()->basePath('resources/lang/en/messages.php'));
});

// AT-21 — helpers.md — public_path() / structure.md — The Public Directory.
it('moves the public directory', function (): void {
    $file = $this->manifest(<<<'YAML'
        app:
          usePublicPath: public
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(public_path())->toBe(app()->basePath('public'))
        ->and(public_path('css/app.css'))->toBe(app()->basePath('public/css/app.css'));
});

// AT-22 — helpers.md — storage_path() / structure.md — The Storage Directory.
it('moves the storage directory', function (): void {
    $file = $this->manifest(<<<'YAML'
        app:
          useStoragePath: storage
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(storage_path())->toBe(app()->basePath('storage'))
        ->and(storage_path('app/file.txt'))->toBe(app()->basePath('storage/app/file.txt'));
});

// AT-23 — helpers.md — config_path() / structure.md — The Config Directory.
it('moves the config directory', function (): void {
    $file = $this->manifest(<<<'YAML'
        app:
          useConfigPath: config
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(config_path())->toBe(app()->basePath('config'))
        ->and(config_path('app.php'))->toBe(app()->basePath('config/app.php'));
});
