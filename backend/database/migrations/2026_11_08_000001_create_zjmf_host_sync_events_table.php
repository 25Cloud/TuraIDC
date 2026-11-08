<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('zjmf_host_sync_events')) {
            return;
        }

        Schema::create('zjmf_host_sync_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('service_id')->index();
            $table->string('event_key', 64)->unique('zjmf_host_sync_events_event_key_unique');
            $table->string('signature_version', 16)->default('legacy');
            $table->string('nonce_hash', 64)->nullable();
            $table->string('payload_hash', 64);
            $table->string('status', 24)->default('processed');
            $table->text('response_json')->nullable();
            $table->timestamp('received_at')->nullable()->default(null);
            $table->timestamp('processed_at')->nullable()->default(null);
            $table->timestamps();
            $table->unique(
                ['service_id', 'nonce_hash'],
                'zjmf_host_sync_events_service_nonce_unique'
            );
            $table->index(
                ['service_id', 'signature_version', 'created_at'],
                'zjmf_host_sync_events_service_version_created_index'
            );
        });
    }

    public function down(): void
    {
        // 事件审计属于新增持久数据，升级后不因回滚迁移而物理删除。
    }
};
