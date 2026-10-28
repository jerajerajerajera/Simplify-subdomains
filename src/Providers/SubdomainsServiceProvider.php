<?php

declare(strict_types=1);

namespace Simplify\Subdomains\Providers;

use App\Models\Server;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use Simplify\Subdomains\Addon\SubdomainsAddon;
use Simplify\Subdomains\Subdomains;
use Throwable;

final class SubdomainsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Shows the add-on inside Simplify when it is installed. Tagging stores only the class name, so without
        // Simplify the class is never loaded.
        $this->app->tag([SubdomainsAddon::class], 'simplify.addons');
    }

    public function boot(): void
    {
        Server::deleted(function (Server $server) {
            // After the response, so deleting a server never waits for Cloudflare. The sync command retries.
            $uuid = (string) $server->uuid;
            $this->app->terminating(function () use ($uuid) {
                try {
                    Subdomains::forget($uuid);
                } catch (Throwable $error) {
                    report($error);
                }
            });
        });

        $this->app->booted(function () {
            Schedule::command('simplify-subdomains:sync')->everyFiveMinutes()->withoutOverlapping(10);
        });
    }
}
