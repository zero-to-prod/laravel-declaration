<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Config;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use ZeroToProd\LaravelDeclaration\Internal\Installer;

/**
 * What [laravel-declaration:install] does, without a prompt to answer.
 *
 * @internal
 */
#[IsIdempotent]
class Install extends Tool
{
    protected string $description = 'Installs the package by writing its configuration file and, when none exists, the manifest skeleton. Mirrors the laravel-declaration:install command.';

    /** @return array<string, mixed> */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'enabled' => $schema->boolean()
                ->description('Register the MCP server that documents the package to coding agents. Defaults to the current setting.'),
            'handle' => $schema->string()
                ->description('The handle the MCP server is registered under. Defaults to the current setting.'),
            'overwrite' => $schema->boolean()
                ->description('Rewrite the configuration file when it already says something else. Defaults to false, which keeps the file as it is.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $enabled = $request->has('enabled') ? $request->boolean('enabled') : Config::boolean('laravel-declaration.mcp.enabled', true);
        $handle = $request->has('handle') ? $request->string('handle')->toString() : Config::string('laravel-declaration.mcp.handle', 'laravel-declaration');

        $status = Installer::write(
            Installer::configuration($enabled, $handle),
            static fn (): bool => $request->boolean('overwrite'),
        );

        $manifest = Installer::writeManifest(Installer::manifest()) === 'created'
            ? sprintf(' The manifest skeleton was written to %s.', Installer::manifestPath())
            : sprintf(' The manifest at %s was left alone.', Installer::manifestPath());

        return Response::text(match ($status) {
            'kept' => sprintf('%s says something else and was left alone. Call again with overwrite=true to replace it.', Installer::path()),
            'unchanged' => sprintf('%s already says this. Nothing was written.', Installer::path()),
            default => sprintf('%s %s. The configuration takes effect on the next boot.', Installer::path(), $status),
        }.$manifest);
    }
}
