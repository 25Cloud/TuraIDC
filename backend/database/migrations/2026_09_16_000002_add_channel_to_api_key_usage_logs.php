<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 使用日志表增加 channel 列，承载「魔方财务对接」链路的调用审计。
 *
 * 该表此前只服务开放接口（每行绑定一个 api_keys.id）。魔方协议的凭据是
 * users.api_username/api_password，没有对应的密钥行，无法复用 api_key_id 归属；
 * 新增 channel 后两条链路共用同一张审计表、同一套保留期与清理命令，
 * 控制台按 (user_id, channel) 查询即可。
 *
 * 存量行全部属于开放接口，默认值 'open_api' 保证升级后语义不变。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('api_key_usage_logs') || Schema::hasColumn('api_key_usage_logs', 'channel')) {
            return;
        }

        Schema::table('api_key_usage_logs', function (Blueprint $table) {
            $table->string('channel', 20)->default('open_api')->after('user_id');
            $table->index(['user_id', 'channel', 'created_at'], 'api_key_usage_logs_user_channel_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('api_key_usage_logs', 'channel')) {
            return;
        }

        Schema::table('api_key_usage_logs', function (Blueprint $table) {
            $table->dropIndex('api_key_usage_logs_user_channel_idx');
            $table->dropColumn('channel');
        });
    }
};