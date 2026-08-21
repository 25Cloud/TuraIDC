<?php

use App\Http\Controllers\Client\V2\TicketUpstreamUploadController;
use App\Http\Controllers\SecureAssetController;
use App\Support\PublicUrl;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['message' => '图拉云 API'];
});

Route::get('/api/secure-assets/view', [SecureAssetController::class, 'show'])
    ->middleware('signed:relative')
    ->name('secure-assets.show');

Route::post('/upload_image', [TicketUpstreamUploadController::class, 'upload'])
    ->middleware(['throttle:30,1,ticket-upstream-upload', 'verify.ticket.upstream.upload']);

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
