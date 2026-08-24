<?php

declare(strict_types=1);

namespace App\Services\OpenApi;

use App\Exceptions\BusinessException;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Support\Str;

class ApiKeyService
{
    /** 业务域 => 支持级别（write 蕴含 read） */
    public const SCOPE_DOMAINS = ['products', 'orders', 'services', 'finance'];

    public function __construct(
        private readonly OpenApiConfig $config,
    ) {}

    /** 生成完整密钥（明文仅此一次），返回 [model, plainSecret] */
    public function createForUser(
        User $user,
        string $name,
        array $scopes,
        ?string $expiresAt = null,
        array $ipAllowlist = [],
    ): array {
        $this->assertCanCreate($user);

        $plain = Str::random(32);
        $prefix = ApiKey::KEY_PREFIX.Str::lower(Str::random(8));

        $key = ApiKey::query()->create([
            'user_id' => (int) $user->id,
            'name' => $name,
            'key_prefix' => $prefix,
            'secret_hash' => $this->hashSecret($plain),
            'secret_last4' => Str::substr($plain, -4),
            'scopes' => $this->normalizeScopes($scopes),
            'expires_at' => $expiresAt ?: null,
            'ip_allowlist' => $ipAllowlist !== [] ? array_values($ipAllowlist) : null,
            'status' => ApiKey::STATUS_ENABLED,
        ]);

        return [$key, $plain];
    }

    public function assertCanCreate(User $user): void
    {
        if (! $this->config->enabled()) {
            throw new BusinessException('系统未开放 API 密钥功能', 40300, 403);
        }
        if ($this->config->requirePhone() && trim((string) $user->phone) === '') {
            throw new BusinessException('请先绑定手机号后再创建 API 密钥', 40300, 403);
        }
        if ($this->config->requireVerified() && ! $user->hasCompletedVerification()) {
            throw new BusinessException('请先完成实名认证后再创建 API 密钥', 40300, 403);
        }

        $count = ApiKey::query()->where('user_id', (int) $user->id)->count();
        if ($count >= $this->config->maxKeysPerUser()) {
            throw new BusinessException('API 密钥数量已达上限', 42200, 422);
        }
    }

    /** 校验密钥：返回 ApiKey 或抛异常 */
    public function resolve(string $secret): ApiKey
    {
        if ($secret === '') {
            throw new BusinessException('缺少 API 密钥', 40100, 401);
        }

        $candidate = ApiKey::query()
            ->where('status', ApiKey::STATUS_ENABLED)
            ->get()
            ->first(fn (ApiKey $key) => hash_equals($key->secret_hash, $this->hashSecret($secret)));

        if (! $candidate) {
            throw new BusinessException('API 密钥无效或已被停用', 40100, 401);
        }

        if ($candidate->isExpired()) {
            throw new BusinessException('API 密钥已过期', 40100, 401);
        }

        return $candidate;
    }

    /** 校验 scope：write 蕴含 read */
    public function assertScope(ApiKey $key, string $domain, string $level): void
    {
        $scopes = is_array($key->scopes) ? $key->scopes : [];
        $granted = (string) ($scopes[$domain] ?? '');

        if ($granted === $level || ($level === 'read' && $granted === 'write')) {
            return;
        }
        throw new BusinessException("当前密钥缺少 {$domain}:{$level} 权限", 40300, 403);
    }

    /** 校验 IP 白名单 */
    public function assertIpAllowed(ApiKey $key, string $ip): void
    {
        $allowlist = is_array($key->ip_allowlist) ? array_values(array_filter($key->ip_allowlist)) : [];
        if ($allowlist === [] || in_array($ip, $allowlist, true)) {
            return;
        }
        throw new BusinessException('当前 IP 不在密钥白名单内', 40300, 403);
    }

    public function touchLastUsed(ApiKey $key): void
    {
        $key->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    public function normalizeScopes(array $scopes): array
    {
        $normalized = [];
        foreach (self::SCOPE_DOMAINS as $domain) {
            $value = (string) ($scopes[$domain] ?? '');
            if (in_array($value, ['read', 'write'], true)) {
                $normalized[$domain] = $value;
            }
        }

        return $normalized;
    }

    public function hashSecret(string $secret): string
    {
        return hash('sha256', $secret.config('app.key'));
    }

    public function update(ApiKey $key, array $payload): ApiKey
    {
        $key->fill([
            'name' => (string) ($payload['name'] ?? $key->name),
            'scopes' => $this->normalizeScopes((array) ($payload['scopes'] ?? $key->scopes ?? [])),
            'expires_at' => isset($payload['expires_at']) && $payload['expires_at'] !== '' && $payload['expires_at'] !== null
                ? $payload['expires_at']
                : null,
            'ip_allowlist' => isset($payload['ip_allowlist']) && is_array($payload['ip_allowlist']) && $payload['ip_allowlist'] !== []
                ? array_values($payload['ip_allowlist'])
                : null,
        ])->save();

        return $key;
    }
}
