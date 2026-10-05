<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Internal\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use ZeroToProd\LaravelDeclaration\Internal\Installer;

/**
 * Asks for every value the package can be configured with, writes them to
 * config/laravel-declaration.php, and writes the manifest skeleton when none exists.
 *
 * @internal
 */
class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'laravel-declaration:install';

    /** @var string */
    protected $description = 'Configure the package';

    public function handle(): int
    {
        $default = Config::string('laravel-declaration.mcp.handle', 'laravel-declaration');
        $mcp = $this->confirm('Register the MCP server that documents the package to coding agents?', Config::boolean('laravel-declaration.mcp.enabled', true));
        $handle = $mcp ? $this->text('The handle the MCP server is registered under', $default) : $default;

        $file = Installer::path();

        $this->components->twoColumnDetail($file, Installer::write(
            Installer::configuration($mcp, $handle),
            fn (): bool => $this->confirm('['.$file.'] differs from these answers. Overwrite it?', true),
        ));

        $this->components->twoColumnDetail(Installer::manifestPath(), Installer::writeManifest(Installer::manifest()));

        $this->components->info('Installed. The configuration takes effect on the next boot.');

        return self::SUCCESS;
    }

    private function text(string $question, string $default): string
    {
        $answer = $this->ask($question, $default);

        return is_string($answer) ? $answer : $default;
    }
}
