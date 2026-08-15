<?php

declare(strict_types=1);

use TuraIDC\Plugins\Addons\ZjmfBridge\Http\Controllers\AgentController;
use Illuminate\Support\Facades\Route;

Route::post('/agent/apply', [AgentController::class, 'store']);
Route::get('/agent/info', [AgentController::class, 'info']);
