<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Models\Payment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class PaymentBoundaryAuditCommandTest extends BaseTestCase
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

    public function test_audit_reports_historical_non_third_party_payments(): void
    {
        $this->insertHistoricalPayment('PAY-ALIPAY-1', PaymentGatewayCode::ALIPAY);
        $this->insertHistoricalPayment('PAY-BALANCE-1', PaymentGatewayCode::BALANCE);
        $this->insertHistoricalPayment('PAY-MANUAL-1', PaymentGatewayCode::MANUAL);

        $exitCode = Artisan::call('payment:audit-third-party-boundary', [
            '--json' => true,
            '--baseline-non-third-party' => 2,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, (int) $payload['summary']['third_party_payment_count']);
        $this->assertSame(2, (int) $payload['summary']['historical_non_third_party_payment_count']);
        $this->assertFalse((bool) $payload['summary']['historical_non_third_party_exceeded_baseline']);
    }

    public function test_audit_strict_fails_when_historical_non_third_party_count_grows(): void
    {
        $this->insertHistoricalPayment('PAY-BALANCE-1', PaymentGatewayCode::BALANCE);
        $this->insertHistoricalPayment('PAY-MANUAL-1', PaymentGatewayCode::MANUAL);

        $exitCode = Artisan::call('payment:audit-third-party-boundary', [
            '--json' => true,
            '--strict' => true,
            '--baseline-non-third-party' => 1,
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_payment_model_allows_only_third_party_gateway_on_create(): void
    {
        Payment::query()->create([
            'payment_no' => 'PAY-ALIPAY-OK',
            'user_id' => 1,
            'gateway' => PaymentGatewayCode::ALIPAY,
            'amount' => '10.00',
            'status' => PaymentStatus::PENDING,
        ]);

        $this->assertDatabaseHas('payments', [
            'payment_no' => 'PAY-ALIPAY-OK',
            'gateway_key' => PaymentGatewayCode::ALIPAY,
        ]);

        $this->expectException(InvalidArgumentException::class);

        Payment::query()->create([
            'payment_no' => 'PAY-MANUAL-BLOCKED',
            'user_id' => 1,
            'gateway' => PaymentGatewayCode::MANUAL,
            'amount' => '10.00',
            'status' => PaymentStatus::SUCCESS,
        ]);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('payment_callbacks');
        Schema::dropIfExists('payments');

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('payment_no')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('gateway_key', 30)->nullable();
            $table->string('trade_no')->nullable();
            $table->decimal('amount', 12, 2);
            $table->tinyInteger('status')->default(PaymentStatus::PENDING);
            $table->json('callback_raw')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('trace_id')->nullable();
            $table->timestamps();
        });
    }

    private function insertHistoricalPayment(string $paymentNo, string $gateway): void
    {
        DB::table('payments')->insert([
            'payment_no' => $paymentNo,
            'user_id' => 1,
            'gateway_key' => $gateway,
            'amount' => '10.00',
            'status' => PaymentStatus::SUCCESS,
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
