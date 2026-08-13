<?php

namespace App\Providers;

use App\Contracts\SmsSender;
use App\Models\User;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsChefSmsSender;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SmsSender::class, function () {
            return match (config('sms.driver')) {
                'smschef' => new SmsChefSmsSender(
                    config('sms.smschef.endpoint'),
                    (string) config('sms.smschef.secret'),
                    (string) config('sms.smschef.device'),
                ),
                default => new LogSmsSender,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Scramble API docs (/docs/api) are reachable only over the operator's SSH tunnel,
        // like Telescope, so the gate stays open. ?User is required because the docs are
        // viewed unauthenticated (a guest gate must have a nullable first parameter).
        Gate::define('viewApiDocs', function (?User $user) {
            return true;
        });
    }
}
