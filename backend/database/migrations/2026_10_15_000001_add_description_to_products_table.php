<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'description')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('remark')->comment('商品描述 HTML：卡片式前台展示用，留空时按规格维度自动生成');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'description')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};