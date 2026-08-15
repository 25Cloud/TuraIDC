<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ServiceStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Automation\AutoRenewService;
use App\Services\Finance\PaymentService;
use App\Services\Provisioning\ServiceRenewService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AutoRenewServiceTest extends TestCase
{
    #[Test]
    public function it_creates_a_fresh_renew_order_and_pays_by_balance_when_user_balance_is_enough(): void
    {
        $suffix = bin2hex(random_bytes(4));

        Service::query()->update(['auto_renew' => 0]);

        $user = User::query()->create([
            'email' => 'auto-renew-paid-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Auto Renew Paid',
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
        ]);
        // balance 已从 $fillable 移出，通过 forceFill+save 触发 booted hook 同步到 user_accounts
        $user->forceFill(['balance' => '49.00'])->save();
        $user->refresh();

        $product = Product::query()->create([
            'name' => 'Auto Renew Paid Product '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '49.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Auto Renew Paid Service '.$suffix,
            'domain' => 'auto-renew-paid-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '49.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => ['monthly' => '49.00'],
            'provision_data' => [],
            'expires_at' => Carbon::now()->addDays(3),
            'auto_renew' => 1,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVAUTOPAID'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'service_id' => (int) $service->id,
            'type' => 'renew',
            'amount' => '49.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'status' => 0,
            'due_date' => now()->addDay(),
        ]);

        $order = new Order([
            'order_no' => 'ORDAUTOPAID'.strtoupper($suffix),
            'billing_cycle' => 'monthly',
        ]);
        $order->id = 999001;
        $order->exists = true;
        $order->setRelation('invoice', $invoice);

        $renewService = $this->createMock(ServiceRenewService::class);
        $renewService->expects($this->once())
            ->method('previewForUser')
            ->with(
                $this->callback(fn ($model) => $model instanceof User && (int) $model->id === (int) $user->id),
                (int) $service->id,
                'monthly',
                0
            )
            ->willReturn([
                'default_cycle' => 'monthly',
                'renew_price' => '49.00',
                'cycles' => [
                    ['billing_cycle' => 'monthly', 'amount' => '49.00'],
                ],
            ]);
        $renewService->expects($this->once())
            ->method('createRenewOrderForUser')
            ->with(
                $this->callback(fn ($model) => $model instanceof User && (int) $model->id === (int) $user->id),
                (int) $service->id,
                'monthly',
                0,
                $this->callback(fn (array $context): bool => ($context['auto_renew'] ?? false) === true)
            )
            ->willReturn($order);

        $paymentService = $this->createMock(PaymentService::class);
        $paymentService->expects($this->once())
            ->method('payOrderByBalance')
            ->with(
                $this->callback(fn ($model) => $model instanceof Order && (int) $model->id === 999001),
                $this->callback(fn ($model) => $model instanceof User && (int) $model->id === (int) $user->id),
                $this->callback(fn (array $context): bool => ($context['auto_renew'] ?? false) === true)
            );

        $summary = (new AutoRenewService($renewService, $paymentService))->handle();

        $this->assertSame(1, $summary['matched']);
        $this->assertSame(1, $summary['paid']);
        $this->assertSame(0, $summary['pending']);
        $this->assertSame(0, $summary['recovered']);
    }

    #[Test]
    public function it_skips_auto_renew_order_creation_when_balance_is_insufficient(): void
    {
        $suffix = bin2hex(random_bytes(4));

        Service::query()->update(['auto_renew' => 0]);

        $user = User::query()->create([
            'email' => 'auto-renew-pending-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Auto Renew Pending',
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
        ]);

        $product = Product::query()->create([
            'name' => 'Auto Renew Pending Product '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '49.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Auto Renew Pending Service '.$suffix,
            'domain' => 'auto-renew-pending-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '49.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => ['monthly' => '49.00'],
            'provision_data' => [],
            'expires_at' => Carbon::now()->addDays(3),
            'auto_renew' => 1,
        ]);

        $renewService = $this->createMock(ServiceRenewService::class);
        $renewService->expects($this->once())
            ->method('previewForUser')
            ->with(
                $this->callback(fn ($model) => $model instanceof User && (int) $model->id === (int) $user->id),
                (int) $service->id,
                'monthly',
                0
            )
            ->willReturn([
                'default_cycle' => 'monthly',
                'renew_price' => '49.00',
                'cycles' => [
                    ['billing_cycle' => 'monthly', 'amount' => '49.00'],
                ],
            ]);
        $renewService->expects($this->never())->method('createRenewOrderForUser');

        $paymentService = $this->createMock(PaymentService::class);
        $paymentService->expects($this->never())->method('payOrderByBalance');

        $summary = (new AutoRenewService($renewService, $paymentService))->handle();

        $this->assertSame(1, $summary['matched']);
        $this->assertSame(0, $summary['paid']);
        $this->assertSame(1, $summary['pending']);
        $this->assertSame(0, $summary['recovered']);
    }
}
