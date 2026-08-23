<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * 上游附件上传限流：白名单 IP/CIDR 不限速，非白名单来源按配置速率限制（0 为不限速）。
 * 配置存于 settings 表 ticket_upstream 组，可在管理端「工单传递设置」页调整。
 */
final class TicketUpstreamUploadThrottle
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->uploadImageEnabled()) {
            // 接口未启用是配置态，属预期行为；/upload_image 为匿名公开路由，
            // 外部扫描器持续请求会放大日志量，故用 debug 级别记录，避免刷屏告警。
            Log::debug('上游工单附件上传接口未启用', [
                'reason' => 'upload_image_disabled',
                'ip' => $request->ip(),
            ]);

            return response()->json(['status' => 400, 'msg' => '上传接口未启用'], 200);
        }

        $ip = (string) $request->ip();
        if ($ip === '' || $ip === 'unknown') {
            return response()->json(['status' => 400, 'msg' => '上传失败'], 200);
        }

        if ($this->isWhitelisted($ip)) {
            return $next($request);
        }

        // 拒绝白名单外上传：仅允许配置的白名单 IP/CIDR 来源，其余一律拒绝
        if ($this->blockNonWhitelisted()) {
            Log::warning('上游附件上传被白名单拒绝', ['ip' => $ip, 'reason' => 'non_whitelisted_blocked']);

            return response()->json(['status' => 403, 'msg' => '上传失败'], 200);
        }

        $maxAttempts = $this->maxAttempts();
        if ($maxAttempts <= 0) {
            return $next($request);
        }

        $key = 'ticket-upstream-upload:'.$ip;
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            Log::warning('上游附件上传触发限流', ['ip' => $ip, 'reason' => 'rate_limit_exceeded']);

            return response()->json(['status' => 429, 'msg' => '上传过于频繁，请稍后再试'], 200);
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }

    private function uploadImageEnabled(): bool
    {
        $value = Setting::getValue(
            'ticket_upstream',
            'upload_image_enabled',
            config('ticket_upstream.upload_image_enabled', false)
        );

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private function isWhitelisted(string $ip): bool
    {
        foreach ($this->allowedIps() as $entry) {
            if ($this->ipInRange($ip, trim($entry))) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断 IP 是否落在条目（IPv4 或 IPv4/CIDR）范围内。
     * CIDR 前缀必须是纯数字（ctype_digit），否则 "1.2.3.4/foo" 会被 (int) 强转为 0，
     * 误匹配全部 IPv4 导致白名单保护失效；"1.2.3.4/0" 仍为合法输入（匹配所有地址）。
     */
    private function ipInRange(string $ip, string $entry): bool
    {
        if ($entry === '') {
            return false;
        }

        if (str_contains($entry, '/')) {
            [$subnet, $bits] = array_pad(explode('/', $entry, 2), 2, '');
            if (! ctype_digit($bits)) {
                return false;
            }
            $bits = (int) $bits;
            if ($bits < 0 || $bits > 32 || ! filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return false;
            }

            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            if ($ipLong === false || $subnetLong === false) {
                return false;
            }

            $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;

            return ($ipLong & $mask) === ($subnetLong & $mask);
        }

        return $ip === $entry;
    }

    /**
     * @return list<string>
     */
    private function allowedIps(): array
    {
        $raw = (string) Setting::getValue(
            'ticket_upstream',
            'allowed_ips',
            (string) config('ticket_upstream.upload_allowed_ips', '')
        );

        return array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            preg_split('/[\s,]+/', $raw) ?: []
        ), static fn (string $item): bool => $item !== ''));
    }

    private function maxAttempts(): int
    {
        $value = Setting::getValue(
            'ticket_upstream',
            'rate_limit',
            (string) config('ticket_upstream.upload_rate_limit', 30)
        );

        return max(0, (int) $value);
    }

    private function blockNonWhitelisted(): bool
    {
        // 与 TicketDeliveryController 读取同一配置时保持一致的布尔解析规则：
        // (bool) 强转会把字符串 'false' 判为 true，导致管理员关闭后白名单外上传仍被拒绝。
        $value = Setting::getValue(
            'ticket_upstream',
            'block_non_whitelisted',
            config('ticket_upstream.upload_block_non_whitelisted', true)
        );

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }
}
