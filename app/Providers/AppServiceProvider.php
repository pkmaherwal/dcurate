<?php

namespace App\Providers;

use App\Jobs\FetchSourceJob;
use App\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentWorkspace::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureDevCommands();
    }

    /**
     * "composer dev": work every curation queue and run the scheduler (fetches, AI sweeps).
     */
    protected function configureDevCommands(): void
    {
        // Horizon needs Redis; without it, a plain worker covers the same queues.
        if (config('queue.default') === 'redis') {
            DevCommands::except('queue');
        } else {
            DevCommands::except('queue', 'horizon');
            DevCommands::artisan('queue:listen --queue=fetch,ai,default --tries=1 --timeout=0', 'queues');
        }

        DevCommands::artisan('schedule:work', 'scheduler');
    }

    /**
     * Be polite to each website and fair between workspaces.
     */
    protected function configureRateLimiting(): void
    {
        // Throttling runs before token authentication, so key on the token itself.
        RateLimiter::for('api', function (Request $request) {
            $token = $request->bearerToken() ?: $request->query('token');

            return Limit::perMinute(120)->by('api:'.sha1(is_string($token) && $token !== '' ? $token : (string) $request->ip()));
        });

        RateLimiter::for('source-fetch', fn (FetchSourceJob $job) => [
            Limit::perMinute((int) config('curation.fetch.per_domain_per_minute', 20))->by('domain:'.$job->source->host()),
            Limit::perMinute((int) config('curation.fetch.per_workspace_per_minute', 60))->by('workspace:'.$job->source->workspace_id),
        ]);
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
