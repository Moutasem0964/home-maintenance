<?php

namespace App\Providers;

<<<<<<< HEAD
=======
use App\Models\User;
>>>>>>> origin/main
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

<<<<<<< HEAD
        Telescope::filter(function (IncomingEntry $entry) {
            return $entry->type === 'request'
                || $entry->isReportableException()
                || $entry->isFailedRequest()
                || $entry->isFailedJob()
                || $entry->isScheduledTask()
                || $entry->hasMonitoredTag();
=======
        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            return $isLocal ||
                $entry->isReportableException() ||
                $entry->isFailedRequest() ||
                $entry->isFailedJob() ||
                $entry->isScheduledTask() ||
                $entry->hasMonitoredTag();
>>>>>>> origin/main
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

<<<<<<< HEAD
        Telescope::hideRequestParameters([
            '_token',
            'password',
            'password_confirmation',
            'otp',
            'code',
            'token',
            'access_token',
            'refresh_token',
        ]);
=======
        Telescope::hideRequestParameters(['_token']);
>>>>>>> origin/main

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
<<<<<<< HEAD
            'authorization',
=======
>>>>>>> origin/main
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    protected function gate(): void
    {
<<<<<<< HEAD
        Gate::define('viewTelescope', function () {
            return true;
=======
        Gate::define('viewTelescope', function (User $user) {
            return $user->phone === '0900000000';
>>>>>>> origin/main
        });
    }
}
