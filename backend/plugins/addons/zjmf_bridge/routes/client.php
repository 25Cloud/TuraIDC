<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TuraIDC\Plugins\Addons\ZjmfBridge\Http\Controllers\AgentController;

Route::post('/agent/apply', [AgentController::class, 'store']);
Route::get('/agent/info', [AgentController::class, 'info']);
