<?php

use App\Http\Middleware\CustomerIdleTimeout;
use App\Http\Middleware\GoogleCallbackPrivacy;
use App\Http\Middleware\RecordActivity;
use App\Http\Middleware\RequireVerifiedEmail;
use App\Http\Middleware\StaffAccess;
use App\Services\AccountNavigation;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\AuthenticateSession;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request) => route($request->is('admin', 'admin/*') ? 'admin.login' : 'login'));
        $middleware->redirectUsersTo(fn (Request $request) => route(AccountNavigation::homeRoute($request->user())));
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
        $middleware->web(append: [AuthenticateSession::class, RecordActivity::class, CustomerIdleTimeout::class, RequireVerifiedEmail::class]);
        $middleware->alias(['staff' => StaffAccess::class]);
        $middleware->prependToPriorityList(ThrottleRequests::class, GoogleCallbackPrivacy::class);
        $middleware->preventRequestForgery(except: ['webhooks/paymongo']);
    })->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['code', 'token']);
        $exceptions->render(function (LockTimeoutException $exception, Request $request) {
            $message = 'Your previous request is still finishing. Wait a moment and try again.';

            return ($request->expectsJson()
                ? response()->json(['message' => $message], 503)
                : response($message, 503))->header('Cache-Control', 'no-store, private');
        });
        $exceptions->respond(function (Response $response) {
            if (request()->routeIs('password.*')) {
                $response->headers->set('Cache-Control', 'no-store, private');
                $response->headers->set('Referrer-Policy', 'no-referrer');
            }

            return $response;
        });
    })->create();
