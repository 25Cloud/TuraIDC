<?php

declare(strict_types=1);

use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Route;
use TuraIDC\Plugins\Addons\AiAssistant\Http\Controllers\Admin\AiAdminController;

/*
|--------------------------------------------------------------------------
| AI 助手：管理端治理接口
|--------------------------------------------------------------------------
|
| 与拆分前 backend/routes/v2-admin.php 的 AI 段逐条一致：
| 设置与连通性测试需要 ai.manage，用量与会话审计需要 ai.view。
| 外层前缀 api/v2/admin 与 ['api','auth:sanctum','ensure.admin'] 由
| AiAssistantServiceProvider 统一挂载。
|
*/

Route::middleware(['permission:' . AdminPermissions::AI_VIEW])->group(function (): void {
    Route::get('/ai/usage', [AiAdminController::class, 'usage']);
    Route::get('/ai/conversations', [AiAdminController::class, 'conversations']);
    Route::get('/ai/conversations/{conversation}', [AiAdminController::class, 'showConversation']);
    Route::get('/ai/settings', [AiAdminController::class, 'settings']);
});

Route::middleware(['permission:' . AdminPermissions::AI_MANAGE])->group(function (): void {
    Route::put('/ai/settings', [AiAdminController::class, 'updateSettings']);
    Route::post('/ai/settings/test', [AiAdminController::class, 'test']);
});
