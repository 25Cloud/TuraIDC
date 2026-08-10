<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Models\User;
use App\Support\AdminPrivacy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class AdminUserListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $privacy = AdminPrivacy::fromRequest($request);
        $profile = $this->resource->relationLoaded('profile') ? $this->resource->getRelation('profile') : null;
        $nickname = trim((string) ($profile?->nickname ?? $this->resource->getRawOriginal('nickname') ?? ''));
        $email = (string) ($this->email ?? '');
        $phone = (string) ($this->phone ?? '');
        $realName = (string) ($this->real_name ?? '');

        return [
            'id' => (int) $this->id,
            'email' => $privacy->email($email),
            'phone' => $privacy->phone($phone),
            'nickname' => $nickname,
            'display_name' => $privacy->displayName($this->resolveDisplayName($nickname), $email, $phone, $realName),
            'company' => trim((string) ($profile?->company ?? $this->resource->getRawOriginal('company') ?? '')),
            'qq' => trim((string) ($profile?->qq ?? $this->resource->getRawOriginal('qq') ?? '')),
            'member_level_id' => $this->member_level_id !== null ? (int) $this->member_level_id : null,
            'verification_status' => (int) ($this->verification_status ?? 0),
            'real_name' => $privacy->name($realName),
            'cash_balance' => (float) ($this->resource->balance ?? 0),
            'credit_limit' => (float) ($this->resource->credit_limit ?? 0),
            'referral_frozen_balance' => (float) ($this->resource->referral_frozen_amount ?? 0),
            'referral_available_balance' => (float) ($this->resource->referral_available_amount ?? 0),
            'referral_pending_withdrawal_balance' => (float) ($this->resource->referral_withdrawing_amount ?? 0),
            'referral_withdrawn_balance' => (float) ($this->resource->referral_withdrawn_amount ?? 0),
            'status' => (int) ($this->status ?? 0),
            'is_verified' => (int) ($this->is_verified ?? 0),
            'opened_product_count' => (int) ($this->opened_product_count ?? 0),
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
}
