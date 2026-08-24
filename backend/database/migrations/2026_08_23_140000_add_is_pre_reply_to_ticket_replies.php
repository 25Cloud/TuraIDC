<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 工单回复增加「预回复」标记。
 *
 * 预回复是用户建单时由系统以管理员名义自动插入的员工回复，仅作本地会话
 * 自动应答，不允许推送到上游。投递链路（deliverTicket 的历史补投、
 * queueStaffReply 入口）按该标记跳过，避免「同步管理员回复」规则开启时
 * 把自动应答当作真实管理员回复转发给上游。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ticket_replies')) {
            return;
        }

        Schema::table('ticket_replies', function (Blueprint $table): void {
            $table->unsignedTinyInteger('is_pre_reply')
                ->default(0)
                ->after('sender_name');
        });

        // 存量数据全部为真实回复，回填默认值即可。
        DB::table('ticket_replies')->whereNull('is_pre_reply')->update(['is_pre_reply' => 0]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('ticket_replies')) {
            return;
        }

        Schema::table('ticket_replies', function (Blueprint $table): void {
            $table->dropColumn('is_pre_reply');
        });
    }
};
