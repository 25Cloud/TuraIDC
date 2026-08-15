<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('services') && Schema::hasColumn('services', 'auto_renew')) {
            Schema::table('services', function (Blueprint $table): void {
                $table->tinyInteger('auto_renew')
                    ->default(0)
                    ->comment('是否自动续费：0关闭 1开启')
                    ->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('services') && Schema::hasColumn('services', 'auto_renew')) {
            Schema::table('services', function (Blueprint $table): void {
                $table->tinyInteger('auto_renew')
                    ->default(1)
                    ->comment('是否自动续费：0关闭 1开启')
                    ->change();
            });
        }
    }
};
