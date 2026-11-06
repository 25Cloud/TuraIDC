<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\Recharge\StatusRequest;
use App\Http\Requests\Client\V2\Recharge\StoreRequest;
use App\Services\Auth\AccountPolicyService;
use App\Services\Finance\ClientRechargeWorkflowService;

class RechargeController extends Controller
{
    public function __construct(
        private readonly ClientRechargeWorkflowService $workflow,
        private readonly AccountPolicyService $accountPolicy,
    ) {}

    public function gateways()
    {
        return $this->success(['list' => $this->workflow->gatewayOptions()]);
    }

    public function store(StoreRequest $request)
    {
        // 账户策略前置：拦在网关选择之前，用户看到的是「缺哪几项」，而不是「无可用支付方式」
        $this->accountPolicy->assertSatisfied($request->user(), 'recharge');

        return $this->success(
            $this->workflow->create($request->user(), $request->validated(), (string) $request->ip()),
            '充值二维码已生成',
        );
    }

    public function status(StatusRequest $request, string $paymentNo)
    {
        return $this->success($this->workflow->status(
            $request->user(),
            $paymentNo,
            (string) $request->validated('poll_token'),
            (string) $request->ip(),
        ));
    }
}
