<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TuraIDC\Plugins\Addons\AiAssistant\Http\Controllers\Client\AiController;

/*
|--------------------------------------------------------------------------
| AI 助手：客户端接口
|--------------------------------------------------------------------------
|
| 路径与中间件必须与拆分前 backend/routes/v2-client.php 里的 AI 段完全一致，
| 前端调用方（用户控制台 / 官网 / 管理端浮窗）不做任何改动。
| 外层前缀 api/v2/client 与 ['api','auth:sanctum','ensure.client'] 由
| AiAssistantServiceProvider 统一挂载。
|
*/

Route::get('/ai/bootstrap', [AiController::class, 'bootstrap']);
Route::get('/ai/conversations', [AiController::class, 'conversations']);
Route::post('/ai/conversations', [AiController::class, 'storeConversation'])
    ->middleware('throttle:20,1,client-ai-conversation-store');
Route::get('/ai/conversations/{conversation}', [AiController::class, 'showConversation']);
Route::patch('/ai/conversations/{conversation}', [AiController::class, 'updateConversation']);
Route::delete('/ai/conversations/{conversation}', [AiController::class, 'destroyConversation']);
Route::post('/ai/messages', [AiController::class, 'messages'])
    ->middleware('throttle:20,1,client-ai-message');
Route::post('/ai/messages/stream', [AiController::class, 'stream'])
    ->middleware('throttle:10,1,client-ai-stream');
Route::post('/ai/actions/{action}/confirm', [AiController::class, 'confirmAction'])
    ->middleware('throttle:30,1,client-ai-confirm');
Route::post('/ai/actions/{action}/reject', [AiController::class, 'rejectAction'])
    ->middleware('throttle:30,1,client-ai-reject');
