<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Models\User;
use App\Support\AdminPrivacy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserDetailResource extends JsonResource
{
    private const STATS_FIELDS = [
        'service_active',
        'service_total',
        'order_total',
        'order_pending',
        'total_income',
        'total_expense',
        'unpaid_amount',
        'ticket_open',
        'ticket_closed',
        'ticket_total',
        'invoice_unpaid',
        'invoice_paid',
        'direct_referral_count',
        'rewarded_orders_count',
        'total_referral_reward',
    ];

    /**
     * @return array{user: array<string, mixed>|null, stats: array<string, mixed>, referral: array<string, mixed>|null}
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];
        $user = $payload['user'] ?? null;
        $privacy = AdminPrivacy::fromRequest($request);

        return [
            'user' => $user instanceof User ? $this->projectUser($user, $privacy) : null,
            'stats' => $this->projectStats((array) ($payload['stats'] ?? [])),
            'referral' => $this->projectReferral($payload['referral'] ?? null, $privacy),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectUser(User $user, AdminPrivacy $privacy): array
    {
        $memberLevel = $user->relationLoaded('memberLevel') ? $user->getRelation('memberLevel') : null;
        $profile = $user->relationLoaded('profile') ? $user->getRelation('profile') : null;
        $nickname = trim((string) ($profile?->nickname ?? $user->getRawOriginal('nickname') ?? ''));
        $company = trim((string) ($profile?->company ?? $user->getRawOriginal('company') ?? ''));
        $qq = trim((string) ($profile?->qq ?? $user->getRawOriginal('qq') ?? ''));
        $adminNote = trim((string) ($profile?->admin_note ?? $user->getRawOriginal('admin_note') ?? ''));
        $email = (string) ($user->email ?? '');
        $phone = (string) ($user->phone ?? '');
        $realName = (string) ($user->real_name ?? '');

        return [
            'id' => (int) $user->id,
            'email' => $privacy->email($email),
            'nickname' => $nickname,
            'display_name' => $privacy->displayName($this->resolveDisplayName($user, $nickname), $email, $phone, $realName),
            'phone' => $privacy->phone($phone),
            'company' => $company,
            'qq' => $qq,
            'admin_note' => $adminNote !== '' ? $adminNote : null,
            'referral_code' => (string) ($user->referral_code ?? ''),
            'referrer_user_id' => $user->referrer_user_id !== null ? (int) $user->referrer_user_id : null,
            'member_level_id' => $user->member_level_id !== null ? (int) $user->member_level_id : null,
            'total_sales_amount' => $this->formatMoney($user->total_sales_amount ?? 0),
            'member_level' => $memberLevel ? [
                'id' => (int) $memberLevel->id,
                'name' => (string) $memberLevel->name,
                'code' => (string) $memberLevel->code,
                'reward_rate' => $this->formatMoney($memberLevel->reward_rate ?? 0),
            ] : null,
            'cash_balance' => $this->formatMoney($user->balance ?? 0),
            'credit_limit' => $this->formatMoney($user->credit_limit ?? 0),
            'referral_frozen_balance' => $this->formatMoney($user->referral_frozen_amount ?? 0),
            'referral_available_balance' => $this->formatMoney($user->referral_available_amount ?? 0),
            'referral_pending_withdrawal_balance' => $this->formatMoney($user->referral_withdrawing_amount ?? 0),
            'referral_withdrawn_balance' => $this->formatMoney($user->referral_withdrawn_amount ?? 0),
            'active_services_count' => (int) ($user->active_services_count ?? 0),
            'status' => (int) ($user->status ?? 0),
            'is_verified' => (int) ($user->is_verified ?? 0),
            'verification_status' => (int) ($user->verification_status ?? 0),
            'verification_status_label' => User::verificationStatusLabel((int) ($user->verification_status ?? 0)),
            'real_name' => $privacy->name($realName),
            'id_card_masked' => $privacy->idCard($user->id_card),
            'referred_at' => $user->referred_at?->format('Y-m-d H:i:s'),
            'alipay_real_name' => $privacy->name($user->alipay_real_name),
            'alipay_account' => $privacy->account($user->alipay_account),
            'last_login_at' => $user->last_login_at?->format('Y-m-d H:i:s'),
            'last_login_ip' => $privacy->ip($user->last_login_ip),
            'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    private function projectStats(array $stats): array
    {
        return collect(self::STATS_FIELDS)
            ->mapWithKeys(static fn (string $field): array => [$field => $stats[$field] ?? 0])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function projectReferral(mixed $referral, AdminPrivacy $privacy): ?array
    {
        if (! is_array($referral)) {
            return null;
        }

        $memberLevel = is_array($referral['member_level'] ?? null) ? $referral['member_level'] : null;

        return [
            'referral_code' => (string) ($referral['referral_code'] ?? ''),
            'referrer_user_id' => isset($referral['referrer_user_id']) ? (int) $referral['referrer_user_id'] : null,
            'member_level' => $memberLevel ? [
                'id' => (int) ($memberLevel['id'] ?? 0),
                'name' => (string) ($memberLevel['name'] ?? ''),
                'code' => (string) ($memberLevel['code'] ?? ''),
                'reward_rate' => (float) ($memberLevel['reward_rate'] ?? 0),
            ] : null,
            'total_sales_amount' => (float) ($referral['total_sales_amount'] ?? 0),
            'referral_frozen_amount' => (float) ($referral['referral_frozen_amount'] ?? 0),
            'referral_available_amount' => (float) ($referral['referral_available_amount'] ?? 0),
            'referral_withdrawing_amount' => (float) ($referral['referral_withdrawing_amount'] ?? 0),
            'referral_withdrawn_amount' => (float) ($referral['referral_withdrawn_amount'] ?? 0),
            'recent_referrals' => $this->projectRecentReferrals((array) ($referral['recent_referrals'] ?? []), $privacy),
        ];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function projectRecentReferrals(array $items, AdminPrivacy $privacy): array
    {
        return collect($items)
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->take(8)
            ->map(static function (array $item) use ($privacy): array {
                $email = (string) ($item['email'] ?? '');
                $displayName = (string) ($item['display_name'] ?? '');

                return [
                    'id' => (int) ($item['id'] ?? 0),
                    'email' => $privacy->email($email),
                    'nickname' => (string) ($item['nickname'] ?? ''),
                    'display_name' => $privacy->displayName($displayName, $email),
                    'created_at' => (string) ($item['created_at'] ?? ''),
                    'referred_at' => (string) ($item['referred_at'] ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    private function resolveDisplayName(User $user, string $nickname): string
    {
        $realName = trim((string) ($user->real_name ?? ''));
        if ($realName !== '' && ((int) ($user->verification_status ?? 0) === 2 || (int) ($user->is_verified ?? 0) === 1)) {
            return $realName;
        }

        if ($nickname !== '' && preg_replace('/[\s\?？\x{FFFD}]+/u', '', $nickname) !== '') {
            return $nickname;
        }

        $email = trim((string) ($user->email ?? ''));
        if ($email !== '') {
            return $email;
        }

        return trim((string) ($user->phone ?? ''));
    }

    private function formatMoney(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
