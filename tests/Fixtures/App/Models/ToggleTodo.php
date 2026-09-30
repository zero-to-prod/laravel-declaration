<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Models;

use Illuminate\Database\Eloquent\Model;

final class ToggleTodo extends Model
{
    protected $table = 'todos';

    protected $guarded = [];

    public function toggle(?string $column = null): self
    {
        $column ??= 'completed';
        $this->{$column} = ! $this->{$column};
        $this->save();

        return $this;
    }
}
