<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Services;

use TuraIDC\Plugins\Addons\AiAssistant\Models\AiToolInvocation;

/**
 * 写操作确认令牌。
 *
 * 令牌是确定性的 HMAC（调用编号 + 用户 + 参数指纹），因此：
 * - 数据库只存 sha256 哈希，明文不落地；
 * - 历史待确认动作可以随时重新签发同一个令牌，前端刷新后卡片仍可点；
 * - 参数被改动会直接导致指纹变化，旧令牌立刻失效。
 */
final class AiConfirmToken
{
    public function issue(AiToolInvocation $invocation): string
    {
        return hash_hmac('sha256', $this->payload($invocation), $this->key());
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function verify(AiToolInvocation $invocation, string $token): bool
    {
        if ($token === '') {
            return false;
        }

        $expected = $this->issue($invocation);
        if (! hash_equals($expected, $token)) {
            return false;
        }

        $storedHash = (string) ($invocation->confirm_token_hash ?? '');
        if ($storedHash === '') {
            return true;
        }

        return hash_equals($storedHash, $this->hash($token));
    }

    private function payload(AiToolInvocation $invocation): string
    {
        $arguments = $invocation->arguments;
        $fingerprint = is_array($arguments)
            ? sha1((string) json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR))
            : sha1('');

        return implode('|', [
            'ai-action',
            (string) $invocation->id,
            (string) $invocation->user_id,
            (string) $invocation->tool_name,
            $fingerprint,
        ]);
    }

    /** 密钥缺省即拒绝：绝不允许退化成空密钥签名 */
    private function key(): string
    {
        $key = (string) config('app.key', '');
        if ($key === '') {
            throw new \App\Exceptions\BusinessException('系统密钥未配置，无法签发确认令牌', 50000, 500);
        }

        return $key;
    }
}
