<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use LogicException;
use ZeroToProd\LaravelDeclaration\Manifest;

/** @internal */
class ConfigDeclarationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(Manifest::class, function (Manifest $Manifest): void {
            foreach ($Manifest->config as $file => $values) {
                if (! is_array($values)) {
                    throw new LogicException("The `config.$file` entry must be a map of config keys.");
                }

                Config::set(Arr::prependKeysWith($values, "$file."));
            }
        });
    }
}
