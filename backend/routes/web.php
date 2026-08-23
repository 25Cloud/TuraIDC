<?php

use App\Http\Controllers\Client\V2\TicketUpstreamUploadController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\SecureAssetController;
use App\Http\Controllers\Site\SeoController;
use App\Support\PublicUrl;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['message' => '图拉云 API'];
});

// ---- 安装向导（已安装后所有入口 404，见 InstallController） ----
Route::get('/install', [InstallController::class, 'index'])
    ->middleware('throttle:30,1');
Route::post('/install/requirements', [InstallController::class, 'requirements'])
    ->middleware('throttle:10,1');
Route::post('/install/test', [InstallController::class, 'test'])
    ->middleware('throttle:10,1');
Route::post('/install/run', [InstallController::class, 'run'])
    ->middleware('throttle:4,1');

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
