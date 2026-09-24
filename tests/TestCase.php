<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelPackage\Tests;

use Illuminate\Contracts\Config\Repository;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ZeroToProd\LaravelPackage\LaravelPackageServiceProvider;

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
            LaravelPackageServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
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
