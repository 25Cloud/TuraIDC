<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 魔方财务作为下游把本系统配置为上游时，用普通客户账号 + api 开关进行 API 鉴权
     * （对齐 zjmf376 clients.api_open / api_username / api_password 的语义）。
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('api_open')->default(false)->after('agent_group_id');
            $table->string('api_username', 64)->nullable()->unique()->after('api_open');
            $table->string('api_password', 255)->nullable()->after('api_username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['api_open', 'api_username', 'api_password']);
        });
    }
};
