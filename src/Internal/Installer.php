<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal;

use Closure;
use Illuminate\Support\Facades\File;

/**
 * The published configuration file, as the install command and the install
 * tool both write it.
 *
 * @internal
 */
final class Installer
{
    public static function path(): string
    {
        return config_path('laravel-declaration.php');
    }

    /** The shipped configuration file, carrying the given values. */
    public static function configuration(bool $mcp, string $handle): string
    {
        return str_replace([
            "'enabled' => true,",
            "'handle' => 'laravel-declaration',",
        ], [
            "'enabled' => ".var_export($mcp, true).',',
            "'handle' => ".var_export($handle, true).',',
        ], File::get(dirname(__DIR__, 2).'/config/laravel-declaration.php'));
    }

    /**
     * @param  Closure(): bool  $overwrite  Consulted only when the file on disk says something else
     * @return 'created'|'unchanged'|'updated'|'kept'
     */
    public static function write(string $contents, Closure $overwrite): string
    {
        $file = self::path();
        $status = match (true) {
            ! File::exists($file) => 'created',
            File::get($file) === $contents => 'unchanged',
            $overwrite() => 'updated',
            default => 'kept',
        };

        if ($status === 'created' || $status === 'updated') {
            File::ensureDirectoryExists(dirname($file));
            File::put($file, $contents);
        }

        return $status;
    }
}
