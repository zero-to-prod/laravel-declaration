<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Attributes;

use Attribute;
use Illuminate\Http\RedirectResponse;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
class FlashAction
{
    /** @var list<string> */
    private const array MODIFIERS = ['with', 'withInput', 'withErrors', 'headers', 'withFragment'];

    /** @param  array<array-key, mixed>  $args */
    public function apply(RedirectResponse $response, array $args): RedirectResponse
    {
        foreach (self::MODIFIERS as $modifier) {
            if (isset($args[$modifier])) {
                $this->{$modifier}($response, $args[$modifier]);
            }
        }

        return $response;
    }

    public function with(RedirectResponse $response, mixed $value): void
    {
        if (is_array($value)) {
            $response->with($value);
        }
    }

    public function withInput(RedirectResponse $response, mixed $value): void
    {
        is_array($value) ? $response->onlyInput(...$value) : $response->withInput();
    }

    public function withErrors(RedirectResponse $response, mixed $value): void
    {
        if (is_array($value) || is_string($value)) {
            $response->withErrors($value);
        }
    }

    public function headers(RedirectResponse $response, mixed $value): void
    {
        if (is_array($value)) {
            $response->withHeaders($value);
        }
    }

    public function withFragment(RedirectResponse $response, mixed $value): void
    {
        if (is_string($value)) {
            $response->withFragment($value);
        }
    }
}
