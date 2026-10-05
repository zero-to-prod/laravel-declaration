#!/usr/bin/env sh

set -e

if grep -rEn '^use (Illuminate|Symfony|Laravel|Composer|ZeroToProd\\LaravelDeclaration)\\' interpreter; then
    echo "interpreter-check: interpreter/ imports a non-PHP symbol" >&2
    exit 1
fi

echo "interpreter-check: interpreter/ is pure PHP"
