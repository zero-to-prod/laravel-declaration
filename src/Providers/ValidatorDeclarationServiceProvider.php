<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Factory;
use ZeroToProd\LaravelDeclaration\Manifest;
use ZeroToProd\LaravelDeclaration\Validator;

/** @internal */
class ValidatorDeclarationServiceProvider extends ServiceProvider
{
    public function boot(?Manifest $Manifest = null): void
    {
        if (! $Manifest?->validator instanceof Validator) {
            return;
        }

        $this->callAfterResolving('validator', function (Factory $Factory) use ($Manifest): void {
            /** @var array<string, array<string, string|array{extension: string, message?: string}>> $registries — the four Factory registry-method properties */
            $registries = $Manifest->validator->toArray();

            foreach ($registries as $method => $registry) {
                foreach ($registry as $rule => $extension) {
                    $Factory->{$method}($rule, ...$this->arguments($extension));
                }
            }
        });
    }

    /** The native second argument onward: a reference string, or the map form's {extension, message}.
     *  `replacer()` takes no `$message`, so its values are always reference strings (§3.6 schema).
     *
     * @param  string|array{extension: string, message?: string}  $extension
     * @return list<string|null>
     */
    private function arguments(string|array $extension): array
    {
        return is_string($extension)
            ? [$extension]
            : [$extension[Validator::extension], $extension[Validator::message] ?? null];
    }
}
