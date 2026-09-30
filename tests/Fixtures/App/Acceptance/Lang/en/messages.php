<?php

declare(strict_types=1);

// AT-17 — localization.md — Configuring the Locale: the framework-default fallback
// language. If the declared setFallbackLocale is not applied, this file's `farewell`
// is served instead of the `es` one and the acceptance test fails.
return [
    'greeting' => 'Hello',
    'farewell' => 'Goodbye',
];
