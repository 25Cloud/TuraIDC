<?php

namespace App\Services\Referral;

use App\Models\ReferralReward;
use App\Models\ReferralWithdrawal;
use App\Models\User;
use App\Models\UserAccount;
use App\Models\UserReferral;
use App\Support\AdminPrivacy;
use Illuminate\Support\Facades\Schema;

class AdminReferralOverviewService
{
    private ?bool $usersReferrerColumnAvailable = null;

    private ?bool $userReferralsTableAvailable = null;

    public function overview(): array
    {
        $rewardSummary = ReferralReward::query()
            ->selectRaw('COUNT(*) as rewards_total')
            ->selectRaw('COALESCE(SUM(order_amount), 0) as total_sales_amount')
            ->selectRaw('COALESCE(SUM(reward_amount), 0) as total_reward_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = 0 THEN reward_amount ELSE 0 END), 0) as frozen_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = 1 THEN reward_amount ELSE 0 END), 0) as released_amount')
            ->first();

        $withdrawSummary = ReferralWithdrawal::query()
            ->selectRaw('COUNT(*) as withdrawals_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = 0 THEN amount ELSE 0 END), 0) as withdrawing_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = 1 THEN amount ELSE 0 END), 0) as withdrawn_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = 2 THEN amount ELSE 0 END), 0) as rejected_amount')
            ->first();

        $topReferrersQuery = User::query()
            ->withReadAggregates()
            ->with(['memberLevel:id,name,code,reward_rate']);

        if ($this->userReferralsTableAvailable()) {
            $topReferrersQuery
                ->leftJoin('user_referrals as referral_profiles', 'referral_profiles.user_id', '=', 'users.id')
                ->select([
                    'users.id',
                    'users.email',
                    'users.phone',
                    'users.nickname',
                    'users.real_name',
                    'users.verification_status',
                ])
                ->selectRaw('COALESCE(referral_profiles.member_level_id, users.member_level_id) as member_level_id')
                ->selectRaw('COALESCE(referral_profiles.total_sales_amount, users.total_sales_amount) as total_sales_amount')
                ->whereRaw('COALESCE(referral_profiles.total_sales_amount, users.total_sales_amount) > 0')
                ->orderByRaw('COALESCE(referral_profiles.total_sales_amount, users.total_sales_amount) DESC');
        } else {
            $topReferrersQuery
                ->where('total_sales_amount', '>', 0)
                ->orderByDesc('total_sales_amount');
        }

        $topReferrersQuery->orderByDesc(
            UserAccount::query()
                ->select('referral_withdrawn_balance')
                ->whereColumn('user_accounts.user_id', 'users.id')
                ->limit(1)
        );

        $topReferrers = $topReferrersQuery
            ->limit(10)
            ->get();

        $privacy = AdminPrivacy::current();

        return [
            'summary' => [
                'rewards_total' => (int) ($rewardSummary?->rewards_total ?? 0),
                'total_sales_amount' => (float) ($rewardSummary?->total_sales_amount ?? 0),
                'total_reward_amount' => (float) ($rewardSummary?->total_reward_amount ?? 0),
                'frozen_amount' => (float) ($rewardSummary?->frozen_amount ?? 0),
                'released_amount' => (float) ($rewardSummary?->released_amount ?? 0),
                'withdrawals_total' => (int) ($withdrawSummary?->withdrawals_total ?? 0),
                'withdrawing_amount' => (float) ($withdrawSummary?->withdrawing_amount ?? 0),
                'withdrawn_amount' => (float) ($withdrawSummary?->withdrawn_amount ?? 0),
                'rejected_amount' => (float) ($withdrawSummary?->rejected_amount ?? 0),
                'direct_referral_users' => $this->directReferralUsers(),
            ],
            'top_referrers' => $topReferrers->map(fn (User $user) => [
                'id' => (int) $user->id,
                'email' => $privacy->email($user->email),
                'nickname' => (string) $user->nickname,
                'display_name' => $privacy->displayName($user->display_name, $user->email, $user->phone, $user->real_name),
                'member_level' => $user->memberLevel ? [
                    'id' => (int) $user->memberLevel->id,
                    'name' => (string) $user->memberLevel->name,
                    'code' => (string) $user->memberLevel->code,
                    'reward_rate' => $user->memberLevel->reward_rate,
                ] : null,
                'total_sales_amount' => (float) $user->total_sales_amount,
                'referral_frozen_amount' => (float) $user->referral_frozen_amount,
                'referral_available_amount' => (float) $user->referral_available_amount,
                'referral_withdrawing_amount' => (float) $user->referral_withdrawing_amount,
                'referral_withdrawn_amount' => (float) $user->referral_withdrawn_amount,
            ])->values()->all(),
        ];
    }

    private function directReferralUsers(): int
    {
        if ($this->userReferralsTableAvailable()) {
            return UserReferral::query()
                ->whereNotNull('referrer_user_id')
                ->where('referrer_user_id', '>', 0)
                ->whereHas('referrer', fn ($query) => $query->active())
                ->count();
        }

        if (! $this->usersReferrerColumnAvailable()) {
            return 0;
        }

        return User::query()
            ->whereNotNull('referrer_user_id')
            ->where('referrer_user_id', '>', 0)
            ->whereHas('referrer', fn ($query) => $query->active())
            ->count();
    }

    private function usersReferrerColumnAvailable(): bool
    {
        if ($this->usersReferrerColumnAvailable !== null) {
            return $this->usersReferrerColumnAvailable;
        }

        $this->usersReferrerColumnAvailable = Schema::hasColumn('users', 'referrer_user_id');

        return $this->usersReferrerColumnAvailable;
    }

    private function userReferralsTableAvailable(): bool
    {
        if ($this->userReferralsTableAvailable !== null) {
            return $this->userReferralsTableAvailable;
        }

        $this->userReferralsTableAvailable = Schema::hasTable('user_referrals');

        return $this->userReferralsTableAvailable;
    }
}
