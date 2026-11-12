<?php

namespace App\Providers;

use App\Http\Controllers\BarScriptController;
use App\Models\OAuthClient;
use App\OAuth\ClauthAccessToken;
use App\OAuth\IdTokenResponse;
use App\OAuth\TokenContext;
use App\Services\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Passport::ignoreRoutes();
        $this->app->scoped(TokenContext::class);
    }

    public function boot(): void
    {
        if (str_starts_with(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Passport::useClientModel(OAuthClient::class);
        Passport::useAccessTokenEntity(ClauthAccessToken::class);
        Passport::$authorizationServerResponseType = new IdTokenResponse;
        Passport::tokensExpireIn(now()->addMinutes(config('clauth.access_token_minutes')));
        Passport::refreshTokensExpireIn(now()->addDays(config('clauth.refresh_token_days')));
        Passport::tokensCan(config('clauth.scopes'));
        Passport::$revokeRefreshTokenAfterUse = true;

        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by(mb_strtolower((string) $r->input('email')).'|'.$r->ip()));
        RateLimiter::for('mfa', fn (Request $r) => Limit::perMinute(10)->by(($r->session()->get('login.user_id') ?? '').'|'.$r->ip()));
        // Token requests per client per minute: 30 by default, the publisher's limit for outside organizations (§10.1),
        // more for William's own organizations' apps.
        RateLimiter::for('token', function (Request $r) {
            $clientId = (string) ($r->input('client_id') ?: $r->getUser() ?: '');
            $per = Cache::remember('clauth.token_limit.'.$clientId, 60, function () use ($clientId) {
                $org = $clientId ? OAuthClient::with('app.publisher')->find($clientId)?->app?->publisher : null;

                return $org ? ($org->limit('token_per_minute') ?? 120) : 30;
            });

            return Limit::perMinute($per)->by($clientId.'|'.$r->ip());
        });
        RateLimiter::for('forgot', fn (Request $r) => Limit::perMinute(3)->by($r->ip()));

        // The Clauth bar script. Registered here (not in routes/web.php) so it carries no session or cookies.
        // No .js ending on purpose: the web server caches *.js forever, and bar updates must reach apps within minutes.
        Route::get('/bar/v1', BarScriptController::class)->name('bar.v1');

        config(['session.lifetime' => (int) (rescue(fn () => Settings::get('session_idle_hours'), 8, false) * 60)]);
    }
}
