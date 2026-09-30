<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes;

use Attribute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
class RedirectAction
{
    /** @var list<string> */
    private const array GENERATORS = ['route', 'to', 'back', 'away', 'action', 'redirect'];

    /** @param  array<array-key, mixed>  $args */
    public function apply(Redirector $redirector, array $args): RedirectResponse
    {
        $status = is_numeric($args['status'] ?? null) ? (int) $args['status'] : 302;
        $headers = is_array($args['headers'] ?? null) ? $args['headers'] : [];

        foreach (self::GENERATORS as $generator) {
            if (isset($args[$generator])) {
                return $this->{$generator}($redirector, $args[$generator], $status, $headers);
            }
        }

        return $redirector->to('/', $status, $headers);
    }

    /** @param  array<array-key, mixed>  $headers */
    protected function route(Redirector $redirector, mixed $value, int $status, array $headers): RedirectResponse
    {
        if (is_array($value)) {
            $name = $value['name'] ?? throw new InvalidArgumentException('The `route` declaration requires a `name` key.');

            return $redirector->route($this->string($name, 'route.name'), $value['parameters'] ?? [], $status, $headers);
        }

        return $redirector->route($this->string($value, 'route'), [], $status, $headers);
    }

    /** @param  array<array-key, mixed>  $headers */
    protected function to(Redirector $redirector, mixed $value, int $status, array $headers): RedirectResponse
    {
        return $redirector->to($this->string($value, 'to'), $status, $headers);
    }

    /** @param  array<array-key, mixed>  $headers */
    protected function back(Redirector $redirector, mixed $value, int $status, array $headers): RedirectResponse
    {
        $fallback = is_array($value) ? ($value['fallback'] ?? false) : false;

        return $redirector->back($status, $headers, $fallback);
    }

    /** @param  array<array-key, mixed>  $headers */
    protected function away(Redirector $redirector, mixed $value, int $status, array $headers): RedirectResponse
    {
        return $redirector->away($this->string($value, 'away'), $status, $headers);
    }

    /** @param  array<array-key, mixed>  $headers */
    protected function action(Redirector $redirector, mixed $value, int $status, array $headers): RedirectResponse
    {
        if (is_array($value)) {
            $action = $value['action'] ?? throw new InvalidArgumentException('The `action` declaration requires an `action` key.');

            if (! is_string($action) && ! is_array($action)) {
                throw new InvalidArgumentException('The `action` declaration must be a string or an array.');
            }

            return $redirector->action($action, $value['parameters'] ?? [], $status, $headers);
        }

        return $redirector->action($this->string($value, 'action'), [], $status, $headers);
    }

    /** @param  array<array-key, mixed>  $headers */
    protected function redirect(Redirector $redirector, mixed $value, int $status, array $headers): RedirectResponse
    {
        $dest = $this->string($value, 'redirect');

        return Route::has($dest)
            ? $redirector->route($dest, [], $status, $headers)
            : $redirector->to($dest, $status, $headers);
    }

    private function string(mixed $value, string $key): string
    {
        return is_string($value) ? $value : throw new InvalidArgumentException("The `{$key}` declaration must be a string.");
    }
}
