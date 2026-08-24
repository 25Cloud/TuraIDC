<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('name', 64)->default('');
            $table->string('key_prefix', 32)->unique();
            $table->string('secret_hash', 64);
            $table->string('secret_last4', 4);
            $table->json('scopes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('ip_allowlist')->nullable();
            $table->string('status', 16)->default('enabled');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
