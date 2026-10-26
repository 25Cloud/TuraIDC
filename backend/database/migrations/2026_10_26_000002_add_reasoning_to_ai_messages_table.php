<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI 消息增加思考过程列。
 *
 * 推理模型（DeepSeek R1/V4、Claude thinking、Responses reasoning）会把思考文本单独返回，
 * 前端要能折叠展示；只新增列，不动已发布结构。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_messages') || Schema::hasColumn('ai_messages', 'reasoning')) {
            return;
        }

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->longText('reasoning')->nullable()->after('content')->comment('模型返回的思考过程，仅展示用');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_messages') || ! Schema::hasColumn('ai_messages', 'reasoning')) {
            return;
        }

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropColumn('reasoning');
        });
    }
};
