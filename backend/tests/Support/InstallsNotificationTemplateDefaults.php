<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Schema;

trait InstallsNotificationTemplateDefaults
{
    protected function installNotificationTemplateDefaults(): void
    {
        // 通知模板默认数据已包含在 schema baseline 中
        // 测试环境如需重置，直接 truncate 表后重新 seeder
    }
}
