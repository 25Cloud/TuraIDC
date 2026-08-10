<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\VerificationHistory;
use App\Support\AdminPrivacy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

class AdminVerificationQueryService
{
    private ?bool $verificationHistoryTableAvailable = null;

    public function __construct(
        private VerificationService $verificationService,
    ) {}

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return User::query()
            ->withReadAggregates()
            ->select([
                'id',
                'email',
                'phone',
                'nickname',
                'real_name',
                'id_card',
                'verification_status',
                'verification_message',
                'created_at',
            ])
            ->when(! empty($filters['keyword']), fn ($query) => $query->search((string) $filters['keyword']))
            ->when(
                array_key_exists('is_verified', $filters),
                function ($query) use ($filters) {
                    if ((int) $filters['is_verified'] === 1) {
                        $query->where('verification_status', 2);

                        return;
                    }

                    $query->where('verification_status', '<>', 2);
                }
            )
            ->when(
                array_key_exists('verification_status', $filters),
                function ($query) use ($filters) {
                    $status = (int) $filters['verification_status'];

                    if ($status === 1) {
                        // 1=pending(旧), 4=pending(当前) — 合并展示"待认证"
                        $query->whereIn('verification_status', [1, 4]);

                        return;
                    }

                    $query->where('verification_status', $status);
                }
            )
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function summary(): array
    {
        $stats = User::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN verification_status = 2 THEN 1 ELSE 0 END), 0) as verified')
            ->selectRaw('COALESCE(SUM(CASE WHEN verification_status = 4 THEN 1 ELSE 0 END), 0) as pending')
            ->selectRaw('COALESCE(SUM(CASE WHEN verification_status = 3 THEN 1 ELSE 0 END), 0) as failed')
            ->selectRaw('COALESCE(SUM(CASE WHEN verification_status = 5 THEN 1 ELSE 0 END), 0) as unbound')
            ->first();

        return [
            'stats' => [
                'total' => (int) ($stats?->total ?? 0),
                'verified' => (int) ($stats?->verified ?? 0),
                'pending' => (int) ($stats?->pending ?? 0),
                'failed' => (int) ($stats?->failed ?? 0),
                'unbound' => (int) ($stats?->unbound ?? 0),
            ],
            'config' => $this->verificationService->getConfigSummary(),
        ];
    }

    public function detail(User $user): array
    {
        $privacy = AdminPrivacy::current();
        $config = $this->verificationService->getConfigSummary();
        $bizCode = (string) ($config['verification_biz_code'] ?? 'FACE');
        $idCard = trim((string) $user->id_card);
        $email = (string) $user->email;
        $phone = (string) $user->phone;
        $realName = (string) $user->real_name;

        return [
            'id' => (int) $user->id,
            'display_name' => $privacy->displayName($user->display_name, $email, $phone, $realName),
            'email' => $privacy->email($email),
            'phone' => $privacy->phone($phone),
            'real_name' => $privacy->name($realName),
            'id_card_masked' => $privacy->idCard($idCard),
            'verification_status' => (int) $user->verification_status,
            'verification_message' => (string) $user->verification_message,
            'verification_certify_id' => $user->verification_certify_id,
            'verification_biz_code' => $bizCode,
            'verification_method_label' => $this->bizCodeLabel($bizCode),
            'verification_type_label' => '个人认证',
            'document_type_label' => $idCard !== '' ? '居民身份证' : '-',
            'identity_region_label' => $idCard !== '' ? '大陆' : '-',
            'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $user->updated_at?->format('Y-m-d H:i:s'),
            'verified_at' => $user->verified_at?->format('Y-m-d H:i:s'),
        ];
    }

    public function history(User $user): array
    {
        $privacy = AdminPrivacy::current();
        $list = collect();

        if ($this->verificationHistoryTableAvailable()) {
            $list = VerificationHistory::query()
                ->where('user_id', $user->id)
                ->orderByDesc('submitted_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (VerificationHistory $history) => $this->transformHistory($history))
                ->values();
        }

        if ($list->isEmpty() && $this->hasVerificationSnapshot($user)) {
            $config = $this->verificationService->getConfigSummary();
            $bizCode = (string) ($config['verification_biz_code'] ?? 'FACE');
            $idCard = trim((string) $user->id_card);
            $realName = (string) $user->real_name;

            $list = collect([[
                'id' => 0,
                'real_name' => $privacy->name($realName),
                'id_card_masked' => $privacy->idCard($idCard),
                'verification_status' => (int) $user->verification_status,
                'verification_message' => (string) $user->verification_message,
                'verification_certify_id' => $user->verification_certify_id,
                'verification_method_label' => $this->bizCodeLabel($bizCode),
                'verification_type_label' => '个人认证',
                'submitted_at' => $user->created_at?->format('Y-m-d H:i:s'),
                'completed_at' => $user->verified_at?->format('Y-m-d H:i:s'),
            ]]);
        }

        return [
            'user_name' => $privacy->displayName($user->display_name, $user->email, $user->phone, $user->real_name),
            'list' => $list->values()->all(),
        ];
    }

    private function bizCodeLabel(string $bizCode): string
    {
        return match ($bizCode) {
            'CERT_PHOTO' => '证照认证',
            'CERT_PHOTO_FACE' => '证照+人脸',
            'SMART_FACE' => '快捷认证',
            default => '人脸识别',
        };
    }

    private function transformHistory(VerificationHistory $history): array
    {
        $privacy = AdminPrivacy::current();
        $idCard = trim((string) $history->id_card);

        return [
            'id' => (int) $history->id,
            'real_name' => $privacy->name($history->real_name),
            'id_card_masked' => $privacy->idCard($idCard),
            'verification_status' => (int) $history->verification_status,
            'verification_message' => (string) $history->verification_message,
            'verification_certify_id' => $history->verification_certify_id,
            'verification_method_label' => $this->bizCodeLabel((string) $history->verification_biz_code),
            'verification_type_label' => $history->verification_type === 'personal' ? '个人认证' : '企业认证',
            'submitted_at' => $history->submitted_at?->format('Y-m-d H:i:s'),
            'completed_at' => $history->completed_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function hasVerificationSnapshot(User $user): bool
    {
        return (int) $user->verification_status > 0
            || trim((string) $user->real_name) !== ''
            || trim((string) $user->id_card) !== '';
    }

    private function verificationHistoryTableAvailable(): bool
    {
        if ($this->verificationHistoryTableAvailable !== null) {
            return $this->verificationHistoryTableAvailable;
        }

        $this->verificationHistoryTableAvailable = Schema::hasTable('verification_histories');

        return $this->verificationHistoryTableAvailable;
    }
}
