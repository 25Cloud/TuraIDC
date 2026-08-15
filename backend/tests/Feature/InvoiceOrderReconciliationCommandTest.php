<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class InvoiceOrderReconciliationCommandTest extends BaseTestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        app('db')->setDefaultConnection('sqlite');

        $this->createSchema();
    }

    public function test_dry_run_reports_invoice_order_anomalies_without_writing(): void
    {
        $invalidInvoiceId = $this->insertInvoice([
            'invoice_no' => 'zd202606160101010001',
            'order_id' => 9999,
            'status' => InvoiceStatus::UNPAID,
        ]);
        $orderWithoutInvoiceId = $this->insertOrder([
            'order_no' => 'dd202606160101020001',
            'status' => OrderStatus::PENDING,
        ]);
        $mismatchOrderId = $this->insertOrder([
            'order_no' => 'dd202606160101030001',
            'status' => OrderStatus::PAID,
            'paid_amount' => '88.00',
            'paid_at' => now(),
        ]);
        $this->insertInvoice([
            'invoice_no' => 'zd202606160101030001',
            'order_id' => $mismatchOrderId,
            'status' => InvoiceStatus::UNPAID,
        ]);

        Artisan::call('trade:reconcile-invoice-order', ['--json' => true]);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue((bool) $payload['dry_run']);
        $this->assertSame(1, (int) $payload['summary']['invoices_invalid_order']);
        $this->assertSame(1, (int) $payload['summary']['orders_without_invoice']);
        $this->assertSame(1, (int) $payload['summary']['paid_order_invoice_status_mismatch']);
        $this->assertDatabaseHas('invoices', ['id' => $invalidInvoiceId, 'order_id' => 9999]);
        $this->assertDatabaseMissing('invoices', ['order_id' => $orderWithoutInvoiceId]);
    }

    public function test_dry_run_reports_amount_mismatch_and_completed_cancelled_without_writing(): void
    {
        $amountOrderId = $this->insertOrder([
            'order_no' => 'dd202606160109020001',
            'status' => OrderStatus::COMPLETED,
            'amount' => '10.00',
            'paid_amount' => '10.00',
            'paid_at' => now(),
        ]);
        $this->insertInvoice([
            'invoice_no' => 'zd202606160109020001',
            'order_id' => $amountOrderId,
            'status' => InvoiceStatus::PAID,
            'amount' => '99.00',
            'paid_amount' => '99.00',
            'paid_at' => now(),
        ]);

        $cancelledOrderId = $this->insertOrder([
            'order_no' => 'dd202606160109030001',
            'status' => OrderStatus::COMPLETED,
            'paid_at' => now(),
        ]);
        $this->insertInvoice([
            'invoice_no' => 'zd202606160109030001',
            'order_id' => $cancelledOrderId,
            'status' => InvoiceStatus::CANCELLED,
        ]);

        Artisan::call('trade:reconcile-invoice-order', ['--json' => true]);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        // 金额口径漂移只报告、不自动修复；已完成+已取消状态矛盾同样只报告。
        $this->assertSame(1, (int) $payload['summary']['amount_mismatch']);
        $this->assertSame(1, (int) $payload['summary']['completed_invoice_cancelled']);
        $this->assertDatabaseHas('invoices', ['id' => $cancelledOrderId, 'status' => InvoiceStatus::CANCELLED]);

        $samples = $payload['samples']['amount_mismatch'];
        $this->assertSame('manual_review', $samples[0]['suggested_action']);
    }

    public function test_execute_snapshots_and_repairs_invoice_order_anomalies(): void
    {
        $snapshotDir = storage_path('framework/testing/invoice-order-reconciliation-'.uniqid());
        $backupPath = null;

        try {
            $invalidInvoiceId = $this->insertInvoice([
                'invoice_no' => 'zd202606160102010001',
                'order_id' => 9999,
                'status' => InvoiceStatus::UNPAID,
            ]);
            $orderWithoutInvoiceId = $this->insertOrder([
                'order_no' => 'dd202606160102020001',
                'status' => OrderStatus::PENDING,
            ]);
            $paidOrderId = $this->insertOrder([
                'order_no' => 'dd202606160102030001',
                'status' => OrderStatus::PAID,
                'paid_amount' => '88.00',
                'paid_at' => now(),
            ]);
            $unpaidInvoiceId = $this->insertInvoice([
                'invoice_no' => 'zd202606160102030001',
                'order_id' => $paidOrderId,
                'status' => InvoiceStatus::UNPAID,
            ]);
            $pendingOrderId = $this->insertOrder([
                'order_no' => 'dd202606160102040001',
                'status' => OrderStatus::PENDING,
            ]);
            $this->insertInvoice([
                'invoice_no' => 'zd202606160102040001',
                'order_id' => $pendingOrderId,
                'status' => InvoiceStatus::PAID,
                'paid_amount' => '99.00',
                'paid_at' => now(),
            ]);
            $refundedOrderId = $this->insertOrder([
                'order_no' => 'dd202606160102050001',
                'status' => OrderStatus::REFUNDED,
                'paid_amount' => '66.00',
                'paid_at' => now(),
            ]);
            $refundedInvoiceId = $this->insertInvoice([
                'invoice_no' => 'zd202606160102050001',
                'order_id' => $refundedOrderId,
                'status' => InvoiceStatus::PAID,
                'paid_amount' => '66.00',
                'paid_at' => now(),
            ]);

            Artisan::call('trade:reconcile-invoice-order', [
                '--execute' => true,
                '--json' => true,
                '--snapshot-dir' => $snapshotDir,
            ]);

            $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            $backupPath = $payload['snapshot_path'] ?? null;

            $this->assertFalse((bool) $payload['dry_run']);
            $this->assertSame(0, (int) $payload['after']['invoices_invalid_order']);
            $this->assertSame(0, (int) $payload['after']['orders_without_invoice']);
            $this->assertSame(0, (int) $payload['after']['paid_order_invoice_status_mismatch']);
            $this->assertIsString($backupPath);
            $this->assertFileExists($backupPath);
            $this->assertDatabaseHas('invoices', ['id' => $invalidInvoiceId, 'order_id' => null]);
            $this->assertDatabaseHas('invoices', ['order_id' => $orderWithoutInvoiceId]);
            $this->assertDatabaseHas('invoices', ['id' => $unpaidInvoiceId, 'status' => InvoiceStatus::PAID]);
            $this->assertDatabaseHas('orders', ['id' => $pendingOrderId, 'status' => OrderStatus::PAID]);
            $this->assertDatabaseHas('orders', ['id' => $refundedOrderId, 'status' => OrderStatus::REFUNDED]);
            $this->assertDatabaseHas('invoices', ['id' => $refundedInvoiceId, 'status' => InvoiceStatus::REFUNDED]);
        } finally {
            if (is_string($backupPath) && File::exists($backupPath)) {
                File::delete($backupPath);
            }
            if (File::exists($snapshotDir)) {
                File::deleteDirectory($snapshotDir);
            }
        }
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('orders');

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_no')->unique();
            $table->unsignedBigInteger('user_id')->default(1);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->unsignedBigInteger('user_coupon_id')->nullable();
            $table->string('coupon_code')->nullable();
            $table->string('type')->default('new');
            $table->decimal('amount', 12, 2)->default(100);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('billing_cycle')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->text('product_spec_snapshot')->nullable();
            $table->string('product_type_snapshot')->nullable();
            $table->json('product_snapshot_json')->nullable();
            $table->json('config_snapshot')->nullable();
            $table->json('config_pricing_snapshot')->nullable();
            $table->json('coupon_snapshot')->nullable();
            $table->tinyInteger('status')->default(OrderStatus::PENDING);
            $table->dateTime('paid_at')->nullable();
            $table->string('trace_id')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_no')->unique();
            $table->unsignedBigInteger('user_id')->default(1);
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->unsignedBigInteger('user_coupon_id')->nullable();
            $table->string('coupon_code')->nullable();
            $table->string('type')->default('normal');
            $table->decimal('amount', 12, 2)->default(100);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('billing_cycle')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->text('product_spec_snapshot')->nullable();
            $table->string('product_type_snapshot')->nullable();
            $table->json('product_snapshot_json')->nullable();
            $table->json('config_snapshot')->nullable();
            $table->json('config_pricing_snapshot')->nullable();
            $table->json('coupon_snapshot')->nullable();
            $table->tinyInteger('status')->default(InvoiceStatus::UNPAID);
            $table->dateTime('due_date')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('refunded_at')->nullable();
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->string('trace_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function insertOrder(array $overrides = []): int
    {
        return (int) DB::table('orders')->insertGetId(array_merge([
            'order_no' => 'dd'.now()->format('YmdHis').random_int(1000, 9999),
            'user_id' => 1,
            'type' => 'new',
            'amount' => '100.00',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'status' => OrderStatus::PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function insertInvoice(array $overrides = []): int
    {
        return (int) DB::table('invoices')->insertGetId(array_merge([
            'invoice_no' => 'zd'.now()->format('YmdHis').random_int(1000, 9999),
            'user_id' => 1,
            'order_id' => null,
            'type' => 'normal',
            'amount' => '100.00',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
            'due_date' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
