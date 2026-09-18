<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 魔方财务对接凭据补齐安全能力：IP 白名单、有效期、最近调用时间。
 *
 * 背景：魔方协议（/api/v2/zjmf）的凭据是 users.api_username/api_password，
 * 拿不到 JWT 之前的登录端点又是明文 username+password，因此无法像开放接口
 * 那样用 Bearer 密钥；但「凭据治理」不该因此降级——原先 api_open=1 即全权、
 * 无白名单、无有效期、无审计。这里把缺失的三项补上，与 api_keys 对齐。
 *
 * 三列均按「默认不限制」初始化：存量已开启对接的账号升级后行为不变。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // JSON 数组，条目为精确 IP 或 CIDR；null/空表示不限制
            $table->json('api_ip_allowlist')->nullable()->after('api_password');
            // 到期即拒登（与 api_keys.expires_at 同语义）；null 表示永不过期
            $table->timestamp('api_expires_at')->nullable()->after('api_ip_allowlist');
            // 最近一次成功换取 JWT 的时间；用于控制台展示与长期未用凭据排查
            $table->timestamp('api_last_used_at')->nullable()->after('api_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['api_ip_allowlist', 'api_expires_at', 'api_last_used_at']);
        });
    }
};