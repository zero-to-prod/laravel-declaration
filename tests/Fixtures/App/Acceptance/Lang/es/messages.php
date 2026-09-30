<?php

declare(strict_types=1);

// AT-17 — localization.md — Configuring the Locale: the fallback language file holding
// the string missing from the default language. Deliberately not `en` — the framework
// default fallback — so AT-17's assertions only hold when setFallbackLocale is applied.
return [
    'farewell' => 'Adiós',
];
