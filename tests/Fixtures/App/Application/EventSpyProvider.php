<?php

declare(strict_types=1);

namespace ZeroToProd\LaravelDeclaration\Tests\Fixtures\App\Application;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Events\LocaleUpdated;
use Illuminate\Support\ServiceProvider;

/**
 * Registers a LocaleUpdated listener from register() — before the app: block is applied
 * in the registered() pass — so it can observe the dispatch setLocale() makes there.
 */
final class EventSpyProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->bound('events')) {
            $this->app->make(Dispatcher::class)->listen(LocaleUpdated::class, static function (LocaleUpdated $event): void {
                HookLog::record("LocaleUpdated:{$event->locale}");
            });
        }
    }
}
