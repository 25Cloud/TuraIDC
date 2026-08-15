<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_applications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('contact_name', 50)->default('')->comment('联系人');
            $table->string('contact_phone', 30)->default('')->comment('联系手机');
            $table->string('contact_qq', 30)->default('')->comment('QQ号');
            $table->string('company_name', 120)->default('')->comment('公司名称');
            $table->string('reason', 500)->default('')->comment('申请说明');
            $table->string('status', 20)->default('pending')->comment('状态: pending/approved/rejected');
            $table->string('admin_note', 500)->default('')->comment('管理员备注');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_applications');
    }
};
