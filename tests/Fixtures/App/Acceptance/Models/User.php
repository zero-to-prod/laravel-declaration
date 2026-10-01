<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Acceptance\Models;

use Illuminate\Database\Eloquent\Model;

/** The plan's persisted model for AT-15's `{user}` implicit binding observable.
 *
 * @property string $email
 */
final class User extends Model
{
    protected $guarded = [];
}
