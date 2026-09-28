<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests;

use Illuminate\Contracts\Config\Repository;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ZeroToProd\LaravelDeclaration\LaravelDeclarationProvider;

abstract class TestCase extends Orchestra
{
    /** @var array<string, mixed> */
    protected array $environmentConfig = [];

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            // Real applications discover this from laravel/mcp's composer
            // manifest. Testbench does not, so it is listed explicitly.
            McpServiceProvider::class,
            LaravelDeclarationProvider::class,
        ];
    }

    /**
     * Applied right after the config files load, before any provider
     * registers — where a host's own config/*.php values sit.
     */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        $config = $app->make(Repository::class);

        foreach ($this->environmentConfig as $key => $value) {
            $config->set($key, $value);
        }
    }

    /** @param  array<string, mixed>  $config */
    protected function withConfig(array $config): static
    {
        $this->environmentConfig = $config;

        $this->refreshApplication();

        return $this;
    }
}
