<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration;

use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\AppDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\BladeDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\ConfigDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\DatabaseDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\KernelDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\PaginationDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\ProvidersDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\ResponseDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\RouterDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\RoutesDeclarationServiceProvider;
use ZeroToProd\LaravelDeclaration\Providers\ViewDeclarationServiceProvider;

/**
 * Manages the default ServiceProviders registered by the declarative package.
 *
 * @internal
 */
final readonly class DefaultProviders
{
    /** @var list<class-string<ServiceProvider>> */
    private array $providers;

    /** @param list<class-string<ServiceProvider>>|null $providers */
    public function __construct(?array $providers = null)
    {
        if ($providers !== null) {
            $this->providers = $providers;

            return;
        }

        $this->providers = [
            ConfigDeclarationServiceProvider::class,
            AppDeclarationServiceProvider::class,
            RouterDeclarationServiceProvider::class,
            ViewDeclarationServiceProvider::class,
            BladeDeclarationServiceProvider::class,
            ResponseDeclarationServiceProvider::class,
            PaginationDeclarationServiceProvider::class,
            DatabaseDeclarationServiceProvider::class,
            KernelDeclarationServiceProvider::class,
            ProvidersDeclarationServiceProvider::class,
            RoutesDeclarationServiceProvider::class,
        ];
    }

    /** @param  list<class-string<ServiceProvider>>  $providers */
    public function merge(array $providers): self
    {
        return new self(array_values(array_unique([...$this->providers, ...$providers])));
    }

    /** @param  array<class-string<ServiceProvider>, class-string<ServiceProvider>>  $replacements */
    public function replace(array $replacements): self
    {
        /** @var Collection<int, class-string<ServiceProvider>> $current */
        $current = new Collection($this->providers);

        foreach ($replacements as $from => $to) {
            $key = $current->search($from);

            if (is_int($key)) {
                $current = $current->replace([$key => $to]);
            }
        }

        return new self(array_values($current->values()->all()));
    }

    /** @param  list<class-string<ServiceProvider>>  $providers */
    public function except(array $providers): self
    {
        /** @var Collection<int, class-string<ServiceProvider>> $current */
        $current = new Collection($this->providers);

        return new self(array_values($current
            ->diff($providers)
            ->values()
            ->all()));
    }

    /** @return list<class-string<ServiceProvider>> */
    public function toArray(): array
    {
        return $this->providers;
    }
}
