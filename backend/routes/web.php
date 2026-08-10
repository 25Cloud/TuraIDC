<?php

use App\Http\Controllers\SecureAssetController;
use App\Support\PublicUrl;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['message' => '二五云 API'];
});

Route::get('/api/secure-assets/view', [SecureAssetController::class, 'show'])
    ->middleware('signed:relative')
    ->name('secure-assets.show');

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
