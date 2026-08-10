<?php

declare(strict_types=1);

namespace App\Http\Resources\Referral\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReferralOverviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $overview = is_array($this->resource) ? $this->resource : [];
        $summary = (array) ($overview['summary'] ?? []);

        return [
            'summary' => [
                'rewards_total' => (int) ($summary['rewards_total'] ?? 0),
                'total_sales_amount' => $this->money($summary['total_sales_amount'] ?? 0),
                'total_reward_amount' => $this->money($summary['total_reward_amount'] ?? 0),
                'frozen_amount' => $this->money($summary['frozen_amount'] ?? 0),
                'released_amount' => $this->money($summary['released_amount'] ?? 0),
                'available_amount' => $this->money($summary['available_amount'] ?? ($summary['released_amount'] ?? 0)),
                'withdrawals_total' => (int) ($summary['withdrawals_total'] ?? 0),
                'withdrawing_amount' => $this->money($summary['withdrawing_amount'] ?? 0),
                'withdrawn_amount' => $this->money($summary['withdrawn_amount'] ?? 0),
                'rejected_amount' => $this->money($summary['rejected_amount'] ?? 0),
                'direct_referral_users' => (int) ($summary['direct_referral_users'] ?? 0),
            ],
            'top_referrers' => collect($overview['top_referrers'] ?? [])
                ->filter(fn (mixed $item): bool => is_array($item))
                ->take(10)
                ->map(fn (array $item): array => $this->topReferrer($item))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function topReferrer(array $item): array
    {
        return [
            'id' => (int) ($item['id'] ?? 0),
            'email' => (string) ($item['email'] ?? ''),
            'nickname' => (string) ($item['nickname'] ?? ''),
            'display_name' => (string) ($item['display_name'] ?? ''),
            'member_level' => $this->memberLevel($item['member_level'] ?? null),
            'total_sales_amount' => $this->money($item['total_sales_amount'] ?? 0),
            'referral_frozen_amount' => $this->money($item['referral_frozen_amount'] ?? 0),
            'referral_available_amount' => $this->money($item['referral_available_amount'] ?? 0),
            'referral_withdrawing_amount' => $this->money($item['referral_withdrawing_amount'] ?? 0),
            'referral_withdrawn_amount' => $this->money($item['referral_withdrawn_amount'] ?? 0),
        ];
    }

    private function memberLevel(mixed $item): ?array
    {
        if (! is_array($item)) {
            return null;
        }

        return [
            'id' => (int) ($item['id'] ?? 0),
            'name' => (string) ($item['name'] ?? ''),
            'code' => (string) ($item['code'] ?? ''),
            'reward_rate' => $this->money($item['reward_rate'] ?? 0),
        ];
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
