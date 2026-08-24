<?php

use App\Http\Middleware\AppendSecurityHeaders;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureAdminAuthenticated;
use App\Http\Middleware\EnsureClientAuthenticated;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\LogOperation;
use App\Http\Middleware\SetJsonEncodingOptions;
use App\Http\Middleware\TicketUpstreamUploadThrottle;
use App\Http\Middleware\VerifyAlipayCallbackSignature;
use App\Http\Middleware\VerifyCallbackSignature;
use App\Http\Middleware\VerifyPaymentCallbackSignature;
use App\Http\Middleware\VerifyTicketUpstreamCallbackSignature;
use App\Http\Middleware\VerifyTicketUpstreamUploadToken;
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

            Route::middleware('api')
                ->prefix('api/v2/open')
                ->group(base_path('routes/v2-open.php'));

        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // 反代信任：四端部署一律在反向代理之后（1Panel / 宝塔 / 宿主机 Nginx，
        // 见 docs/references/operations/）。此前未配置受信代理，Laravel 忽略
        // X-Forwarded-For，request()->ip() 取到的是反代或 Docker 网关地址
        // （容器部署下所有用户的 last_login_ip 都是 172.x.0.1）。影响不止审计：
        // ReferralService 的自荐风控以「注册 IP == 推荐人 last_login_ip」为拒绝条件，
        // 所有 IP 相同会让每一次推荐绑定都被判定为同 IP 而静默拒绝。
        // 只信任回环与 RFC1918 私有网段：文档化的部署形态里反代都与应用同机或同
        // Docker 网络，私有地址已覆盖；万一应用端口直接对外，公网来源的 XFF 也不会
        // 被采信，避免 IP 伪造。
        // 注意不要在此处调用 config()/env()：withMiddleware 闭包在配置与环境变量
        // 加载之前执行，取用会抛 “Class "config" does not exist”。
        // 不信任 X-Forwarded-Host：对外地址由 PublicUrl 依配置生成，无需依赖该头，
        // 信任它会引入 Host 头注入面。
        $middleware->trustProxies(
            at: [
                '127.0.0.1',
                '::1',
                '10.0.0.0/8',
                '172.16.0.0/12',
                '192.168.0.0/16',
                'fc00::/7',
            ],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

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
            'upload_image',
            // 安装向导运行于全新站点，session/cookie 域名与 HTTPS 可能尚未就绪，
            // 豁免 CSRF；防重放依赖安装锁（storage/app/install.lock）与限流。
            'install/*',
        ]);

        $middleware->alias([
            'verified' => EnsureEmailIsVerified::class,
            'permission' => CheckPermission::class,
            'ensure.admin' => EnsureAdminAuthenticated::class,
            'ensure.client' => EnsureClientAuthenticated::class,
            'api.key' => \App\Http\Middleware\AuthenticateApiKey::class,
            'log.operation' => LogOperation::class,
            'verify.alipay.callback' => VerifyAlipayCallbackSignature::class,
            'verify.payment.callback' => VerifyPaymentCallbackSignature::class,
            'verify.callback' => VerifyCallbackSignature::class,
            'verify.ticket.upstream.callback' => VerifyTicketUpstreamCallbackSignature::class,
            'verify.ticket.upstream.upload' => VerifyTicketUpstreamUploadToken::class,
            'ticket.upstream.upload.throttle' => TicketUpstreamUploadThrottle::class,
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
                // 全新部署的 Web 安装向导（/install）同样需要在 APP_KEY 未配置时放行，
                // 由 InstallService::ensureAppKey() 在安装流程内生成密钥。
                $requestPath = PHP_SAPI !== 'cli' ? (string) ($_SERVER['REQUEST_URI'] ?? '') : '';

                if (! str_starts_with($requestPath, '/install')) {
                    throw new RuntimeException('APP_KEY is not set. Run: php artisan key:generate');
                }
            }
        }

        // 生产环境强制 APP_DEBUG=false，防止异常堆栈泄漏数据库凭证等敏感信息。
        // 使用 config() 而非 env()，确保 config:cache 后仍能正确检测。
        if (config('app.env') === 'production' && config('app.debug') === true) {
            throw new RuntimeException('APP_DEBUG must be false in production.');
        }
    })->create();
