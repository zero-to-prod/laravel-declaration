<?php

declare(strict_types=1);

// Section 4 of docs/declarative-application-acceptance-test-plan.md — locale, sourced
// from docs/repos/laravel/docs/localization.md. The `fr`, `en` and `es` language files
// are published into the skeleton's `lang` directory by tests/TestCase.php.

// AT-16 — localization.md — Configuring the Locale / Determining the Current Locale.
it('sets the runtime default language', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls: [{method: registered, args: [[{method: setLocale, args: [fr]}]]}]
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(app()->currentLocale())->toBe('fr')
        ->and(app()->isLocale('fr'))->toBeTrue()
        ->and(__('messages.greeting'))->toBe('Bonjour');
});

// AT-17 — localization.md — Configuring the Locale. The fallback is deliberately not
// the framework default (`en` — the en fixture), so the assertions can only hold when
// the declared setFallbackLocale is applied.
it('serves strings missing from the default language through the fallback language', function (): void {
    $file = $this->manifest(<<<'YAML'
        calls: [{method: registered, args: [[{method: setLocale, args: [fr]}, {method: setFallbackLocale, args: [es]}]]}]
        YAML);

    $this->withConfig(['laravel-declaration.manifest' => $file]);

    expect(__('messages.farewell'))->toBe('Adiós')
        ->and(__('messages.greeting'))->toBe('Bonjour')
        ->and(app()->getFallbackLocale())->toBe('es');
});
