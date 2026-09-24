<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelPackage\Internal\Mcp\Tools;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/** @internal */
class Readme extends Tool
{
    protected string $description = 'Returns the README';

    public function handle(): Response
    {
        $path = self::path();

        // @codeCoverageIgnoreStart
        if (! is_file($path)) {
            return Response::error(sprintf('The README could not be found at %s.', $path));
        }
        // @codeCoverageIgnoreEnd

        $contents = file_get_contents($path);

        // @codeCoverageIgnoreStart
        if ($contents === false) {
            return Response::error(sprintf('The README at %s could not be read.', $path));
        }
        // @codeCoverageIgnoreEnd

        return Response::text($contents);
    }

    public static function path(): string
    {
        return dirname(__DIR__, 4).'/README.md';
    }
}
