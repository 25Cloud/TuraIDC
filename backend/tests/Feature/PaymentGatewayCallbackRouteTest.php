<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PaymentGatewayCallbackRouteTest extends TestCase
{
    public function test_generic_payment_notify_route_is_gateway_neutral(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/api/v2/client/payment/notify/demo_pay', 'POST')
        );

        $this->assertNotContains('verify.alipay.callback', $route->gatherMiddleware());
        $this->assertContains('verify.payment.callback', $route->gatherMiddleware());
    }

    public function test_generic_payment_notify_route_accepts_get_callbacks(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/api/v2/client/payment/notify/yipay', 'GET')
        );

        $this->assertNotContains('verify.alipay.callback', $route->gatherMiddleware());
        $this->assertContains('verify.payment.callback', $route->gatherMiddleware());
    }

    public function test_legacy_alipay_notify_route_keeps_alipay_signature_middleware(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/api/v2/client/payment/alipay/notify', 'POST')
        );

        $this->assertContains('verify.alipay.callback', $route->gatherMiddleware());
    }
}
