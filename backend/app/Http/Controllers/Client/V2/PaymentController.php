<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\Payment\ListPaymentsRequest;
use App\Http\Requests\Client\V2\Payment\ShowPaymentRequest;
use App\Http\Requests\Client\V2\Payment\SummarizePaymentsRequest;
use App\Services\Finance\ClientPaymentQueryService;

class PaymentController extends Controller
{
    public function __construct(
        private readonly ClientPaymentQueryService $payments,
    ) {}

    public function index(ListPaymentsRequest $request)
    {
        return $this->success($this->payments->paginate(
            (int) $request->user()->id,
            $request->validated(),
            $this->paymentWindowExpiredContext(),
        ));
    }

    public function summary(SummarizePaymentsRequest $request)
    {
        return $this->success($this->payments->summary(
            (int) $request->user()->id,
            $this->paymentWindowExpiredContext(),
        ));
    }

    public function show(int $id, ShowPaymentRequest $request)
    {
        return $this->success($this->payments->detail(
            (int) $request->user()->id,
            $id,
            $this->paymentWindowExpiredContext(),
        ));
    }

    /**
     * @return array<string, string>
     */
    private function paymentWindowExpiredContext(): array
    {
        return [
            'actor_type' => 'system',
            'actor_name' => 'payment-window-expired',
            'reason' => 'payment_window_expired',
        ];
    }
}
