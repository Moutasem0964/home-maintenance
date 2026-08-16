<?php

namespace App\Providers;

use App\Contracts\CustomTokenMinter;
use App\Contracts\LocationTracker;
use App\Contracts\PushSender;
use App\Contracts\SmsSender;
use App\Models\User;
use App\Services\Push\FcmPushSender;
use App\Services\Push\LogPushSender;
use App\Services\Realtime\FirebaseCustomTokenMinter;
use App\Services\Realtime\FirebaseLocationTracker;
use App\Services\Realtime\LocalCustomTokenMinter;
use App\Services\Realtime\LogLocationTracker;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsChefSmsSender;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Contract\Database;
use Kreait\Firebase\Contract\Messaging;

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

        // 'log' (default, local/tests) just logs; 'fcm' delivers through Firebase Cloud
        // Messaging. Resolving the FCM sender lazily means tests never need Firebase.
        $this->app->bind(PushSender::class, function ($app) {
            return match (config('push.driver')) {
                'fcm' => new FcmPushSender($app->make(Messaging::class)),
                default => new LogPushSender,
            };
        });

        // Live-location realtime: 'log' (default, local/tests) keeps Firebase untouched;
        // 'firebase' resolves the kreait Auth/Database lazily so tests never need credentials.
        $this->app->bind(CustomTokenMinter::class, function ($app) {
            return match (config('realtime.driver')) {
                'firebase' => new FirebaseCustomTokenMinter($app->make(Auth::class)),
                default => new LocalCustomTokenMinter,
            };
        });

        $this->app->bind(LocationTracker::class, function ($app) {
            return match (config('realtime.driver')) {
                'firebase' => new FirebaseLocationTracker($app->make(Database::class)),
                default => new LogLocationTracker,
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
