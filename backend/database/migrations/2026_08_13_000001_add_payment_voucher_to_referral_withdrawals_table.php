<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('referral_withdrawals')) {
            return;
        }

        Schema::table('referral_withdrawals', function (Blueprint $table): void {
            if (! Schema::hasColumn('referral_withdrawals', 'payment_no')) {
                $table->string('payment_no', 120)->nullable()->after('status');
            }

            if (! Schema::hasColumn('referral_withdrawals', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('operator');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('referral_withdrawals')) {
            return;
        }

        Schema::table('referral_withdrawals', function (Blueprint $table): void {
            if (Schema::hasColumn('referral_withdrawals', 'payment_no')) {
                $table->dropColumn('payment_no');
            }

            if (Schema::hasColumn('referral_withdrawals', 'paid_at')) {
                $table->dropColumn('paid_at');
            }
        });
    }
};
