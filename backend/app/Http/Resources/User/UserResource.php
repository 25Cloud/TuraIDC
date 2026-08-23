<?php

namespace App\Http\Resources\User;

use App\Models\User;
use App\Support\AdminPrivacy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $privacy = AdminPrivacy::fromRequest($request);
        $memberLevel = $this->resource->relationLoaded('memberLevel') ? $this->resource->getRelation('memberLevel') : null;
        $profile = $this->resource->relationLoaded('profile') ? $this->resource->getRelation('profile') : null;
        $nickname = trim((string) ($profile?->nickname ?? $this->resource->getRawOriginal('nickname') ?? ''));
        $company = trim((string) ($profile?->company ?? $this->resource->getRawOriginal('company') ?? ''));
        $qq = trim((string) ($profile?->qq ?? $this->resource->getRawOriginal('qq') ?? ''));
        $adminNote = trim((string) ($profile?->admin_note ?? $this->resource->getRawOriginal('admin_note') ?? ''));
        $email = (string) ($this->email ?? '');
        $phone = (string) ($this->phone ?? '');
        $realName = (string) ($this->real_name ?? '');

        return [
            'id' => $this->id,
            'email' => $privacy->email($email),
            'nickname' => $nickname,
            'display_name' => $privacy->displayName($this->resolveDisplayName($nickname), $email, $phone, $realName),
            'phone' => $privacy->phone($phone),
            'company' => $company,
            'qq' => $qq,
            'admin_note' => $adminNote !== '' ? $adminNote : null,
            'referral_code' => $this->referral_code,
            'referrer_user_id' => $this->referrer_user_id,
            'member_level_id' => $this->member_level_id,
            'total_sales_amount' => $this->total_sales_amount,
            'member_level' => $memberLevel ? [
                'id' => $memberLevel->id,
                'name' => $memberLevel->name,
                'code' => $memberLevel->code,
                'reward_rate' => $memberLevel->reward_rate,
            ] : null,
            'agent_group' => $this->resource->agentGroup ? [
                'id' => (int) $this->resource->agentGroup->id,
                'name' => (string) $this->resource->agentGroup->name,
                'code' => (string) $this->resource->agentGroup->code,
            ] : null,
            'cash_balance' => $this->formatMoney($this->resource->balance),
            'credit_limit' => $this->formatMoney($this->resource->credit_limit),
            'referral_frozen_balance' => $this->formatMoney($this->resource->referral_frozen_amount),
            'referral_available_balance' => $this->formatMoney($this->resource->referral_available_amount),
            'referral_pending_withdrawal_balance' => $this->formatMoney($this->resource->referral_withdrawing_amount),
            'referral_withdrawn_balance' => $this->formatMoney($this->resource->referral_withdrawn_amount),
            'active_services_count' => $this->active_services_count ?? 0,
            'status' => $this->status,
            'is_verified' => $this->is_verified,
            'real_name' => $privacy->name($realName),
            'id_card_masked' => $privacy->idCard($this->id_card),
            'verification_certify_id' => $this->verification_certify_id,
            'referred_at' => $this->referred_at?->format('Y-m-d H:i:s'),
            'alipay_real_name' => $privacy->name($this->alipay_real_name),
            'alipay_account' => $privacy->account($this->alipay_account),
            'last_login_at' => $this->last_login_at?->format('Y-m-d H:i:s'),
            'last_login_ip' => $privacy->ip($this->last_login_ip),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function resolveDisplayName(string $nickname): string
    {
        $realName = trim((string) ($this->real_name ?? ''));
        if ($realName !== '' && ((int) ($this->verification_status ?? 0) === 2 || (int) ($this->is_verified ?? 0) === 1)) {
            return $realName;
        }

        if ($nickname !== '' && preg_replace('/[\s\?？\x{FFFD}]+/u', '', $nickname) !== '') {
            return $nickname;
        }

        $email = trim((string) ($this->email ?? ''));
        if ($email !== '') {
            return $email;
        }

        return trim((string) ($this->phone ?? ''));
    }

    private function formatMoney(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
