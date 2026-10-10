<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * user_coupons.uid 改名为 coupon_code。
 *
 * 该列实际存储的是券码串（uc_xxx），与生态内 uid = 用户 ID 的既有语义
 * （users.id、JWT claims uid、上游 API uid）直接冲突，易引起误用。
 * 统一改为 coupon_code，同步更名唯一索引 user_coupons_uid_unique
 * → user_coupons_coupon_code_unique。
 *
 * 幂等：列或索引已是目标状态时无操作；重复执行无副作用。
 * 回滚：down() 逆向改名，仅当没有依赖新列名的数据写入时才安全。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_coupons')) {
            return;
        }

        if (Schema::hasColumn('user_coupons', 'uid')
            && ! Schema::hasColumn('user_coupons', 'coupon_code')) {
            Schema::table('user_coupons', function (Blueprint $table): void {
                $table->renameColumn('uid', 'coupon_code');
            });
        }

        if (Schema::hasIndex('user_coupons', 'user_coupons_uid_unique')
            && ! Schema::hasIndex('user_coupons', 'user_coupons_coupon_code_unique')) {
            Schema::table('user_coupons', function (Blueprint $table): void {
                $table->renameIndex('user_coupons_uid_unique', 'user_coupons_coupon_code_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_coupons')) {
            return;
        }

        if (Schema::hasIndex('user_coupons', 'user_coupons_coupon_code_unique')
            && ! Schema::hasIndex('user_coupons', 'user_coupons_uid_unique')) {
            Schema::table('user_coupons', function (Blueprint $table): void {
                $table->renameIndex('user_coupons_coupon_code_unique', 'user_coupons_uid_unique');
            });
        }

        if (Schema::hasColumn('user_coupons', 'coupon_code')
            && ! Schema::hasColumn('user_coupons', 'uid')) {
            Schema::table('user_coupons', function (Blueprint $table): void {
                $table->renameColumn('coupon_code', 'uid');
            });
        }
    }
};
