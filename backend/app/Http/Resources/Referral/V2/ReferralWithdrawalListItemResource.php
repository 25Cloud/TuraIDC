<?php

declare(strict_types=1);

namespace App\Http\Resources\Referral\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReferralWithdrawalListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => (int) ($item['id'] ?? 0),
            'amount' => $this->money($item['amount'] ?? 0),
            'method' => (string) ($item['method'] ?? ''),
            'account_name' => (string) ($item['account_name'] ?? ''),
            'account_no' => (string) ($item['account_no'] ?? ''),
            'status' => (int) ($item['status'] ?? 0),
            'remark' => $item['remark'] ?? null,
            'operator' => $item['operator'] ?? null,
            'created_at' => $item['created_at'] ?? null,
            'processed_at' => $item['processed_at'] ?? null,
            'user' => $this->user($item['user'] ?? null),
        ];
    }

    private function user(mixed $user): ?array
    {
        if (! is_array($user)) {
            return null;
        }

        return [
            'id' => (int) ($user['id'] ?? 0),
            'email' => (string) ($user['email'] ?? ''),
            'nickname' => (string) ($user['nickname'] ?? ''),
            'display_name' => (string) ($user['display_name'] ?? ''),
        ];
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
