<?php

declare(strict_types=1);

namespace App\Services\Referral;

use App\Exceptions\BusinessException;
use App\Jobs\ResyncMemberLevelsJob;
use App\Models\MemberLevel;
use App\Models\User;
use App\Models\UserReferral;
use App\Support\CacheKey;
use App\Support\DatabaseSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        // 等级本身的写入用一个短事务；存量用户重算异步化，理由见 resyncAllUserLevels()。
        $updatedLevel = DB::transaction(function () use ($level, $data) {
            $payload = $this->preparePayload($data, $level);
            $level->update($payload);

            return $level->refresh();
        });

        // 重算量与用户数成正比（实测 2000 用户 15.5 秒、外推 10 万用户约 773 秒），
        // 同步跑在 HTTP 请求里必然先撞上执行/网关超时，因此派发到队列异步执行。
        // Job 是全局单例（ShouldBeUnique）：连续改多个等级只触发一轮重算，读的是最新规则。
        ResyncMemberLevelsJob::dispatch();

        $this->forgetListCaches();

        return $updatedLevel;
    }

    /**
     * 按等级规则重算存量用户的会员等级。
     *
     * 这一步原本和「等级写入」同处一个事务里，实测 2000 个用户就要 15.5 秒、14017 条 SQL，
     * 按每用户 7 条 SQL 外推，10 万用户约 70 万条查询、约 773 秒——整段时间都持着同一个事务的锁，
     * 期间并发注册与余额变更都会被阻塞。
     *
     * 现在改为：每批单独开一个短事务，把锁的持有时间限制在一批（默认 200 个用户）之内。
     * 代价是失去了「等级写入 + 全量重算」的整体原子性：若重算中途失败，等级已经落库、
     * 部分用户尚未重算。这个取舍是可以接受的，因为重算完全幂等——结果只由 total_sales_amount
     * 与当前等级规则决定，重跑一次即可收敛，调用本方法即可补算。
     *
     * @return int 实际重算的用户数
     */
    public function resyncAllUserLevels(int $chunkSize = 200): int
    {
        $chunkSize = max(1, $chunkSize);
        $processed = 0;
        $hasReferralProfiles = DatabaseSchema::hasTable('user_referrals');

        // 候选集统一从 users 表出发：member_level_id 非空或有销售额的用户都要重算，
        // 存在档案表时再并入「档案里有等级/销售额」的用户。
        // 此前两个分支不对称——档案分支只看 member_level_id 非空的档案，会漏掉
        // 「档案缺失或档案无等级、但 users.total_sales_amount > 0」的用户：管理员调低
        // 门槛后这些本应升级的用户拿不到新等级，返利仍按旧 reward_rate 计发。
        // 整个 OR 组必须包在同一个 where 闭包里，否则 chunkById 追加的 `id > ?`
        // 会因 AND 优先级只约束到最后一个 OR 分支，导致分块永不推进。
        User::query()
            ->where(function ($query) use ($hasReferralProfiles) {
                $query
                    ->whereNotNull('member_level_id')
                    ->orWhere('total_sales_amount', '>', 0);

                if ($hasReferralProfiles) {
                    $query->orWhereIn('id', UserReferral::query()
                        ->where(function ($profile) {
                            $profile
                                ->whereNotNull('member_level_id')
                                ->orWhere('total_sales_amount', '>', 0);
                        })
                        ->select('user_id'));
                }
            })
            ->chunkById($chunkSize, function (Collection $users) use (&$processed): void {
                $processed += $this->syncUserLevelChunk($users);
            });

        return $processed;
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function syncUserLevelChunk(Collection $users): int
    {
        if ($users->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($users): int {
            foreach ($users as $user) {
                $this->syncUserLevel($user);
            }

            return $users->count();
        });
    }

    public function delete(MemberLevel $level): void
    {
        throw_if(
            DatabaseSchema::hasTable('user_referrals')
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
        $hasReferralProfilesTable = DatabaseSchema::hasTable('user_referrals');
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

        if (DatabaseSchema::hasTable('user_referrals')) {
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
