<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

/**
 * 上游（智简魔方财务）响应解析的单一入口。
 *
 * 为什么需要它：上游不同端点对「业务码」和「账单支付状态」用了同一个 `status` 键，
 * 层级还不一致。三个服务各自读一遍就会出现三份口径，本插件真实踩过的缺陷正是这么来的：
 *
 * - `/v1/invoices/{id}` 把账单支付状态放在 `data.invoices.status`（可能是对象，也可能是一行列表），
 *   而顶层 `data.status` 是业务码 `200`。按顶层读会恒得到 "200"，每张已付账单都被当成
 *   未付，重试时又去 `/fund` 补付，上游对已付账单回 400「Bill paid」，开通重试直接失败。
 * - `/check_order`、`/v1/invoices/{id}/fund` 的 `status` 才是业务码（1000/1001 表示已付）。
 *
 * 因此：**纯数字一律视为业务码，不是支付状态**；拿不到字符串状态时才回退业务码判定。
 */
final class ZjmfUpstreamResponse
{
    /**
     * 请求元信息里的 HTTP 状态键：挂在响应数组上，与业务码严格分开。
     *
     * 用 `get()`/`post()` 的裸响应体拼数据会得到 http_code=0，恒判失败；
     * 必须用 `requestWithMeta()` 的返回经 {@see self::withHttpCode()} 转换。
     */
    public const HTTP_CODE_META_KEY = '_zjmf_upstream_http_code';

    /** 账单已付的字符串状态 */
    private const PAID_STATUS = 'paid';

    /** 只有这两个业务码代表「账单支付已完成」 */
    private const PAYMENT_COMPLETED_CODES = [1000, 1001];

    /**
     * 把 `requestWithMeta()` 的返回转成「响应体 + HTTP 状态」。
     *
     * @param  array<string, mixed>  $meta  {response, headers, http_code, content_type}
     * @return array<string, mixed>
     */
    public static function withHttpCode(array $meta): array
    {
        $response = is_array($meta['response'] ?? null) ? $meta['response'] : [];
        $response[self::HTTP_CODE_META_KEY] = (int) ($meta['http_code'] ?? 0);

        return $response;
    }

    /**
     * 响应体里的 `data` 段；没有 data 时返回响应本身。
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    public static function payload(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : $response;
    }

    /**
     * 业务码：顶层 status/code/status_code 优先，其次 `data` 段里的同名键。
     *
     * 注意这只是**业务结果**，不是账单支付状态——账单状态请用 {@see self::invoiceStatus()}。
     *
     * @param  array<string, mixed>  $response
     */
    public static function status(array $response, int $default = 0): int
    {
        foreach ([$response, self::payload($response)] as $candidate) {
            foreach (['status', 'code', 'status_code'] as $key) {
                if (isset($candidate[$key]) && is_numeric($candidate[$key])) {
                    return (int) $candidate[$key];
                }
            }
        }

        return $default;
    }

    /**
     * 响应是否带了 HTTP 状态元信息。
     *
     * @param  array<string, mixed>  $response
     */
    public static function hasHttpCodeMeta(array $response): bool
    {
        return array_key_exists(self::HTTP_CODE_META_KEY, $response);
    }

    /**
     * HTTP 状态是否 2xx。没有元信息时返回 false（未知不放行）。
     *
     * @param  array<string, mixed>  $response
     */
    public static function hasSuccessfulHttpStatus(array $response): bool
    {
        $httpCode = (int) ($response[self::HTTP_CODE_META_KEY] ?? 0);

        return $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * 账单支付状态（小写字符串，如 paid/unpaid/refunded）。
     *
     * 取值顺序：`data.invoices.status` → `data.invoice.status` → 顶层 `status` → 响应顶层同名键。
     * `invoices`/`invoice` 既可能是对象也可能是列表（列表取第一条带状态的），两种都要兼容。
     * **纯数字一律视为缺失**：那是业务码 200/1001，把它当支付状态就是本次要修的那个缺陷。
     *
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>|null  $payload  已解析的 data 段（省一次判断，可为空）
     */
    public static function invoiceStatus(array $response, ?array $payload = null): string
    {
        $payload ??= self::payload($response);

        foreach ([
            self::nodeStatus($payload['invoices'] ?? null),
            self::nodeStatus($payload['invoice'] ?? null),
            self::nodeStatus($response['invoices'] ?? null),
            self::nodeStatus($response['invoice'] ?? null),
            self::normalizeStatus($payload['status'] ?? null),
            self::normalizeStatus($response['status'] ?? null),
        ] as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * 账单是否已付：字符串状态优先（unknown 时回退业务码 1000/1001）。
     *
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>|null  $payload
     */
    public static function isInvoicePaid(array $response, ?array $payload = null): bool
    {
        $status = self::invoiceStatus($response, $payload);

        if ($status !== '') {
            return $status === self::PAID_STATUS;
        }

        return in_array(self::status($response, -1), self::PAYMENT_COMPLETED_CODES, true);
    }

    /**
     * 从一个状态节点里取字符串状态：支持对象、列表、标量三种形态。
     */
    private static function nodeStatus(mixed $node): string
    {
        if (is_array($node)) {
            if (array_key_exists('status', $node)) {
                return self::normalizeStatus($node['status']);
            }

            // 上游把账单放在列表里：/v1/invoices/{id} 只会有一张，取第一条带状态的
            foreach ($node as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $status = self::nodeStatus($row);
                if ($status !== '') {
                    return $status;
                }
            }

            return '';
        }

        return self::normalizeStatus($node);
    }

    /**
     * 规范化状态值：只接受字符串，纯数字是业务码，一律视为缺失。
     */
    private static function normalizeStatus(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $normalized = strtolower(trim($value));

        return $normalized !== '' && ! is_numeric($normalized) ? $normalized : '';
    }
}
