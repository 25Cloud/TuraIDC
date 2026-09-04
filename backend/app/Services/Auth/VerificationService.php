<?php

namespace App\Services\Auth;

use App\Constants\FinanceLedgerEventType;
use App\Exceptions\BusinessException;
use App\Models\AccountTransaction;
use App\Models\IntegrationPlugin;
use App\Models\User;
use App\Models\VerificationHistory;
use App\Services\Integrations\Plugins\IntegrationDriverBindingResolver;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginDomain;
use App\Services\User\AccountService;
use App\Services\Verification\Contracts\ProvidesVerificationFeeConfig;
use App\Services\Verification\Contracts\VerificationDriver;
use App\Services\Verification\Data\VerificationInitializeRequest;
use App\Services\Verification\Data\VerificationInitializeResult;
use App\Services\Verification\Data\VerificationScanUrlResult;
use App\Services\Verification\Data\VerificationStatusResult;
use App\Services\Verification\VerificationDriverManager;
use App\Support\PublicUrl;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class VerificationService
{
    private const API_STATUS_SUCCESS = 200;

    private const API_STATUS_FAILED = 400;

    private const API_STATUS_NETWORK_ERROR = 500;

    private const RESULT_STATUS_SUCCESS = 1;

    private const RESULT_STATUS_FAILED = 2;

    private const RESULT_STATUS_NETWORK_ERROR = 3;

    private const RESULT_STATUS_PENDING = 4;

    private const RESULT_STATUS_UNBOUND = 5;

    private const VERIFICATION_TYPE_PERSONAL = 'personal';

    private const QR_CODE_URL_CACHE_PREFIX = 'verification:qrcode_url:';

    private const QR_CODE_URL_CACHE_TTL_SECONDS = 300;

    private VerificationDriverManager $driverManager;

    private ?bool $verificationHistoryTableAvailable = null;

    private ?array $verificationPluginConfigCache = null;

    public function __construct(
        VerificationDriverManager $driverManager,
        private ?PluginConfigRepository $pluginConfigRepository = null,
        private ?IntegrationDriverBindingResolver $driverBindingResolver = null,
        private ?AccountService $accountService = null,
    ) {
        $this->driverManager = $driverManager;
    }

    public function getDriverManager(): VerificationDriverManager
    {
        return $this->driverManager;
    }

    public function initVerification(User $user, string $realname, string $idcard, string $certType = 'IDENTITY_CARD'): array
    {
        $verification = $this->getVerificationSnapshot($user);
        $previousCertifyId = $verification['certify_id'];

        $response = $this->getCertifyId($realname, $idcard, $certType);
        $this->assertSourceResponseSuccess($response, '认证初始化失败');

        $certifyId = $response->certifyId;

        DB::transaction(function () use ($user, $realname, $idcard, $certifyId): void {
            // 实名收费：免费次数内免费，超出后按插件配置扣余额（与状态持久化同事务保证原子）。
            $this->chargeVerificationIfNeeded($user);

            $updatedUser = $this->persistVerificationState($user, [
                'verification_status' => self::RESULT_STATUS_PENDING,
                'real_name' => $realname,
                'id_card' => $idcard,
                'certify_id' => $certifyId,
                'verification_message' => '等待认证',
                'last_submitted_at' => now(),
                'verified_at' => null,
            ]);

            $this->createHistoryEntry($updatedUser, $certifyId);
        });

        if ($previousCertifyId !== '' && $previousCertifyId !== $certifyId) {
            $this->forgetQrCodeUrlCache($previousCertifyId);
        }

        return [
            'certify_id' => $certifyId,
            'status' => self::RESULT_STATUS_SUCCESS,
            'message' => '实名认证初始化成功',
        ];
    }

    public function startVerificationSession(User $user, string $realname, string $idcard, string $certType = 'IDENTITY_CARD'): array
    {
        $result = $this->initVerification($user, $realname, $idcard, $certType);
        $qrcode = $this->generateQrCode($result['certify_id']);

        return array_merge($result, $qrcode);
    }

    public function generateQrCode(string $certifyId): array
    {
        $response = $this->generateScanForm($certifyId);
        $this->assertSourceResponseSuccess($response, '获取认证链接失败');

        $remoteUrl = trim($response->url);
        if (! $this->isValidRemoteUrl($remoteUrl)) {
            throw new BusinessException('生成认证链接失败', 42200);
        }

        $expiresAt = now()->addSeconds(self::QR_CODE_URL_CACHE_TTL_SECONDS);
        $proxyUrl = $this->buildQrCodeProxyUrl($certifyId);
        $this->cacheQrCodeUrl($certifyId, $remoteUrl, $expiresAt);

        return [
            'url' => $proxyUrl,
            'qrcode_url' => $proxyUrl,
            'expires_at' => $expiresAt->toIso8601String(),
            'expires_in_seconds' => self::QR_CODE_URL_CACHE_TTL_SECONDS,
        ];
    }

    public function resolveQrCodeRedirectUrl(string $certifyId): string
    {
        $certifyId = trim($certifyId);
        if ($certifyId === '') {
            throw new BusinessException('认证会话不存在或已失效', 42200);
        }

        $cachedUrl = Cache::get($this->buildQrCodeUrlCacheKey($certifyId));
        if (is_string($cachedUrl) && $this->isValidRemoteUrl($cachedUrl)) {
            return $cachedUrl;
        }

        $this->forgetQrCodeUrlCache($certifyId);

        throw new BusinessException('认证二维码已失效，请重新生成', 42200);
    }

    public function closeQrCodeSession(string $certifyId): void
    {
        $certifyId = trim($certifyId);
        if ($certifyId === '') {
            return;
        }

        $this->forgetQrCodeUrlCache($certifyId);
    }

    public function queryStatus(string $certifyId): array
    {
        $maxRetries = 3;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $result = $this->getAliyunAuthStatus($certifyId);

            if ($result->status !== self::RESULT_STATUS_NETWORK_ERROR) {
                return $result->toArray();
            }

            if ($attempt < $maxRetries - 1) {
                usleep(200_000);
            }
        }

        // 重试耗尽仍网络失败：返回网络错误状态（3），syncUserStatus 会保留原认证状态，
        // 避免把上游暂时不可用误判为“认证失败”并写库。
        return [
            'status' => self::RESULT_STATUS_NETWORK_ERROR,
            'msg' => '网络请求失败，请刷新页面重试',
        ];
    }

    public function syncUserStatus(User $user, array $result, ?string $certifyId = null): User
    {
        $verification = $this->getVerificationSnapshot($user);
        $updatedUser = $user;

        DB::transaction(function () use ($user, $result, $certifyId, $verification, &$updatedUser): void {
            $payload = [
                'verification_message' => (string) ($result['msg'] ?? ''),
            ];

            if ($certifyId) {
                $payload['certify_id'] = $certifyId;
            }

            if (($result['status'] ?? null) === self::RESULT_STATUS_SUCCESS) {
                $payload['verification_status'] = 2;
                $payload['verification_message'] = (string) ($result['msg'] ?? '审核通过');
                $payload['verified_at'] = now();
            } elseif (($result['status'] ?? null) === self::RESULT_STATUS_NETWORK_ERROR) {
                $payload['verification_status'] = $verification['verification_status'] > 0
                    ? $verification['verification_status']
                    : self::RESULT_STATUS_PENDING;
                // 网络错误保留原认证状态与消息：不得把 pending 用户的文案覆盖为"网络请求失败"。
                $payload['verification_message'] = (string) ($verification['verification_message'] ?? '');
            } elseif (($result['status'] ?? null) === self::RESULT_STATUS_PENDING) {
                $payload['verification_status'] = self::RESULT_STATUS_PENDING;
            } else {
                $payload['verification_status'] = 3;
                $payload['verified_at'] = null;
            }

            if (! $this->verificationPayloadDiffersFromSnapshot($verification, $payload)) {
                // 轮询期间状态与消息均未变化：跳过写库，避免每 1 秒一次的无意义 UPDATE 放大。
                return;
            }

            $updatedUser = $this->persistVerificationState($user, $payload);
            $this->syncHistoryEntry($updatedUser, $certifyId);
        });

        return $updatedUser->fresh() ?? $updatedUser;
    }

    public function restartVerificationSession(User $user): array
    {
        $verification = $this->getVerificationSnapshot($user);
        $realName = $verification['real_name'];
        $idCard = $verification['id_card'];

        if ($realName === '' || $idCard === '') {
            throw new BusinessException('缺少实名认证信息，无法重新生成会话，请重新提交认证资料', 42200);
        }

        $result = $this->initVerification($user, $realName, $idCard);
        $qrcode = $this->generateQrCode($result['certify_id']);

        return array_merge($result, $qrcode);
    }

    public function getConfigSummary(): array
    {
        $config = $this->activeVerificationPluginConfig();
        $api = (string) ($config['api'] ?? '');
        $key = (string) ($config['key'] ?? '');
        $feeConfig = $this->safeFeeConfig();
        $context = $this->driverBindingResolver()->verificationContext();

        return [
            'verification_api_masked' => $this->maskConfigValue($api),
            'verification_biz_code' => $this->resolvedBizCode(),
            'configured' => trim($api) !== '' && trim($key) !== '',
            'driver_key' => $context['driver_key'],
            'plugin_id' => $context['plugin_id'],
            'free_attempts' => $feeConfig['free_attempts'],
            'retry_fee' => $feeConfig['retry_fee'],
            'charge_enabled' => $feeConfig['charge_enabled'],
            'amount' => $feeConfig['amount'],
        ];
    }

    public function feeConfig(): array
    {
        $driver = $this->driver();

        if ($driver instanceof ProvidesVerificationFeeConfig) {
            return $driver->feeConfig()->toArray();
        }

        return [
            'free_attempts' => 0,
            'retry_fee' => 0.0,
            'charge_enabled' => false,
            'amount' => 0.0,
        ];
    }

    public function unbind(User $user, ?int $adminUserId = null, ?string $adminName = null, ?string $rejectReason = null): array
    {
        $verification = $this->getVerificationSnapshot($user);
        $realName = $verification['real_name'];
        $idCard = $verification['id_card'];
        $certifyId = $verification['certify_id'];
        $resolvedRejectReason = trim((string) $rejectReason);
        $rejectMessage = $resolvedRejectReason !== '' ? $resolvedRejectReason : '管理员驳回';

        if ($verification['verification_status'] !== 2) {
            throw new BusinessException('该用户未通过实名认证，无法解绑', 42200);
        }

        DB::transaction(function () use ($user, $realName, $idCard, $certifyId, $rejectMessage): void {
            $this->persistVerificationState($user, [
                'verification_status' => self::RESULT_STATUS_UNBOUND,
                'verification_message' => $rejectMessage,
                'certify_id' => null,
                'verified_at' => null,
            ]);

            if (! $this->canPersistVerificationHistory()) {
                return;
            }

            try {
                VerificationHistory::create([
                    'user_id' => $user->id,
                    'real_name' => $realName,
                    'id_card' => $idCard,
                    'verification_status' => self::RESULT_STATUS_UNBOUND,
                    'verification_message' => $rejectMessage,
                    'verification_certify_id' => $certifyId,
                    'verification_biz_code' => $this->resolvedBizCode(),
                    'verification_type' => self::VERIFICATION_TYPE_PERSONAL,
                    'submitted_at' => now(),
                    'completed_at' => now(),
                ]);
            } catch (\Throwable $exception) {
                $this->handleHistoryPersistenceFailure('unbind', $exception, [
                    'user_id' => $user->id,
                    'verification_certify_id' => $certifyId,
                ]);
            }
        });

        return [
            'user_id' => $user->id,
            'real_name' => $this->maskName($realName),
            'unbound_at' => now()->format('Y-m-d H:i:s'),
            'operator' => $adminName ?? '系统',
            'reject_reason' => $rejectMessage,
        ];
    }

    /**
     * 管理端人工维护用户的实名状态与实名信息。
     *
     * 规则：
     * - real_name / id_card 仅做“覆盖为提交值”，留空表示沿用现有值，不支持主动清空；
     * - 状态置为 2（已认证）时要求最终姓名与证件号均非空；
     * - 状态未变化时不会用“审核通过/认证失败”等默认文案覆盖现有说明；
     * - 修改了姓名/证件号，或状态从“已认证”降级时，作废旧的平台认证 certify_id。
     *
     * @param  array{verification_status?: int, real_name?: string, id_card?: string, verification_message?: string}  $payload
     * @return array<string, mixed>
     */
    public function updateVerificationByAdmin(User $user, array $payload, ?int $adminUserId = null, ?string $adminName = null): array
    {
        $snapshot = $this->getVerificationSnapshot($user);
        $currentStatus = (int) $snapshot['verification_status'];

        $statusProvided = array_key_exists('verification_status', $payload);
        $status = $statusProvided ? (int) $payload['verification_status'] : $currentStatus;
        if ($statusProvided && ! in_array($status, [0, 2, 3, 5], true)) {
            throw new BusinessException('不支持的实名认证状态', 42200);
        }

        $realNameInput = trim((string) ($payload['real_name'] ?? ''));
        $idCardInput = trim((string) ($payload['id_card'] ?? ''));
        $messageProvided = array_key_exists('verification_message', $payload);
        $messageInput = trim((string) ($payload['verification_message'] ?? ''));

        $resolvedRealName = $realNameInput !== '' ? $realNameInput : $snapshot['real_name'];
        $resolvedIdCard = $idCardInput !== '' ? $idCardInput : $snapshot['id_card'];
        if ($status === 2 && ($resolvedRealName === '' || $resolvedIdCard === '')) {
            throw new BusinessException('将该用户标记为已认证需要已有或本次填写完整真实姓名与证件号码', 42200);
        }

        $userPayload = [];
        if ($realNameInput !== '' && $realNameInput !== $snapshot['real_name']) {
            $userPayload['real_name'] = $realNameInput;
        }
        if ($idCardInput !== '' && $idCardInput !== $snapshot['id_card']) {
            $userPayload['id_card'] = $idCardInput;
        }
        if ($statusProvided && $status !== $currentStatus) {
            $userPayload['verification_status'] = $status;
            $userPayload['verified_at'] = $status === 2 ? now() : null;
        }

        // 资料被改或状态不再处于“已认证”时，旧的平台认证会话与快照即失配，一律作废。
        // 注意 persistVerificationState 以 certify_id 作为入参 key。
        $profileChanged = array_key_exists('real_name', $userPayload) || array_key_exists('id_card', $userPayload);
        if ($profileChanged || ($statusProvided && $status !== 2)) {
            $userPayload['certify_id'] = null;
        }

        // 状态迁移未显式填说明时套用默认文案；其余情况仅当显式提交说明才允许改写。
        $resolvedMessage = null;
        if ($statusProvided && $status !== $currentStatus) {
            $resolvedMessage = $messageInput !== '' ? $messageInput : match ($status) {
                2 => '审核通过（管理员人工认证）',
                3 => '认证失败（管理员操作）',
                5 => '管理员驳回',
                default => '',
            };
        } elseif ($messageProvided) {
            $resolvedMessage = $messageInput;
        }
        if ($resolvedMessage !== null && $resolvedMessage !== $snapshot['verification_message']) {
            $userPayload['verification_message'] = $resolvedMessage;
        }

        if ($userPayload === []) {
            throw new BusinessException('没有需要变更的实名认证信息', 42200);
        }

        $updatedUser = DB::transaction(function () use ($user, $userPayload, $resolvedRealName, $resolvedIdCard): User {
            $updated = $this->persistVerificationState($user, $userPayload);

            if ($this->canPersistVerificationHistory()) {
                try {
                    VerificationHistory::create([
                        'user_id' => $updated->id,
                        'real_name' => $resolvedRealName,
                        'id_card' => $resolvedIdCard,
                        'verification_status' => (int) $updated->verification_status,
                        'verification_message' => (string) $updated->verification_message,
                        'verification_certify_id' => $updated->verification_certify_id,
                        'verification_biz_code' => $this->resolvedBizCode(),
                        'verification_type' => self::VERIFICATION_TYPE_PERSONAL,
                        'submitted_at' => now(),
                        'completed_at' => now(),
                    ]);
                } catch (\Throwable $exception) {
                    $this->handleHistoryPersistenceFailure('update-by-admin', $exception, ['user_id' => $updated->id]);
                }
            }

            return $updated;
        });

        $finalStatus = (int) $updatedUser->verification_status;
        $finalMessage = trim((string) $updatedUser->verification_message);

        return [
            'user_id' => $user->id,
            'verification_status' => $finalStatus,
            'verification_status_label' => $this->verificationStatusLabel($finalStatus),
            'real_name' => $this->maskName(trim((string) $updatedUser->real_name)),
            'verification_message' => $finalMessage,
            'operator' => $adminName ?? '系统',
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    private function verificationStatusLabel(int $status): string
    {
        return match ($status) {
            0 => '未认证',
            1, 4 => '待认证',
            2 => '已认证',
            3 => '认证失败',
            5 => '已驳回',
            default => '未知',
        };
    }

    public function findUserByCertifyId(string $certifyId): ?User
    {
        $certifyId = trim($certifyId);
        if ($certifyId === '') {
            return null;
        }

        $user = User::query()
            ->where('verification_certify_id', $certifyId)
            ->first();

        if ($user) {
            return $user;
        }

        return null;
    }

    private function getCertifyId(string $realname, string $idcard, string $certType): VerificationInitializeResult
    {
        $returnUrl = $this->resolveCallbackUrl();

        return $this->driver()->initialize(new VerificationInitializeRequest($realname, $idcard, $certType, $returnUrl));
    }

    private function generateScanForm(string $certifyId): VerificationScanUrlResult
    {
        return $this->driver()->generateScanUrl($certifyId);
    }

    private function getAliyunAuthStatus(string $certifyId): VerificationStatusResult
    {
        return $this->driver()->queryStatus($certifyId);
    }

    private function driver(): VerificationDriver
    {
        return $this->driverManager->resolve();
    }

    private function assertSourceResponseSuccess(
        VerificationInitializeResult|VerificationScanUrlResult $response,
        string $fallbackMessage,
    ): void {
        $status = $response->status;
        if ($status === self::API_STATUS_SUCCESS) {
            return;
        }

        $message = trim($response->message) ?: $fallbackMessage;
        $errorCode = $status === self::API_STATUS_NETWORK_ERROR ? 50000 : 42200;

        throw new BusinessException($message, $errorCode);
    }

    private function createHistoryEntry(User $user, string $certifyId): void
    {
        if (! $this->canPersistVerificationHistory()) {
            return;
        }

        try {
            VerificationHistory::create([
                'user_id' => $user->id,
                'real_name' => (string) $user->real_name,
                'id_card' => (string) $user->id_card,
                'verification_status' => self::RESULT_STATUS_PENDING,
                'verification_message' => (string) $user->verification_message,
                'verification_certify_id' => $certifyId,
                'verification_biz_code' => $this->resolvedBizCode(),
                'verification_type' => self::VERIFICATION_TYPE_PERSONAL,
                'submitted_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $this->handleHistoryPersistenceFailure('create', $exception, [
                'user_id' => $user->id,
                'verification_certify_id' => $certifyId,
            ]);
        }
    }

    private function syncHistoryEntry(User $user, ?string $certifyId = null): void
    {
        if (! $this->canPersistVerificationHistory()) {
            return;
        }

        try {
            $query = VerificationHistory::query()
                ->where('user_id', $user->id);

            if ($certifyId) {
                $query->where('verification_certify_id', $certifyId);
            }

            $history = $query
                ->orderByDesc('submitted_at')
                ->orderByDesc('id')
                ->first();

            if (! $history) {
                $history = VerificationHistory::create([
                    'user_id' => $user->id,
                    'real_name' => (string) $user->real_name,
                    'id_card' => (string) $user->id_card,
                    'verification_status' => (int) $user->verification_status,
                    'verification_message' => (string) $user->verification_message,
                    'verification_certify_id' => $certifyId ?: $user->verification_certify_id,
                    'verification_biz_code' => $this->resolvedBizCode(),
                    'verification_type' => self::VERIFICATION_TYPE_PERSONAL,
                    'submitted_at' => now(),
                ]);
            }

            $status = (int) $user->verification_status;

            $history->forceFill([
                'real_name' => (string) $user->real_name,
                'id_card' => (string) $user->id_card,
                'verification_status' => $status,
                'verification_message' => (string) $user->verification_message,
                'verification_certify_id' => $certifyId ?: $history->verification_certify_id,
                'verification_biz_code' => $this->resolvedBizCode(),
                'verification_type' => self::VERIFICATION_TYPE_PERSONAL,
                'completed_at' => in_array($status, [2, 3, self::RESULT_STATUS_UNBOUND], true) ? ($user->verified_at ?? now()) : null,
            ])->save();
        } catch (\Throwable $exception) {
            $this->handleHistoryPersistenceFailure('sync', $exception, [
                'user_id' => $user->id,
                'verification_certify_id' => $certifyId ?: (string) $user->verification_certify_id,
            ]);
        }
    }

    private function canPersistVerificationHistory(): bool
    {
        if ($this->verificationHistoryTableAvailable !== null) {
            return $this->verificationHistoryTableAvailable;
        }

        try {
            return $this->verificationHistoryTableAvailable = Schema::hasTable('verification_histories');
        } catch (\Throwable $exception) {
            $this->verificationHistoryTableAvailable = false;

            Log::warning('[实名认证] verificationHistory-表检查失败', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function handleHistoryPersistenceFailure(string $action, \Throwable $exception, array $context = []): void
    {
        if (str_contains(strtolower($exception->getMessage()), 'verification_histories')) {
            $this->verificationHistoryTableAvailable = false;
        }

        Log::warning('[实名认证] verificationHistory-'.$action.'-失败', SensitiveDataSanitizer::sanitize(array_merge($context, [
            'error' => $exception->getMessage(),
        ])));
    }

    private function resolveCallbackUrl(): string
    {
        return PublicUrl::api('/api/v2/client/verification/callback');
    }

    private function cacheQrCodeUrl(string $certifyId, string $remoteUrl, ?\DateTimeInterface $expiresAt = null): void
    {
        Cache::put(
            $this->buildQrCodeUrlCacheKey($certifyId),
            $remoteUrl,
            $expiresAt ?? now()->addSeconds(self::QR_CODE_URL_CACHE_TTL_SECONDS)
        );
    }

    private function forgetQrCodeUrlCache(string $certifyId): void
    {
        Cache::forget($this->buildQrCodeUrlCacheKey($certifyId));
    }

    private function buildQrCodeProxyUrl(string $certifyId): string
    {
        return PublicUrl::api('/api/v2/client/verification/scan?certify_id='.rawurlencode($certifyId));
    }

    private function resolvedBizCode(): string
    {
        $bizCode = trim((string) ($this->activeVerificationPluginConfig()['biz_code'] ?? ''));

        return $bizCode !== '' ? $bizCode : 'FACE';
    }

    /**
     * @return array<string, mixed>
     */
    private function activeVerificationPluginConfig(): array
    {
        if ($this->verificationPluginConfigCache !== null) {
            return $this->verificationPluginConfigCache;
        }

        $plugin = $this->activeVerificationPlugin();
        if (! $plugin instanceof IntegrationPlugin) {
            return $this->verificationPluginConfigCache = [];
        }

        return $this->verificationPluginConfigCache = $this->pluginConfigRepository()->resolvedConfig($plugin);
    }

    private function activeVerificationPlugin(): ?IntegrationPlugin
    {
        if (! Schema::hasTable('integration_plugins')) {
            return null;
        }

        $context = $this->driverBindingResolver()->verificationContext();
        $pluginId = (int) ($context['plugin_id'] ?? 0);
        if ($pluginId > 0) {
            $plugin = IntegrationPlugin::query()->whereKey($pluginId)->first();
            if ($plugin instanceof IntegrationPlugin) {
                return $plugin;
            }
        }

        $driverKey = trim((string) ($context['driver_key'] ?? ''));
        if ($driverKey === '') {
            return null;
        }

        return IntegrationPlugin::query()
            ->where('domain', PluginDomain::VERIFICATION)
            ->where('status', IntegrationPlugin::STATUS_ENABLED)
            ->where(static function ($query) use ($driverKey): void {
                $query->where('plugin_key', $driverKey)
                    ->orWhere('slug', $driverKey);
            })
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{free_attempts: int, retry_fee: float, charge_enabled: bool, amount: float}
     */
    private function safeFeeConfig(): array
    {
        try {
            return $this->feeConfig();
        } catch (\Throwable) {
            return [
                'free_attempts' => 0,
                'retry_fee' => 0.0,
                'charge_enabled' => false,
                'amount' => 0.0,
            ];
        }
    }

    /**
     * 实名收费消费点：免费次数内免费，超出后每次认证从账户余额扣取认证费用。
     * 需在实名状态持久化同一事务内调用，余额不足抛出业务异常回滚本次认证发起。
     */
    private function chargeVerificationIfNeeded(User $user): void
    {
        $feeConfig = $this->safeFeeConfig();
        if (! (bool) ($feeConfig['charge_enabled'] ?? false)) {
            return;
        }

        $freeAttempts = max((int) ($feeConfig['free_attempts'] ?? 0), 0);
        $feeAmount = round(max((float) ($feeConfig['retry_fee'] ?? $feeConfig['amount'] ?? 0), 0), 2);
        if ($feeAmount <= 0) {
            return;
        }

        // 已提交次数（含成功/失败/待认证）在免费次数内则免费。
        if ($this->countSubmittedVerifications($user) < $freeAttempts) {
            return;
        }

        $accounts = $this->accounts();
        $currentBalance = $accounts->cashBalance($user, true);
        throw_if(
            $currentBalance < $feeAmount,
            new BusinessException('实名认证需要支付认证费用，当前余额不足', 42200)
        );

        $balanceAfter = $accounts->setCashBalance($user, $currentBalance - $feeAmount, true);

        AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => FinanceLedgerEventType::VERIFICATION_FEE,
            'change_amount' => number_format(-$feeAmount, 2, '.', ''),
            'balance_after' => $balanceAfter,
            'source_type' => 'verification',
            'origin_type' => 'verification',
            'remark' => '实名认证费用',
            // 系统按配置自动扣费，标记来源便于对账；认证发起链路暂不携带业务 trace。
            'operator' => 'system',
        ]);
    }

    /**
     * 统计用户历史提交实名认证的次数（VerificationHistory 逐次记录）。
     * 历史表不可用时回退为按用户实名快照估算，避免收费误判。
     */
    private function countSubmittedVerifications(User $user): int
    {
        if (! $this->canPersistVerificationHistory()) {
            return (int) $user->verification_status > 0 ? 1 : 0;
        }

        return (int) VerificationHistory::query()
            ->where('user_id', (int) $user->id)
            ->count();
    }

    private function accounts(): AccountService
    {
        return $this->accountService ??= app(AccountService::class);
    }

    private function pluginConfigRepository(): PluginConfigRepository
    {
        return $this->pluginConfigRepository ??= app(PluginConfigRepository::class);
    }

    private function driverBindingResolver(): IntegrationDriverBindingResolver
    {
        return $this->driverBindingResolver ??= app(IntegrationDriverBindingResolver::class);
    }

    private function buildQrCodeUrlCacheKey(string $certifyId): string
    {
        return self::QR_CODE_URL_CACHE_PREFIX.md5($certifyId);
    }

    private function isValidRemoteUrl(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }

    private function maskConfigValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $length = mb_strlen($value);
        if ($length <= 8) {
            return '已配置';
        }

        return mb_substr($value, 0, 4).'******'.mb_substr($value, -4);
    }

    private function maskName(string $name): string
    {
        $len = mb_strlen($name);
        if ($len <= 1) {
            return '*';
        }

        if ($len === 2) {
            return mb_substr($name, 0, 1).'*';
        }

        return mb_substr($name, 0, 1).str_repeat('*', $len - 2).mb_substr($name, -1);
    }

    private function maskIdCard(string $idcard): string
    {
        $len = strlen($idcard);
        if ($len < 8) {
            return str_repeat('*', $len);
        }

        return substr($idcard, 0, 4).str_repeat('*', $len - 8).substr($idcard, -4);
    }

    /**
     * 判断待写库的实名状态字段是否与当前快照一致；一致时跳过写库，抑制状态轮询写放大。
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $payload
     */
    private function verificationPayloadDiffersFromSnapshot(array $snapshot, array $payload): bool
    {
        if (array_key_exists('verification_status', $payload)
            && (int) ($snapshot['verification_status'] ?? 0) !== (int) $payload['verification_status']) {
            return true;
        }

        if (array_key_exists('verification_message', $payload)
            && trim((string) ($snapshot['verification_message'] ?? '')) !== trim((string) $payload['verification_message'])) {
            return true;
        }

        if (array_key_exists('certify_id', $payload)
            && trim((string) ($snapshot['certify_id'] ?? '')) !== trim((string) $payload['certify_id'])) {
            return true;
        }

        // verified_at 只关注“是否已设置”：成功态必然已设置，失败/解绑态必然清空。
        // 状态未变化时时间精度差异不应触发写库，避免成功态重复同步反复 UPDATE。
        if (array_key_exists('verified_at', $payload)
            && ! $this->sameVerifiedAtPresence($snapshot['verified_at'] ?? null, $payload['verified_at'])) {
            return true;
        }

        return false;
    }

    private function sameVerifiedAtPresence(mixed $left, mixed $right): bool
    {
        return $this->isBlankDateTime($left) === $this->isBlankDateTime($right);
    }

    private function isBlankDateTime(mixed $value): bool
    {
        return $value === null || $value === '' || $value === '0000-00-00 00:00:00';
    }

    private function getVerificationSnapshot(User $user): array
    {
        $freshUser = $user->exists ? ($user->fresh() ?? $user) : $user;

        $realName = trim((string) $freshUser->real_name);
        $idCard = trim((string) $freshUser->id_card);
        $certifyId = trim((string) ($freshUser->verification_certify_id ?? ''));
        $verificationStatus = (int) $freshUser->verification_status;
        if ($verificationStatus === 0 && (int) $freshUser->is_verified === 1) {
            $verificationStatus = 2;
        }

        $verificationMessage = trim((string) $freshUser->verification_message);
        $verifiedAt = $freshUser->verified_at;

        return [
            'real_name' => $realName,
            'id_card' => $idCard,
            'certify_id' => $certifyId,
            'verification_status' => $verificationStatus,
            'verification_message' => $verificationMessage,
            'verified_at' => $verifiedAt,
        ];
    }

    private function persistVerificationState(User $user, array $payload): User
    {
        $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
        $userPayload = [];

        if (array_key_exists('verification_status', $payload)) {
            $status = (int) $payload['verification_status'];
            $userPayload['verification_status'] = $status;
            $userPayload['is_verified'] = $status === 2 ? 1 : 0;
        }

        if (array_key_exists('real_name', $payload)) {
            $userPayload['real_name'] = $this->nullableString($payload['real_name']);
        }

        if (array_key_exists('id_card', $payload)) {
            // User 的 cast 统一负责加密；这里预加密会在模型保存时再次加密。
            $userPayload['id_card'] = $payload['id_card'];
        }

        if (array_key_exists('certify_id', $payload)) {
            $userPayload['verification_certify_id'] = $this->nullableString($payload['certify_id']);
        }

        if (array_key_exists('verification_message', $payload)) {
            $userPayload['verification_message'] = (string) ($payload['verification_message'] ?? '');
        }

        if (array_key_exists('verified_at', $payload)) {
            $userPayload['verified_at'] = $payload['verified_at'];
        }

        if ($userPayload !== []) {
            $lockedUser->forceFill($userPayload)->save();
        }

        return $lockedUser->fresh() ?? $lockedUser;
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
