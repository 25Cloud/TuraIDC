<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionClass;

class InvoicePaymentEntrySwitchTest extends BaseTestCase
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

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_invoice_manual_paid_entry_creates_manual_payment_audit_record(): void
    {
        $user = $this->createUser();
        $order = Order::query()->create([
            'order_no' => 'ORDMANUAL01',
            'user_id' => (int) $user->id,
            'type' => 'new',
            'amount' => '88.00',
            'discount' => '8.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'status' => OrderStatus::PENDING,
        ]);
        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVMANUAL01',
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'type' => 'normal',
            'amount' => '80.00',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'due_date' => now()->addDay(),
        ]);
        Payment::query()->create([
            'payment_no' => 'PAYMANUALPENDING',
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => PaymentGatewayCode::ALIPAY,
            'amount' => '80.00',
            'status' => PaymentStatus::PENDING,
            'callback_raw' => ['source' => 'precreate'],
        ]);

        $paymentService = Mockery::mock(PaymentService::class);
        $paymentService->shouldReceive('syncProjection')->once()->andReturnUsing(
            static fn (Payment $payment): Payment => $payment->fresh() ?? $payment
        );
        $paymentService->shouldReceive('handlePaidInvoice')->never();
        $this->app->instance(PaymentService::class, $paymentService);

        $updated = (new InvoiceService)->markPaidManually($invoice, [
            'amount' => '80.00',
            'payment_gateway' => 'manual',
            'trade_no' => 'ADMIN-MANUAL',
            'sync_business_flow' => false,
            'remark' => '后台确认到账',
        ], [
            'operator_id' => 1001,
            'operator_name' => 'Finance Admin',
            'trace_id' => 'manual-invoice-switch-test',
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertSame(InvoiceStatus::PAID, (int) $updated->status);
        $this->assertDatabaseHas('orders', [
            'id' => (int) $order->id,
            'status' => OrderStatus::PAID,
            'paid_amount' => '80.00',
        ]);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => (int) $invoice->id,
            'gateway_key' => PaymentGatewayCode::ALIPAY,
            'status' => PaymentStatus::FAILED,
        ]);
        $this->assertDatabaseMissing('payments', [
            'invoice_id' => (int) $invoice->id,
            'gateway_key' => PaymentGatewayCode::ALIPAY,
            'status' => PaymentStatus::SUCCESS,
        ]);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => (int) $invoice->id,
            'gateway_key' => 'manual',
            'status' => PaymentStatus::SUCCESS,
            'trade_no' => 'ADMIN-MANUAL',
            'amount' => '80.00',
        ]);
        $this->assertDatabaseHas('operation_logs', [
            'module' => 'invoice',
            'action' => 'invoice.payment.mark_paid',
            'subject_id' => (int) $invoice->id,
        ]);
    }

    public function test_payment_callback_projection_upserts_without_deleting_existing_callback_rows(): void
    {
        $user = $this->createUser();
        $payment = Payment::query()->create([
            'payment_no' => 'PAYCALLBACKUPSERT',
            'user_id' => (int) $user->id,
            'gateway' => PaymentGatewayCode::ALIPAY,
            'trade_no' => 'TRADE-UPSERT',
            'amount' => '19.90',
            'status' => PaymentStatus::SUCCESS,
            'callback_raw' => [
                'trade_no' => 'TRADE-UPSERT',
                'trade_status' => 'TRADE_SUCCESS',
                'trace_id' => 'callback-upsert-test',
                'refund' => [
                    'trade_no' => 'REFUND-UPSERT',
                    'refund_amount' => '19.90',
                    'refunded_at' => now()->format('Y-m-d H:i:s'),
                ],
            ],
            'paid_at' => now(),
            'trace_id' => 'callback-upsert-test',
        ]);
        $refundCallbackId = DB::table('payment_callbacks')->insertGetId([
            'payment_id' => (int) $payment->id,
            'callback_type' => 'refund',
            'gateway_trade_no' => 'OLD-REFUND',
            'payload_json' => json_encode(['old' => true]),
            'is_verified' => 0,
            'received_at' => now(),
            'trace_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = (new ReflectionClass(PaymentService::class))->newInstanceWithoutConstructor();
        $service->syncProjection($payment);

        $this->assertSame(2, DB::table('payment_callbacks')->where('payment_id', (int) $payment->id)->count());
        $this->assertDatabaseHas('payment_callbacks', [
            'payment_id' => (int) $payment->id,
            'callback_type' => 'payment',
            'gateway_trade_no' => 'TRADE-UPSERT',
            'is_verified' => 1,
            'trace_id' => 'callback-upsert-test',
        ]);
        $this->assertDatabaseHas('payment_callbacks', [
            'id' => $refundCallbackId,
            'payment_id' => (int) $payment->id,
            'callback_type' => 'refund',
            'gateway_trade_no' => 'REFUND-UPSERT',
            'is_verified' => 1,
            'trace_id' => 'callback-upsert-test',
        ]);
    }

    public function test_invoice_refund_entry_writes_invoice_audit_without_order_service_delegation(): void
    {
        $user = $this->createUser();
        $order = Order::query()->create([
            'order_no' => 'ORDREFUND01',
            'user_id' => (int) $user->id,
            'type' => 'new',
            'amount' => '66.00',
            'discount' => '0.00',
            'paid_amount' => '66.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'status' => OrderStatus::PAID,
        ]);
        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVREFUND01',
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'type' => 'normal',
            'amount' => '66.00',
            'paid_amount' => '66.00',
            'status' => InvoiceStatus::PAID,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'due_date' => now()->addDay(),
        ]);

        $paymentService = Mockery::mock(PaymentService::class);
        $paymentService->shouldReceive('refundOrder')->once()->with(
            Mockery::on(static fn (Order $givenOrder): bool => (int) $givenOrder->id === (int) $order->id),
            Mockery::type('array'),
            Mockery::type('array')
        )->andReturn([
            'already_refunded' => false,
            'order_id' => (int) $order->id,
            'payment_id' => 9001,
            'refund' => [
                'refund_method' => 'original',
                'refund_method_label' => 'original refund',
                'refund_amount' => '66.00',
                'refund_reason' => 'invoice refund switch',
                'out_request_no' => 'RF-INV-ENTRY',
                'trade_no' => 'TRADE-INV-ENTRY',
            ],
        ]);
        $this->app->instance(PaymentService::class, $paymentService);

        $result = (new InvoiceService)->refundByPaymentMethod($invoice, [
            'refund_method' => 'original',
            'amount' => '66.00',
            'remark' => 'invoice refund switch',
        ], [
            'operator_id' => 1002,
            'operator_name' => 'Finance Admin',
            'trace_id' => 'invoice-refund-entry-test',
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertSame((int) $invoice->id, (int) $result['invoice_id']);
        $this->assertDatabaseHas('operation_logs', [
            'module' => 'invoice',
            'action' => 'invoice.payment.refund',
            'subject_id' => (int) $invoice->id,
        ]);
        $this->assertDatabaseMissing('operation_logs', [
            'module' => 'order',
            'action' => 'order.payment.refund',
            'subject_id' => (int) $order->id,
        ]);
    }

    private function createSchema(): void
    {
        foreach (['payment_callbacks', 'payments', 'invoice_items', 'invoices', 'orders', 'operation_logs', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->string('password');
            $table->string('phone')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_no')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('type')->default('new');
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('billing_cycle')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->integer('status')->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->json('config_snapshot')->nullable();
            $table->json('config_pricing_snapshot')->nullable();
            $table->json('coupon_snapshot')->nullable();
            $table->string('trace_id', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_no')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('type')->default('normal');
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('billing_cycle')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->integer('status')->default(0);
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('config_snapshot')->nullable();
            $table->json('config_pricing_snapshot')->nullable();
            $table->json('coupon_snapshot')->nullable();
            $table->string('trace_id', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('payment_no')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('gateway_key', 120)->nullable();
            $table->string('trade_no')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->integer('status')->default(0);
            $table->json('callback_raw')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('trace_id', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->string('item_name')->nullable();
            $table->string('item_type')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('line_amount', 12, 2)->default(0);
            $table->json('meta_json')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_callbacks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->string('callback_type', 20);
            $table->string('gateway_trade_no', 100)->nullable();
            $table->json('payload_json')->nullable();
            $table->tinyInteger('is_verified')->default(0);
            $table->timestamp('received_at')->nullable();
            $table->string('remark', 255)->nullable();
            $table->string('operator', 50)->nullable();
            $table->string('trace_id', 64)->nullable();
            $table->timestamps();
            $table->unique(['payment_id', 'callback_type'], 'payment_callbacks_payment_type_unique');
        });

        Schema::create('operation_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_type', 20)->nullable();
            $table->string('action', 100);
            $table->string('module', 50);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function createUser(): User
    {
        return User::query()->create([
            'email' => 'invoice-payment-switch-'.uniqid('', true).'@example.test',
            'password' => bcrypt('password'),
            'phone' => null,
            'status' => 1,
        ]);
    }
}
