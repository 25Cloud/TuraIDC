<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\NotificationTemplate;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait InstallsNotificationTemplateDefaults
{
    protected function installNotificationTemplateDefaults(): void
    {
        // 通知模板默认数据由 SettingsSeeder 幂等种入（schema baseline 只含表结构不含模板行）。
        // 共享测试库可能由旧 baseline 初始化或被其他用例改写，这里每次清空重种，
        // 保证本类断言（如目录条数）不随运行历史漂移；SettingsSeeder 本身幂等。
        if (! Schema::hasTable('notification_templates')) {
            return;
        }

        if (! NotificationTemplate::query()->exists()) {
            SettingsSeeder::seed();

            return;
        }

        DB::table('notification_templates')->delete();
        SettingsSeeder::seed();
    }
}
