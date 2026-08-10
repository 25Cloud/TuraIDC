<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Models\UserAccount;
use App\Support\UserBalanceCache;

class AccountService
{
    private const MONEY_FIELDS = [
        'cash_balance',
        'credit_limit',
        'referral_frozen_balance',
        'referral_available_balance',
        'referral_pending_withdrawal_balance',
        'referral_withdrawn_balance',
    ];

    public function ensureAccount(User|int $user, bool $lockForUpdate = false): UserAccount
    {
        $userId = $this->resolveUserId($user);
        $query = UserAccount::query();

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $account = $query->find($userId);
        if ($account instanceof UserAccount) {
            return $account;
        }

        // 使用 firstOrCreate 防止并发创建导致的 duplicate key 异常
        $account = UserAccount::query()->firstOrCreate(
            ['user_id' => $userId],
            $this->defaultAttributes(),
        );

        if ($lockForUpdate) {
            $account = UserAccount::query()->lockForUpdate()->findOrFail($userId);
        }

        return $account;
    }

    public function cashBalance(User|int $user, bool $lockForUpdate = false): float
    {
        $account = $this->ensureAccount($user, $lockForUpdate);

        return round((float) $account->cash_balance, 2);
    }

    public function setCashBalance(User|int $user, float|string $balance, bool $lockForUpdate = false): string
    {
        $account = $this->updateAccount($user, [
            'cash_balance' => $balance,
        ], $lockForUpdate);

        return $this->money($account->cash_balance);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateAccount(UserAccount|User|int $accountOrUser, array $attributes, bool $lockForUpdate = false): UserAccount
    {
        $account = $accountOrUser instanceof UserAccount
            ? $accountOrUser
            : $this->ensureAccount($accountOrUser, $lockForUpdate);

        if ($lockForUpdate && $accountOrUser instanceof UserAccount) {
            $account = UserAccount::query()->lockForUpdate()->findOrFail((int) $accountOrUser->user_id);
        }

        $payload = [];
        foreach (self::MONEY_FIELDS as $field) {
            if (array_key_exists($field, $attributes)) {
                $payload[$field] = $this->money($attributes[$field]);
            }
        }

        if ($payload === []) {
            return $account;
        }

        $payload['version'] = (int) ($account->version ?? 0) + 1;
        $account->forceFill($payload)->save();

        if (array_key_exists('cash_balance', $payload)) {
            UserBalanceCache::forget((int) $account->user_id);
        }

        return $account->refresh();
    }

    private function resolveUserId(User|int $user): int
    {
        return $user instanceof User ? (int) $user->id : (int) $user;
    }

    /**
     * @return array<string, string|int>
     */
    private function defaultAttributes(): array
    {
        return [
            'cash_balance' => '0.00',
            'credit_limit' => '0.00',
            'referral_frozen_balance' => '0.00',
            'referral_available_balance' => '0.00',
            'referral_pending_withdrawal_balance' => '0.00',
            'referral_withdrawn_balance' => '0.00',
            'version' => 0,
        ];
    }

    private function money(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '0.00';
        }

        return number_format(round((float) $value, 2), 2, '.', '');
    }
}
