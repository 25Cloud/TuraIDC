<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_applications', function (Blueprint $table): void {
            $table->string('api_key', 64)->nullable()->comment('API密钥')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('agent_applications', function (Blueprint $table): void {
            $table->dropColumn('api_key');
        });
    }
};
