#!/usr/bin/env sh

set -e

HANDLE=${MCP_HANDLE:-laravel-package}
COMMAND=${1:-list}

case "$COMMAND" in
    list)
        REQUEST='{"jsonrpc":"2.0","id":2,"method":"tools/list"}'
        ;;
    call)
        if [ -z "$2" ]; then
            echo "mcp: usage: composer mcp call <tool> [json-arguments]" >&2
            exit 1
        fi
        ARGUMENTS=$3
        if [ -z "$ARGUMENTS" ]; then
            ARGUMENTS='{}'
        fi
        REQUEST=$(printf \
            '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"%s","arguments":%s}}' \
            "$2" "$ARGUMENTS")
        ;;
    *)
        echo "mcp: unknown command '$COMMAND'. Use 'list' or 'call <tool> [json-arguments]'." >&2
        exit 1
        ;;
esac

printf '%s\n' \
    '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"composer-mcp","version":"1"}}}' \
    '{"jsonrpc":"2.0","method":"notifications/initialized"}' \
    "$REQUEST" \
    | vendor/bin/testbench mcp:start "$HANDLE" \
    | php -r '
$status = 0;
$answered = false;
while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true);
    if (!is_array($message) || ($message["id"] ?? null) !== 2) {
        continue;
    }
    $answered = true;
    if (isset($message["error"]["message"])) {
        fwrite(STDERR, "mcp: " . $message["error"]["message"] . PHP_EOL);
        $status = 1;
        continue;
    }
    foreach ($message["result"]["tools"] ?? [] as $tool) {
        echo $tool["name"], PHP_EOL, "    ", $tool["description"], PHP_EOL, PHP_EOL;
    }
    foreach ($message["result"]["content"] ?? [] as $content) {
        echo $content["text"] ?? "", PHP_EOL;
    }
    if ($message["result"]["isError"] ?? false) {
        $status = 1;
    }
}
if (!$answered) {
    fwrite(STDERR, "mcp: the server returned no response to the request." . PHP_EOL);
    $status = 1;
}
exit($status);
'