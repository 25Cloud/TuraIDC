<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_key_usage_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('api_key_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->string('method', 8)->default('');
            $table->string('path', 255)->default('');
            $table->unsignedSmallInteger('status_code')->default(0);
            $table->string('ip', 45)->default('');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_key_usage_logs');
    }
};
