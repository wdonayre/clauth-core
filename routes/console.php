<?php

use App\Models\Application;
use App\Models\AuditEvent;
use App\Services\Manifest;
use App\Services\SigningKeys;
use Illuminate\Support\Facades\Schedule;

// Signing-key rotation steps (activate staged keys after 7 days, retire old ones after tokens expired).
Schedule::call(fn () => SigningKeys::tick())->hourly()->name('clauth:keys-tick')->withoutOverlapping();

// Nightly manifest sync for apps that publish /.well-known/clauth.json (SPEC §7).
Schedule::call(function () {
    foreach (Application::whereNotNull('manifest_url')->where('status', 'live')->get() as $app) {
        rescue(fn () => Manifest::apply($app, Manifest::fetch($app)), report: false);
    }
})->dailyAt('03:10')->name('clauth:manifest-sync')->withoutOverlapping();

// Audit retention: 1 year.
Schedule::call(fn () => AuditEvent::where('at', '<', now()->subYear())->delete())->dailyAt('03:40');
Schedule::command('passport:purge --hours=24')->dailyAt('03:50');

// Custom sign-in domains: move pending ones forward every 5 minutes; re-check live ones daily.
Schedule::command('clauth:domains check')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('clauth:domains check --all')->dailyAt('04:20')->withoutOverlapping();
Schedule::command('clauth:orgs-purge')->dailyAt('04:40')->withoutOverlapping();
