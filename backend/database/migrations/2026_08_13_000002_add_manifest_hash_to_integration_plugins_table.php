<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('integration_plugins')) {
            return;
        }

        Schema::table('integration_plugins', function (Blueprint $table): void {
            if (! Schema::hasColumn('integration_plugins', 'manifest_hash')) {
                $table->string('manifest_hash', 64)->nullable()->after('version');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('integration_plugins')) {
            return;
        }

        Schema::table('integration_plugins', function (Blueprint $table): void {
            if (Schema::hasColumn('integration_plugins', 'manifest_hash')) {
                $table->dropColumn('manifest_hash');
            }
        });
    }
};
