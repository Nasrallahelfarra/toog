<?php

namespace App\Providers;

use App\Services\Ai\Ai;
use App\Services\Catalog\RecommendationProgress;
use App\Support\CurrentStore;
use App\Support\StoreAccess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentStore::class);
        $this->app->scoped(StoreAccess::class);
        $this->app->scoped(RecommendationProgress::class);
        $this->app->singleton(Ai::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerDevQueueWorkers();
    }

    /**
     * `php artisan dev` starts one queue worker, and one worker runs one job
     * at a time. Start a few more, so two people generating designs at once
     * do not wait for each other.
     */
    protected function registerDevQueueWorkers(): void
    {
        if (! $this->app->runningInConsole() || ! class_exists(DevCommands::class)) {
            return;
        }

        for ($worker = 2; $worker <= (int) config('mojo.queue.dev_workers', 1); $worker++) {
            DevCommands::artisan('queue:listen --tries=1 --timeout=0', "queue-{$worker}");
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
