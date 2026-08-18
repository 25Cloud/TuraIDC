<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseEngineeringCommandTest extends TestCase
{
    public function test_db_audit_core_supports_json_output(): void
    {
        Artisan::call('db:audit-core', ['--json' => true]);

        $output = Artisan::output();

        $this->assertJson($output);
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('table_count', $payload);
        $this->assertArrayHasKey('zero_reference_metrics', $payload);
        $this->assertArrayHasKey('orphan_metrics', $payload);
        $this->assertArrayHasKey('trace_id_metrics', $payload);
    }

    public function test_db_audit_foreign_keys_classifies_all_id_columns(): void
    {
        $exitCode = Artisan::call('db:audit-foreign-keys', [
            '--json' => true,
            '--strict' => true,
        ]);

        $output = Artisan::output();
        $this->assertJson($output);

        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, (int) ($payload['counts']['unclassified'] ?? -1));

        foreach ((array) ($payload['groups']['candidate_fk'] ?? []) as $candidate) {
            $this->assertSame(
                0,
                (int) ($candidate['orphan_count'] ?? -1),
                sprintf('%s.%s has orphan rows', $candidate['table_name'] ?? '', $candidate['column_name'] ?? '')
            );
        }
    }

    public function test_db_normalize_core_relations_rewrites_zero_order_id_to_null(): void
    {
        $userId = $this->ensureUserId();
        $productId = $this->ensureProductId();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            $serviceId = (int) DB::table('services')->insertGetId([
                'user_id' => $userId,
                'product_id' => $productId,
                'order_id' => 0,
                'invoice_id' => null,
                'name' => 'db-normalize-test',
                'domain' => '',
                'billing_cycle' => 'monthly',
                'amount' => '1.00',
                'status' => 0,
                'auto_renew' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        Artisan::call('db:normalize-core-relations', ['--execute' => true]);

        $this->assertDatabaseHas('services', [
            'id' => $serviceId,
            'order_id' => null,
        ]);
    }

    public function test_db_audit_core_reports_orphan_invoice_order_id(): void
    {
        $userId = $this->ensureUserId();
        $productId = $this->ensureProductId();
        $invoiceNo = 'audit-orphan-'.bin2hex(random_bytes(4));

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::table('invoices')->insert([
                'invoice_no' => $invoiceNo,
                'user_id' => $userId,
                'product_id' => $productId,
                'order_id' => 999999999,
                'type' => 'normal',
                'amount' => '1.00',
                'discount' => '0.00',
                'paid_amount' => '0.00',
                'status' => 0,
                'due_date' => now()->addDay()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        try {
            Artisan::call('db:audit-core', ['--json' => true]);

            $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertGreaterThanOrEqual(
                1,
                (int) ($payload['orphan_metrics']['invoices.order_id->orders.id'] ?? 0)
            );
        } finally {
            DB::table('invoices')->where('invoice_no', $invoiceNo)->delete();
        }
    }

    public function test_db_normalize_core_relations_clears_orphan_invoice_order_id(): void
    {
        $userId = $this->ensureUserId();
        $productId = $this->ensureProductId();
        $invoiceNo = 'norm-orphan-'.bin2hex(random_bytes(4));

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            $invoiceId = (int) DB::table('invoices')->insertGetId([
                'invoice_no' => $invoiceNo,
                'user_id' => $userId,
                'product_id' => $productId,
                'order_id' => 999999998,
                'type' => 'normal',
                'amount' => '1.00',
                'discount' => '0.00',
                'paid_amount' => '0.00',
                'status' => 0,
                'due_date' => now()->addDay()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        try {
            Artisan::call('db:normalize-core-relations', ['--execute' => true, '--json' => true]);

            $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertGreaterThanOrEqual(1, (int) ($payload['invoices_cleared_orphan_order_id'] ?? 0));
            $this->assertDatabaseHas('invoices', [
                'id' => $invoiceId,
                'order_id' => null,
            ]);
        } finally {
            DB::table('invoices')->where('id', $invoiceId)->delete();
        }
    }

    public function test_db_normalize_core_relations_reports_but_does_not_delete_orphan_invoices(): void
    {
        $invoiceNo = 'norm-report-orphan-'.bin2hex(random_bytes(4));
        $invoiceId = 0;

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            $invoiceId = (int) DB::table('invoices')->insertGetId([
                'invoice_no' => $invoiceNo,
                'user_id' => 999999997,
                'product_id' => 999999996,
                'type' => 'normal',
                'amount' => '1.00',
                'discount' => '0.00',
                'paid_amount' => '0.00',
                'status' => 0,
                'due_date' => now()->addDay()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        try {
            Artisan::call('db:normalize-core-relations', ['--execute' => true, '--json' => true]);

            $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(0, (int) ($payload['invoices_deleted_orphan_user_or_product'] ?? -1));
            $this->assertGreaterThanOrEqual(1, (int) ($payload['invoices_orphan_user_or_product_reported'] ?? 0));
            $this->assertDatabaseHas('invoices', [
                'id' => $invoiceId,
                'invoice_no' => $invoiceNo,
            ]);
        } finally {
            DB::table('invoices')->where('id', $invoiceId)->delete();
        }
    }

    public function test_db_normalize_core_relations_dry_run_does_not_alter_column_nullability(): void
    {
        $schema = (string) config('database.connections.'.config('database.default').'.database');

        $readNullable = function () use ($schema): string {
            $row = DB::selectOne(
                "SELECT IS_NULLABLE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'services' AND COLUMN_NAME = 'order_id'",
                [$schema]
            );

            return (string) ($row->IS_NULLABLE ?? 'NO');
        };

        $before = $readNullable();

        // 不带 --execute 执行，应为纯 dry-run
        Artisan::call('db:normalize-core-relations');

        $after = $readNullable();

        $this->assertSame(
            $before,
            $after,
            'dry-run 不应修改 services.order_id 的可空性（避免 MySQL DDL 隐式提交与表锁定）'
        );
    }

    private function ensureUserId(): int
    {
        $id = (int) DB::table('users')->value('id');
        if ($id > 0) {
            return $id;
        }

        return (int) DB::table('users')->insertGetId([
            'email' => 'db-engineering-test@example.com',
            'password' => bcrypt('password'),
            'nickname' => 'DB Engineering Test',
            'phone' => '13900000001',
            'company' => '',
            'qq' => '',
            'alipay_real_name' => '',
            'alipay_account' => '',
            'status' => 1,
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureProductId(): int
    {
        $id = (int) DB::table('products')->value('id');
        if ($id > 0) {
            return $id;
        }

        return (int) DB::table('products')->insertGetId([
            'product_type' => 'db_engineering',
            'custom_display_name' => 'DB Engineering Test Product',
            'pricing' => json_encode(['monthly' => '1.00'], JSON_THROW_ON_ERROR),
            'setup_fee' => '0.00',
            'stock' => -1,
            'status' => 1,
            'sort_order' => 0,
            'auto_setup' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
