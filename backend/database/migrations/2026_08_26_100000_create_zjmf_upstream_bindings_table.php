<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 下游（魔方财务）绑定：TuraIDC 作为上游时，魔方财务在 settle 里携带
     * downstream_url/downstream_token/downstream_id，TuraIDC 开通后据此把
     * host 状态推送回魔方财务（对应魔方财务 pushHostInfo 的 /api/host/sync）。
     */
    public function up(): void
    {
        Schema::create('zjmf_upstream_bindings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->unsignedBigInteger('service_id')->nullable()->index();
            $table->string('downstream_url', 255)->default('');
            $table->string('downstream_token', 64)->default('');
            $table->unsignedBigInteger('downstream_id')->default(0);
            $table->string('domain', 255)->default('');
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zjmf_upstream_bindings');
    }
};
