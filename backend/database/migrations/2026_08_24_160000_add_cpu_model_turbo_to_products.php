<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'cpu_model')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->string('cpu_model', 120)->nullable()->after('pricing')->comment('CPU 型号（分组批量设置，展示优先级高于目录绑定）');
            $table->string('cpu_turbo', 40)->nullable()->after('cpu_model')->comment('CPU 睿频（如 3.8GHz）');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'cpu_model')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['cpu_model', 'cpu_turbo']);
        });
    }
};
