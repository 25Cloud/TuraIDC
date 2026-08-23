<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_discount_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 50);
            $table->string('code', 30)->unique();
            $table->decimal('min_discount_rate', 5, 2)->default(100);
            $table->decimal('cost_rate', 5, 2)->default(0);
            $table->tinyInteger('status')->default(1);
            $table->integer('sort_order')->default(0);
            $table->string('remark')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 50);
            $table->string('code', 30)->unique();
            $table->tinyInteger('status')->default(1);
            $table->integer('sort_order')->default(0);
            $table->string('remark')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_group_discounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_group_id')->constrained('agent_groups')->cascadeOnDelete();
            $table->foreignId('product_discount_group_id')->constrained('product_discount_groups')->cascadeOnDelete();
            $table->decimal('discount_rate', 5, 2);
            $table->timestamps();
            $table->unique(['agent_group_id', 'product_discount_group_id'], 'agent_group_discount_unique');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('product_discount_group_id')->nullable()->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('agent_group_id')->nullable()->nullOnDelete();
        });

        Schema::table('coupons', function (Blueprint $table): void {
            $table->boolean('allow_agent')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table): void {
            $table->dropColumn('allow_agent');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['agent_group_id']);
            $table->dropColumn('agent_group_id');
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['product_discount_group_id']);
            $table->dropColumn('product_discount_group_id');
        });
        Schema::dropIfExists('agent_group_discounts');
        Schema::dropIfExists('agent_groups');
        Schema::dropIfExists('product_discount_groups');
    }
};
