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

        // 按 secret_hash 等值命中，而不是把全表 enabled 密钥载入内存逐行 hash_equals。
        // hashSecret() 是确定性的 sha256(secret + app.key)，同一明文恒映射到同一 hash，
        // 因此可直接用它做索引查找 —— 这正是 Laravel Sanctum 校验 token 的做法
        // （findToken 用 where('token', hash('sha256', $plain))）。
        // 时序安全：攻击者提交的是明文 secret，要让 secret_hash 命中必须先知道 app.key
        // 才能构造出目标 hash；数据库 B-tree 等值查找也不像逐字节 memcmp 那样按前缀短路。
        // 原实现的 O(n) 全表 + 每行实例化 ApiKey 模型，任意 Bearer 头即可触发，是 DoS 面。
        $candidate = ApiKey::query()
            ->where('secret_hash', $this->hashSecret($secret))
            ->where('status', ApiKey::STATUS_ENABLED)
            ->first();

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

    /**
     * 部分更新：只改动 $payload 里显式出现的字段，缺失的字段一律保留原值。
     *
     * 用 array_key_exists 而非 isset/??：原实现对 expires_at / ip_allowlist 用
     * `isset(...) ? ... : null`，于是只带 {"name":"x"} 的部分更新会把这两项静默清成
     * null（密钥变成永不过期 + 不限 IP）—— 等于悄悄拆掉两道安全控制。
     * 语义边界：字段缺失 = 保留；字段显式为 null / '' / [] = 采纳其清空意图。
     * （已实测 UpdateApiKeyRequest：未提交的 nullable 字段不会进 validated()，
     * 故 array_key_exists 能可靠区分「缺失」与「显式清空」。）
     */
    public function update(ApiKey $key, array $payload): ApiKey
    {
        if (array_key_exists('name', $payload)) {
            $key->name = (string) $payload['name'];
        }

        if (array_key_exists('scopes', $payload)) {
            $key->scopes = $this->normalizeScopes((array) $payload['scopes']);
        }

        if (array_key_exists('expires_at', $payload)) {
            $expiresAt = $payload['expires_at'];
            $key->expires_at = ($expiresAt === '' || $expiresAt === null) ? null : $expiresAt;
        }

        if (array_key_exists('ip_allowlist', $payload)) {
            $ipAllowlist = $payload['ip_allowlist'];
            $key->ip_allowlist = (is_array($ipAllowlist) && $ipAllowlist !== [])
                ? array_values($ipAllowlist)
                : null;
        }

        $key->save();

        return $key;
    }
}
