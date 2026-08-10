<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\Recharge\StatusRequest;
use App\Http\Requests\Client\V2\Recharge\StoreRequest;
use App\Services\Finance\ClientRechargeWorkflowService;

class RechargeController extends Controller
{
    public function __construct(
        private readonly ClientRechargeWorkflowService $workflow,
    ) {}

    public function gateways()
    {
        return $this->success(['list' => $this->workflow->gatewayOptions()]);
    }

    public function store(StoreRequest $request)
    {
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
