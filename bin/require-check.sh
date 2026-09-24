#!/usr/bin/env sh

set -e

CRC_VERSION=4.20.0
TOOL_DIR=build/require-check
PHAR="$TOOL_DIR/composer-require-checker-$CRC_VERSION.phar"
TREE="$TOOL_DIR/tree"

mkdir -p "$TOOL_DIR"

if [ ! -f "$PHAR" ]; then
    echo "require-check: downloading ComposerRequireChecker $CRC_VERSION"
    curl -sSLf -o "$PHAR" \
        "https://github.com/maglnet/ComposerRequireChecker/releases/download/$CRC_VERSION/composer-require-checker.phar"
fi

rm -rf "$TREE"
mkdir -p "$TREE"
cp composer.json composer-require-checker.json "$TREE/"
cp -R src "$TREE/"

php -r '
$file = $argv[1];
$json = json_decode(file_get_contents($file), true);
unset($json["require-dev"], $json["scripts"], $json["config"]["allow-plugins"]);
file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
' "$TREE/composer.json"

composer update --working-dir="$TREE" --no-interaction --no-progress --quiet

XDEBUG_MODE=off
export XDEBUG_MODE

exec php "$PHAR" check \
    --config-file="$TREE/composer-require-checker.json" \
    "$TREE/composer.json"
