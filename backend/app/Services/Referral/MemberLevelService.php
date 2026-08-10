<?php

declare(strict_types=1);

namespace App\Services\Referral;

use App\Exceptions\BusinessException;
use App\Models\MemberLevel;
use App\Models\User;
use App\Models\UserReferral;
use App\Support\CacheKey;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MemberLevelService
{
    private const LIST_CACHE_TTL_SECONDS = 300; // 5分钟：会员等级不频繁变化，可适当延长

    /**
     * @return Collection<int, MemberLevel>
     */
    public function list(bool $enabledOnly = false): Collection
    {
        return Cache::remember(
            $this->buildListCacheKey($enabledOnly),
            now()->addSeconds(self::LIST_CACHE_TTL_SECONDS),
            fn () => MemberLevel::query()
                ->when($enabledOnly, fn ($query) => $query->enabled())
                ->orderBy('sort_order')
                ->orderBy('sales_amount_min')
                ->orderBy('id')
                ->get()
        );
    }

    public function create(array $data): MemberLevel
    {
        $level = DB::transaction(function () use ($data) {
            $payload = $this->preparePayload($data);

            return MemberLevel::query()->create($payload);
        });

        $this->forgetListCaches();

        return $level;
    }

    public function update(MemberLevel $level, array $data): MemberLevel
    {
        $updatedLevel = DB::transaction(function () use ($level, $data) {
            $payload = $this->preparePayload($data, $level);
            $level->update($payload);

            if (Schema::hasTable('user_referrals')) {
                UserReferral::query()
                    ->whereNotNull('member_level_id')
                    ->chunkById(200, function ($profiles) {
                        foreach ($profiles as $profile) {
                            $user = User::query()->find($profile->user_id);
                            if ($user) {
                                $this->syncUserLevel($user);
                            }
                        }
                    });
            } else {
                User::query()
                    ->where(function ($query) {
                        $query
                            ->whereNotNull('member_level_id')
                            ->orWhere('total_sales_amount', '>', 0);
                    })
                    ->chunkById(200, function ($users) {
                        foreach ($users as $user) {
                            $this->syncUserLevel($user);
                        }
                    });
            }

            return $level->refresh();
        });

        $this->forgetListCaches();

        return $updatedLevel;
    }

    public function delete(MemberLevel $level): void
    {
        throw_if(
            Schema::hasTable('user_referrals')
                ? UserReferral::query()->where('member_level_id', $level->id)->exists()
                : User::query()->where('member_level_id', $level->id)->exists(),
            new BusinessException('当前等级下仍有用户，无法删除'),
        );

        $level->delete();
        $this->forgetListCaches();
    }

    public function resolveLevelBySales(float $salesAmount): ?MemberLevel
    {
        return MemberLevel::query()
            ->enabled()
            ->where('sales_amount_min', '<=', $salesAmount)
            ->where(function ($query) use ($salesAmount) {
                $query
                    ->whereNull('sales_amount_max')
                    ->orWhere('sales_amount_max', '>=', $salesAmount);
            })
            ->orderByDesc('sales_amount_min')
            ->orderBy('sort_order')
            ->first();
    }

    public function syncUserLevel(User $user): User
    {
        $hasReferralProfilesTable = Schema::hasTable('user_referrals');
        $profile = $hasReferralProfilesTable ? UserReferral::query()->find($user->id) : null;
        $salesAmount = round((float) ($profile?->total_sales_amount ?? $user->total_sales_amount), 2);
        $level = $this->resolveLevelBySales($salesAmount);
        $referralCode = $profile?->referral_code ?: $this->resolveReferralCodeForSync($user);
        $referrerUserId = $profile?->referrer_user_id ?? $user->referrer_user_id;
        $referredAt = $profile?->referred_at ?? $user->referred_at;

        if ($hasReferralProfilesTable) {
            UserReferral::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'referral_code' => $referralCode,
                    'referrer_user_id' => $referrerUserId,
                    'referred_at' => $referredAt,
                    'member_level_id' => $level?->id,
                    'total_sales_amount' => number_format($salesAmount, 2, '.', ''),
                ]
            );
        }

        $user->forceFill([
            'referral_code' => $referralCode,
            'referrer_user_id' => $referrerUserId,
            'referred_at' => $referredAt,
            'member_level_id' => $level?->id,
            'total_sales_amount' => number_format($salesAmount, 2, '.', ''),
        ])->save();
        $user->unsetRelation('referralProfile');
        $user->unsetRelation('memberLevel');

        return $user->fresh() ?? $user;
    }

    private function resolveReferralCodeForSync(User $user): string
    {
        $currentCode = strtoupper(trim((string) ($user->referral_code ?? '')));
        if (preg_match('/^[A-Z0-9]{6}$/', $currentCode) === 1) {
            return $currentCode;
        }

        do {
            $currentCode = strtoupper(Str::random(6));
        } while ($this->referralCodeExists($currentCode));

        $user->forceFill([
            'referral_code' => $currentCode,
        ])->save();

        return $currentCode;
    }

    private function referralCodeExists(string $code): bool
    {
        $normalizedCode = strtoupper(trim($code));

        if (User::query()->where('referral_code', $normalizedCode)->exists()) {
            return true;
        }

        if (Schema::hasTable('user_referrals')) {
            return UserReferral::query()->where('referral_code', $normalizedCode)->exists();
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function preparePayload(array $data, ?MemberLevel $level = null): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        throw_if($name === '', new BusinessException('等级名称不能为空'));

        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '') {
            $code = Str::slug($name, '_');
        }
        throw_if($code === '', new BusinessException('等级编码不能为空'));

        $salesMin = round((float) ($data['sales_amount_min'] ?? 0), 2);
        $salesMax = ($data['sales_amount_max'] ?? null) !== null && $data['sales_amount_max'] !== ''
            ? round((float) $data['sales_amount_max'], 2)
            : null;
        $rewardRate = round((float) ($data['reward_rate'] ?? 0), 2);

        throw_if($salesMin < 0, new BusinessException('销售金额门槛不能小于 0'));
        throw_if($salesMax !== null && $salesMax < $salesMin, new BusinessException('销售金额上限不能小于下限'));
        throw_if($rewardRate < 0 || $rewardRate > 100, new BusinessException('返利比例必须在 0-100 之间'));

        $nameExists = MemberLevel::query()
            ->when($level?->id, fn ($query) => $query->where('id', '!=', $level->id))
            ->where('name', $name)
            ->exists();
        throw_if($nameExists, new BusinessException('等级名称已存在'));

        $codeExists = MemberLevel::query()
            ->when($level?->id, fn ($query) => $query->where('id', '!=', $level->id))
            ->where('code', $code)
            ->exists();
        throw_if($codeExists, new BusinessException('等级编码已存在'));

        $overlapExists = MemberLevel::query()
            ->when($level?->id, fn ($query) => $query->where('id', '!=', $level->id))
            ->where(function ($query) use ($salesMin, $salesMax) {
                $query
                    ->where(function ($builder) use ($salesMin, $salesMax) {
                        $builder->where('sales_amount_min', '<=', $salesMin)
                            ->where(function ($child) use ($salesMin) {
                                $child->whereNull('sales_amount_max')
                                    ->orWhere('sales_amount_max', '>=', $salesMin);
                            });

                        if ($salesMax !== null) {
                            $builder->orWhere(function ($child) use ($salesMax) {
                                $child->where('sales_amount_min', '<=', $salesMax)
                                    ->where(function ($inner) use ($salesMax) {
                                        $inner->whereNull('sales_amount_max')
                                            ->orWhere('sales_amount_max', '>=', $salesMax);
                                    });
                            });
                        }
                    })
                    ->orWhere(function ($builder) use ($salesMin, $salesMax) {
                        $builder->where('sales_amount_min', '>=', $salesMin);

                        if ($salesMax !== null) {
                            $builder->where('sales_amount_min', '<=', $salesMax);
                        }
                    });
            })
            ->exists();
        throw_if($overlapExists, new BusinessException('等级销售额区间与现有等级重叠'));

        return [
            'name' => $name,
            'code' => $code,
            'sales_amount_min' => $salesMin,
            'sales_amount_max' => $salesMax,
            'reward_rate' => $rewardRate,
            'status' => (int) (($data['status'] ?? $level?->status ?? 1) ? 1 : 0),
            'sort_order' => max((int) ($data['sort_order'] ?? $level?->sort_order ?? 0), 0),
            'remark' => $this->normalizeNullableString($data['remark'] ?? $level?->remark),
        ];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function buildListCacheKey(bool $enabledOnly): string
    {
        return CacheKey::memberLevels($enabledOnly);
    }

    private function forgetListCaches(): void
    {
        Cache::forget(CacheKey::memberLevels(false));
        Cache::forget(CacheKey::memberLevels(true));
    }
}
