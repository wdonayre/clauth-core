<?php

use App\Api\Problem;
use App\Http\Middleware\ApiClient;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\ForwardPreviousHost;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ServeCustomDomain;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        then: function () {
            Illuminate\Support\Facades\Route::middleware('api')->group(base_path('routes/standards.php'));
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->alias(['admin' => EnsureAdmin::class, 'api.client' => ApiClient::class, 'api.idempotent' => Idempotency::class, 'scim' => App\Http\Middleware\ScimRequest::class]);
        $middleware->prepend(ForwardPreviousHost::class);
        $middleware->web(prepend: [ServeCustomDomain::class], append: [SecurityHeaders::class]);
        $middleware->encryptCookies(except: []);
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Platform API errors are RFC 9457 problem+json (SPEC-platform §5).
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->attributes->get('clauth_api') && ! (($request->is('api/v1/*') || $request->is('scim/v2/*')) && ! $request->route())) {
                return null;
            }
            if ($request->is('scim/v2/*')) {
                $request->attributes->set('clauth_scim', true);
            }
            return match (true) {
                $e instanceof ValidationException => Problem::make(422, 'Invalid request', $e->getMessage(), $e->errors()),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => Problem::make(404, 'Not found', $e instanceof NotFoundHttpException && $e->getMessage() !== '' && ! str_contains($e->getMessage(), 'could not be found') ? $e->getMessage() : 'Not found.'),
                $e instanceof MethodNotAllowedHttpException => Problem::make(405, 'Method not allowed', $e->getMessage()),
                $e instanceof HttpExceptionInterface => Problem::make($e->getStatusCode(), match ($e->getStatusCode()) {
                    403 => 'Forbidden', 409 => 'Conflict', 412 => 'Changed since read', 422 => 'Unprocessable', 429 => 'Limit reached', default => 'Error',
                }, $e->getMessage() ?: null),
                default => config('app.debug') ? null : Problem::make(500, 'Server error', 'Something went wrong. Quote the request_id when asking for help.'),
            };
        });
    })->create();
