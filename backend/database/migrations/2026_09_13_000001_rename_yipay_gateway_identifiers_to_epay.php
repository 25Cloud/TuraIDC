<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 易支付网关标识统一：yi_pay/yipay → epay。
 *
 * 易支付插件此前存在三套标识并存：插件目录与清单 slug 为 yi_pay、清单 key 与
 * 业务网关编码为 yipay，与其他网关「目录名 = slug = key」的单标识约定不一致。
 * 本次统一为 epay（目录 plugins/gateways/epay、命名空间 TuraIDC\Plugins\Gateways\Epay），
 * 运行时对已下发给网关的历史回调 URL（/payment/notify/yipay）与请求参数保持
 * normalize() 别名兼容，无需网关侧配合改地址。
 *
 * 本迁移处理存量数据，全部按主键定位、只改标识列，不动任何金额/状态：
 * 1. integration_plugins 注册行：slug/plugin_key 改为 epay（保 id，插件配置、
 *    绑定、运行日志通过 plugin_id 外键继续关联）；entry_class 同步为新命名空间，
 *    避免历史行与新清单哈希比对或反射时指向不存在的类。
 * 2. 历史业务表的网关编码改写为 epay：payments.gateway_key、
 *    payment_callbacks.gateway_key、gateway_logs.gateway_key。这些表被
 *    scopeWhereGatewayKey / whereGatewayKeyIn / PaymentBoundaryAuditService /
 *    CompensateRechargeInvoicesCommand 以「归一化值 = 列值」等值查询，若保留
 *    yipay 历史值，改名后所有财务筛选与第三方边界审计都会漏掉历史记录。
 *    payments.plugin_id 按 epay 注册行的新 slug 重新回填，保持插件归属正确。
 * 3. gateway_logs.gateway（原始网关串，仅展示与关键词检索）保留历史值不改写，
 *    管理端筛选走的是归一化的 gateway_key 列。
 *
 * 幂等：全部按目标值或不存在旧值时无操作；重复执行无副作用。
 * 回滚：down() 按旧值逆向改回，仅当没有新数据写入 epay 时才安全。
 */
return new class extends Migration
{
    private const OLD_SLUG = 'yi_pay';

    private const OLD_KEY = 'yipay';

    private const NEW_KEY = 'epay';

    public function up(): void
    {
        if (! Schema::hasTable('integration_plugins')) {
            return;
        }

        $plugin = DB::table('integration_plugins')
            ->where('domain', 'payment')
            ->where(static function ($query): void {
                $query->where('slug', self::OLD_SLUG)
                    ->orWhere('slug', self::NEW_KEY)
                    ->orWhere('plugin_key', self::OLD_KEY)
                    ->orWhere('plugin_key', self::NEW_KEY);
            })
            ->orderBy('id')
            ->first(['id', 'slug', 'plugin_key']);

        $pluginId = $plugin === null ? null : (int) $plugin->id;

        if ($plugin !== null
            && ((string) $plugin->slug !== self::NEW_KEY || (string) $plugin->plugin_key !== self::NEW_KEY)) {
            DB::table('integration_plugins')->where('id', (int) $plugin->id)->update([
                'slug' => self::NEW_KEY,
                'plugin_key' => self::NEW_KEY,
                'entry_class' => 'TuraIDC\Plugins\Gateways\Epay\EpayPlugin',
                'updated_at' => now(),
            ]);
        }

        // 网关编码改写：旧值 yipay / yi_pay 都归到 epay。
        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'gateway_key')) {
            DB::table('payments')
                ->whereIn('gateway_key', [self::OLD_KEY, self::OLD_SLUG])
                ->update(['gateway_key' => self::NEW_KEY]);

            if ($pluginId !== null && Schema::hasColumn('payments', 'plugin_id')) {
                DB::table('payments')
                    ->where('gateway_key', self::NEW_KEY)
                    ->where(static function ($query): void {
                        $query->whereNull('plugin_id')->orWhere('plugin_id', 0);
                    })
                    ->update(['plugin_id' => $pluginId]);
            }
        }

        if (Schema::hasTable('payment_callbacks') && Schema::hasColumn('payment_callbacks', 'gateway_key')) {
            DB::table('payment_callbacks')
                ->whereIn('gateway_key', [self::OLD_KEY, self::OLD_SLUG])
                ->update(['gateway_key' => self::NEW_KEY]);
        }

        if (Schema::hasTable('gateway_logs') && Schema::hasColumn('gateway_logs', 'gateway_key')) {
            DB::table('gateway_logs')
                ->whereIn('gateway_key', [self::OLD_KEY, self::OLD_SLUG])
                ->update(['gateway_key' => self::NEW_KEY]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('integration_plugins')) {
            return;
        }

        $plugin = DB::table('integration_plugins')
            ->where('domain', 'payment')
            ->where('slug', self::NEW_KEY)
            ->where('plugin_key', self::NEW_KEY)
            ->orderBy('id')
            ->first(['id']);

        if ($plugin !== null) {
            DB::table('integration_plugins')->where('id', (int) $plugin->id)->update([
                'slug' => self::OLD_SLUG,
                'plugin_key' => self::OLD_KEY,
                'entry_class' => 'TuraIDC\Plugins\Gateways\YiPay\YiPayPlugin',
                'updated_at' => now(),
            ]);
        }

        foreach (['payments', 'payment_callbacks', 'gateway_logs'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'gateway_key')) {
                DB::table($table)->where('gateway_key', self::NEW_KEY)->update(['gateway_key' => self::OLD_KEY]);
            }
        }
    }
};
