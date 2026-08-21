<?php

namespace App\Http\Controllers\Client\V2;

use App\Constants\OrderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\Referral\AccountLogsRequest;
use App\Http\Requests\Client\V2\Referral\ApplyWithdrawalRequest;
use App\Http\Requests\Client\V2\Referral\RewardsRequest;
use App\Http\Requests\Client\V2\Referral\WithdrawalsRequest;
use App\Models\ReferralWithdrawal;
use App\Services\Referral\ReferralService;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function __construct(private ReferralService $referralService) {}

    public function overview(Request $request)
    {
        return $this->success($this->referralService->overview($request->user()));
    }

    public function rewards(RewardsRequest $request)
    {
        // validation handled by RewardsRequest

        $perPage = max(1, min((int) $request->input('page_size', 15), 50));
        $paginator = $this->referralService->rewardLogs($request->user(), $perPage);

        return $this->success([
            'list' => collect($paginator->items())->map(function ($item) {
                return [
                    'id' => $item->id,
                    'reward_amount' => number_format((float) $item->reward_amount, 2, '.', ''),
                    'reward_rate' => number_format((float) $item->reward_rate, 2, '.', ''),
                    'order_amount' => number_format((float) $item->order_amount, 2, '.', ''),
                    'order_type' => $this->resolveOrderTypeLabel($item->order?->type),
                    'status' => (int) $item->status,
                    'rewarded_at' => $item->rewarded_at?->format('Y-m-d H:i:s'),
                    'available_at' => $item->available_at?->format('Y-m-d H:i:s'),
                    'released_at' => $item->released_at?->format('Y-m-d H:i:s'),
                    'remark' => $item->remark,
                    'referred_user' => [
                        'id' => $item->referredUser?->id,
                        'email' => $item->referredUser?->email,
                        'nickname' => $item->referredUser?->nickname,
                        'display_name' => $item->referredUser?->display_name ?: $item->referredUser?->email,
                    ],
                    'invoice' => [
                        'id' => $item->invoice?->id,
                        'invoice_no' => $item->invoice?->invoice_no,
                        'product_display_name' => $this->referralService->resolveRewardProductDisplayName($item),
                    ],
                    'product' => [
                        'id' => $item->product?->id,
                        'custom_display_name' => $item->product?->custom_display_name,
                        'display_name' => $this->referralService->resolveRewardProductDisplayName($item),
                    ],
                ];
            })->values()->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ]);
    }

    public function accountLogs(AccountLogsRequest $request)
    {
        $filters = $request->validated();

        if (empty($filters['event_type']) && ! empty($filters['type'])) {
            $filters['event_type'] = (string) $filters['type'];
        }

        unset($filters['type']);

        $perPage = max(1, min((int) $request->input('page_size', 15), 50));
        $paginator = $this->referralService->accountLogs($request->user(), $filters, $perPage);

        return $this->success([
            'list' => collect($paginator->items())
                ->map(fn ($item) => $this->referralService->transformAccountLogRecord($item))
                ->values()
                ->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ]);
    }

    public function directReferrals(Request $request)
    {
        $perPage = max(1, min((int) $request->input('page_size', 15), 50));
        $paginator = $this->referralService->directReferrals((int) $request->user()->id, $perPage);

        return $this->success([
            'list' => collect($paginator->items())->map(function ($item) {
                return [
                    'id' => $item->id,
                    'email' => $item->email,
                    'nickname' => $item->nickname,
                    'display_name' => $item->display_name,
                    'created_at' => $item->created_at?->format('Y-m-d H:i:s'),
                    'referred_at' => $item->referred_at?->format('Y-m-d H:i:s'),
                    'customer_consumption' => number_format((float) ($item->customer_consumption ?? 0), 2, '.', ''),
                    'my_earnings' => number_format((float) ($item->my_earnings ?? 0), 2, '.', ''),
                ];
            })->values()->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ]);
    }

    public function withdrawals(WithdrawalsRequest $request)
    {
        // validation handled by WithdrawalsRequest

        $perPage = max(1, min((int) $request->input('page_size', 15), 50));
        $paginator = $this->referralService->withdrawalLogs($request->user(), $perPage);

        return $this->success([
            'list' => collect($paginator->items())->map(fn ($item) => [
                'id' => $item->id,
                'amount' => number_format((float) $item->amount, 2, '.', ''),
                'method' => $item->method,
                'account_name' => $item->account_name_display,
                'account_no' => $item->account_no,
                'status' => (int) $item->status,
                'status_label' => ReferralWithdrawal::statusLabel((int) $item->status),
                'remark' => $item->remark,
                'created_at' => $item->created_at?->format('Y-m-d H:i:s'),
                'processed_at' => $item->processed_at?->format('Y-m-d H:i:s'),
            ])->values()->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ]);
    }

    public function applyWithdrawal(ApplyWithdrawalRequest $request)
    {
        $data = $request->validated();

        $withdrawal = $this->referralService->createWithdrawal(
            $request->user(),
            $data,
            (string) $request->header('X-Request-Id', ''),
        );

        return $this->success([
            'id' => $withdrawal->id,
            'amount' => number_format((float) $withdrawal->amount, 2, '.', ''),
            'status' => (int) $withdrawal->status,
            'status_label' => ReferralWithdrawal::statusLabel((int) $withdrawal->status),
            'created_at' => $withdrawal->created_at?->format('Y-m-d H:i:s'),
        ], '提现申请已提交');
    }

    private function resolveOrderTypeLabel(?string $type): string
    {
        return match ($type) {
            OrderType::NEW => '新购',
            OrderType::RENEW => '续费',
            OrderType::UPGRADE => '升级',
            default => '--',
        };
    }
}
