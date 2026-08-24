<?php

use App\Http\Controllers\Client\V2\TicketUpstreamUploadController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\SecureAssetController;
use App\Http\Controllers\Site\SeoController;
use App\Support\PublicUrl;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', function () {
    return ['message' => '图拉云 API'];
});

// ---- 安装向导（已安装后所有入口 404，见 InstallController） ----
// 向导按设计运行在 APP_KEY 尚未生成的全新站点上，而 web 中间件组里
// EncryptCookies / StartSession / ShareErrorsFromSession / ValidateCsrfToken
// 都要用到 encrypter：没有 APP_KEY 时解析即抛 MissingAppKeyException，向导页必然 500。
// 尤其 ValidateCsrfToken——即便已在 bootstrap/app.php 按路径豁免了校验，
// 它的构造函数仍要注入 Encrypter，光是实例化就会炸。
// 向导本身不依赖 session（令牌走 X-Install-Token 头），故显式剥离这四个中间件。
Route::withoutMiddleware([
    EncryptCookies::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    ValidateCsrfToken::class,
])->group(function (): void {
    Route::get('/install', [InstallController::class, 'index'])
        ->middleware('throttle:30,1');
    Route::post('/install/requirements', [InstallController::class, 'requirements'])
        ->middleware('throttle:10,1');
    Route::post('/install/test', [InstallController::class, 'test'])
        ->middleware('throttle:10,1');
    Route::post('/install/run', [InstallController::class, 'run'])
        ->middleware('throttle:4,1');
});

// ---- 官网 SEO 动态渲染（由前端 Nginx 转发公开路径到此） ----
Route::get('/seo/www/{path?}', [SeoController::class, 'render'])
    ->where('path', '.*')
    ->name('seo.www.render');

Route::get('/robots.txt', [SeoController::class, 'robots']);
Route::get('/sitemap.xml', [SeoController::class, 'sitemap']);

Route::get('/api/secure-assets/view', [SecureAssetController::class, 'show'])
    ->middleware('signed:relative')
    ->name('secure-assets.show');

Route::post('/upload_image', [TicketUpstreamUploadController::class, 'upload'])
    ->middleware(['ticket.upstream.upload.throttle', 'verify.ticket.upstream.upload']);

Route::get('/client/register', function () {
    $frontendUrl = PublicUrl::website();
    $currentRoot = rtrim(request()->getSchemeAndHttpHost(), '/');

    if ($frontendUrl === '' || $frontendUrl === $currentRoot) {
        abort(404);
    }

    $queryString = request()->getQueryString();
    $target = PublicUrl::website('/client/register');

    if ($queryString) {
        $target .= '?'.$queryString;
    }

    return redirect()->away($target);
});
