<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Exceptions\BusinessException;
use Illuminate\Support\Str;

/**
 * 魔方财务同步推送的签名算法（上下游共用一套）。
 *
 * 3.7.6 及更早只有 legacy：签名 = upper(md5(json(ksort([id, token, rand_str]))))。
 * 3.8.0 起新增 v2：签名 = upper(hmac_sha256(规范化载荷, token))，规范化载荷 = 全部字段
 * （去掉 signature 与 token）递归按键名排序、null 归一成空串、标量统一转字符串后
 * 用 JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES 编码；另带 timestamp（±300 秒）
 * 与 32 位 hex nonce 防重放。默认 v2，legacy 只在被下游/上游明确拒绝时兜底。
 *
 * 两侧都用这一个类，避免「我们发 v2、我们收 legacy」这种自打自脸的口径漂移。
 */
final class ZjmfSyncSignature
{
    public const VERSION_V2 = '2';

    /** 3.8.0 校验的时间戳容差（秒） */
    public const TIMESTAMP_TOLERANCE = 300;

    /** 下游/上游明确拒绝签名时的提示语（3.8.0 原文 + 英文） */
    private const REJECTION_MESSAGES = [
        '签名验证失败',
        '签名校验失败',
        '签名错误',
        'signature verification failed',
    ];

    /**
     * 规范化载荷：递归处理数组、按键名排序、null→空串、标量转字符串。
     *
     * @param  array<string, mixed>  $params
     */
    public static function canonicalPayload(array $params): string
    {
        unset($params['signature'], $params['token']);

        $normalized = self::normalize($params);

        return (string) json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalize($item);
            }
            ksort($normalized, SORT_STRING);

            return $normalized;
        }

        return $value === null ? '' : (string) $value;
    }

    /**
     * 生成 v2 签名及其附加字段（不含 token：接收方用自己库里的 token 复算）。
     *
     * @param  array<string, mixed>  $params  业务载荷（不含 sign 相关字段）
     * @return array{signature: string, signature_version: string, timestamp: int, nonce: string, rand_str: string}
     */
    public static function signV2(array $params, string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new BusinessException('回推凭据未配置，无法签名');
        }

        $meta = [
            'signature_version' => self::VERSION_V2,
            'timestamp' => time(),
            'nonce' => bin2hex(random_bytes(16)),
            'rand_str' => Str::lower(Str::random(6)),
        ];

        return array_merge($meta, [
            'signature' => strtoupper(hash_hmac(
                'sha256',
                self::canonicalPayload(array_merge($params, $meta)),
                $token
            )),
        ]);
    }

    /**
     * 生成 legacy 签名（3.7.6 及更早，只有 id/token/rand_str 三个字段参与）。
     *
     * @param  array<string, mixed>  $params
     * @return array{signature: string, rand_str: string}
     */
    public static function signLegacy(array $params, string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new BusinessException('回推凭据未配置，无法签名');
        }

        $randStr = Str::lower(Str::random(6));
        $signed = ['id' => $params['id'] ?? 0, 'token' => $token, 'rand_str' => $randStr];
        ksort($signed, SORT_STRING);

        return [
            'signature' => strtoupper(md5((string) json_encode($signed))),
            'rand_str' => $randStr,
        ];
    }

    /**
     * 校验 v2 签名。timestamp / nonce 的基本合法性也在这里挡掉。
     *
     * @param  array<string, mixed>  $params  接收到的完整参数（含 signature 与 nonce 等）
     */
    public static function verifyV2(array $params, string $token): bool
    {
        $token = trim($token);
        $signature = strtoupper(trim((string) ($params['signature'] ?? '')));
        if ($token === '' || $signature === '') {
            return false;
        }

        $timestamp = (int) ($params['timestamp'] ?? 0);
        $nonce = strtolower(trim((string) ($params['nonce'] ?? '')));
        if ($timestamp <= 0 || abs(time() - $timestamp) > self::TIMESTAMP_TOLERANCE) {
            return false;
        }

        if (preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1) {
            return false;
        }

        $expected = strtoupper(hash_hmac('sha256', self::canonicalPayload($params), $token));

        return hash_equals($expected, $signature);
    }

    /**
     * 请求方声明的是否为 v2。
     *
     * @param  array<string, mixed>  $params
     */
    public static function isV2(array $params): bool
    {
        return (string) ($params['signature_version'] ?? '') === self::VERSION_V2;
    }

    /**
     * 下游/上游的响应是否在抱怨签名（用于 v2 → legacy 的自动兜底）。
     *
     * @param  array<string, mixed>  $body
     */
    public static function isSignatureRejected(array $body): bool
    {
        if ((int) ($body['status'] ?? 0) !== 400) {
            return false;
        }

        $message = strtolower(trim((string) ($body['msg'] ?? $body['message'] ?? '')));
        foreach (self::REJECTION_MESSAGES as $needle) {
            if ($message !== '' && str_contains($message, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
