<?php

declare(strict_types=1);

// Section 4 of docs/declarative-application-acceptance-test-plan.md — locale, sourced
// from docs/repos/laravel/docs/localization.md. The `fr` and `en` language files are
// published into the skeleton's `lang` directory by tests/TestCase.php.

// AT-16 — localization.md — Configuring the Locale / Determining the Current Locale.
it('sets the runtime default language', function (): void {
    $file = $this->manifest(<<<'YAML'
        app:
          setLocale: fr
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(app()->currentLocale())->toBe('fr')
        ->and(app()->isLocale('fr'))->toBeTrue()
        ->and(__('messages.greeting'))->toBe('Bonjour');
});

// AT-17 — localization.md — Configuring the Locale.
it('serves strings missing from the default language through the fallback language', function (): void {
    $file = $this->manifest(<<<'YAML'
        app:
          setLocale: fr
          setFallbackLocale: en
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(__('messages.farewell'))->toBe('Goodbye')
        ->and(__('messages.greeting'))->toBe('Bonjour')
        ->and(app()->getFallbackLocale())->toBe('en');
});
