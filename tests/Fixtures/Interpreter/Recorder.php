<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\Interpreter;

use Closure;

/** Records every call as [method, arguments]; the methods cover each form the interpreter reads. */
final class Recorder
{
    /** @var list<array{0: string, 1: array<array-key, mixed>}> */
    public array $calls = [];

    public function none(): void
    {
        $this->calls[] = ['none', []];
    }

    public function zero(): void
    {
        $this->calls[] = ['zero', []];
    }

    public function one(mixed $x): void
    {
        $this->calls[] = ['one', [$x]];
    }

    /** @param  list<mixed>  $x */
    public function typed(array $x): void
    {
        $this->calls[] = ['typed', [$x]];
    }

    public function spread(mixed ...$x): void
    {
        $this->calls[] = ['spread', $x];
    }

    public function pair(mixed $k, mixed $v): void
    {
        $this->calls[] = ['pair', [$k, $v]];
    }

    /** @param  list<mixed>  $v */
    public function pairArray(mixed $k, array $v): void
    {
        $this->calls[] = ['pairArray', [$k, $v]];
    }

    public function hook(mixed $k, Closure $callback): void
    {
        $this->calls[] = ['hook', [$k, $callback]];
    }

    public function fluent(mixed $a, mixed $b = null): self
    {
        $this->calls[] = ['fluent', ['a' => $a, 'b' => $b]];

        return $this;
    }

    public function other(mixed $a): Recorder
    {
        $this->calls[] = ['other', [$a]];

        return new self;
    }

    public function void(mixed $a): void
    {
        $this->calls[] = ['void', [$a]];
    }
}
