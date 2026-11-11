<?php

namespace KraftInk\Plugins\Stats\Providers;

use App\Events\PruningData;
use Illuminate\Auth\Events\Registered;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use KraftInk\Plugins\Stats\Services\History;
use KraftInk\Plugins\Stats\Services\ServerTelemetry;

class PluginServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'stats');
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'stats');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../../routes/admin.php');

        Event::listen(RequestHandled::class, [History::class, 'recordVisit']);
        Event::listen(RequestHandled::class, [ServerTelemetry::class, 'recordHeartbeat']);
        Event::listen(Registered::class, [History::class, 'recordRegistration']);
        Event::listen(PruningData::class, [History::class, 'prune']);
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->call(fn () => app(History::class)->sampleServers())
                ->name('stats:sample-servers')->everyFiveMinutes()->withoutOverlapping(10);
        });
    }
}
