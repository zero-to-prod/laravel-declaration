<?php

declare(strict_types=1);

// AT-13 — container.md — Binding Primitives: "->needs('$variableName')->give($value)".
// The container unwraps the Closure and injects its return value.
return function (): int {
    return 7;
};
