<?php

use App\Http\Middleware\AppendSecurityHeaders;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureAdminAuthenticated;
use App\Http\Middleware\EnsureClientAuthenticated;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\LogOperation;
use App\Http\Middleware\SetJsonEncodingOptions;
use App\Http\Middleware\VerifyAlipayCallbackSignature;
use App\Http\Middleware\VerifyCallbackSignature;
use App\Http\Middleware\VerifyPaymentCallbackSignature;
use App\Support\ApiResponseBuilder;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Sentry\Laravel\Integration as SentryIntegration;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')
                ->prefix('api/v2/admin')
                ->group(base_path('routes/v2-admin.php'));

            Route::middleware('api')
                ->prefix('api/v2/client')
                ->group(base_path('routes/v2-client.php'));

        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(
            prepend: [
                SetJsonEncodingOptions::class,
                EnsureFrontendRequestsAreStateful::class,
            ],
            append: [
                AppendSecurityHeaders::class,
                LogOperation::class,
            ]
        );

        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            return '/';
        });

        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        $middleware->alias([
            'verified' => EnsureEmailIsVerified::class,
            'permission' => CheckPermission::class,
            'ensure.admin' => EnsureAdminAuthenticated::class,
            'ensure.client' => EnsureClientAuthenticated::class,
            'log.operation' => LogOperation::class,
            'verify.alipay.callback' => VerifyAlipayCallbackSignature::class,
            'verify.payment.callback' => VerifyPaymentCallbackSignature::class,
            'verify.callback' => VerifyCallbackSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        SentryIntegration::handles($exceptions);

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponseBuilder::error(40100, '未登录或登录已过期', null, 401);
            }

            return null;
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponseBuilder::error(42200, '参数验证失败', [
                    'errors' => $exception->errors(),
                ], 422);
            }

            return null;
        });

        $exceptions->render(function (ModelNotFoundException $exception, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponseBuilder::error(40400, '请求的资源不存在', null, 404);
            }

            return null;
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponseBuilder::error(40400, '请求的接口不存在', null, 404);
            }

            return null;
        });

        // 限流触发统一返回中文 429 消息，避免 Symfony 默认英文文案。
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponseBuilder::error(42900, '请求过于频繁，请稍后再试', null, 429);
            }

            return null;
        });

        // 首次部署时允许执行生成密钥和 Composer 发现命令，其余场景仍强制要求 APP_KEY 已配置。
        if (empty(config('app.key'))) {
            $currentCommand = PHP_SAPI === 'cli'
                ? trim((string) ($_SERVER['argv'][1] ?? ''))
                : '';

            $allowedCommands = [
                'key:generate',
                'package:discover',
                'config:clear',
                'cache:clear',
                'optimize:clear',
            ];

            if (! in_array($currentCommand, $allowedCommands, true)) {
                throw new RuntimeException('APP_KEY is not set. Run: php artisan key:generate');
            }
        }

        // 生产环境强制 APP_DEBUG=false，防止异常堆栈泄漏数据库凭证等敏感信息。
        // 使用 config() 而非 env()，确保 config:cache 后仍能正确检测。
        if (config('app.env') === 'production' && config('app.debug') === true) {
            throw new RuntimeException('APP_DEBUG must be false in production.');
        }
    })->create();
