<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfUpstreamResponse;

require_once __DIR__.'/../../plugins/servers/zjmf_finance/lib/ZjmfUpstreamResponse.php';

/**
 * 上游账单支付状态解析（不启动 Laravel、不连库）。
 *
 * 回归的是真实缺陷：`/v1/invoices/{id}` 把账单支付状态放在 `data.invoices.status`，
 * 顶层 `data.status` 是业务码 `200`。按顶层读会恒得到 "200"，已付账单被当成未付，
 * 重试时又去 `/fund` 补付，上游对已付账单回 400「Bill paid」，开通重试直接失败。
 */
class ZjmfUpstreamInvoiceStatusTest extends TestCase
{
    public function test_invoice_status_reads_nested_invoices_node_instead_of_top_level_business_code(): void
    {
        // 与上游真实响应同形
        $response = [
            'status' => 200,
            'msg' => 'ok',
            'data' => [
                'invoices' => ['status' => 'Paid'],
            ],
        ];

        $this->assertSame('paid', ZjmfUpstreamResponse::invoiceStatus($response));
        $this->assertTrue(ZjmfUpstreamResponse::isInvoicePaid($response));
        // 顶层仍是业务码，业务码解析逻辑不受影响
        $this->assertSame(200, ZjmfUpstreamResponse::status($response));
    }

    public function test_invoice_status_accepts_invoices_node_rendered_as_a_list(): void
    {
        // 上游可能把账单放在列表里：只要第一条带状态就必须认
        $response = [
            'status' => 200,
            'data' => [
                'invoices' => [
                    ['id' => 77, 'status' => 'Unpaid'],
                ],
            ],
        ];

        $this->assertSame('unpaid', ZjmfUpstreamResponse::invoiceStatus($response));
        $this->assertFalse(ZjmfUpstreamResponse::isInvoicePaid($response));
    }

    public function test_invoice_status_falls_back_to_invoice_node(): void
    {
        $response = [
            'status' => 200,
            'data' => [
                'invoice' => ['id' => 77, 'status' => 'Refunded'],
            ],
        ];

        $this->assertSame('refunded', ZjmfUpstreamResponse::invoiceStatus($response));
        $this->assertFalse(ZjmfUpstreamResponse::isInvoicePaid($response));
    }

    public function test_numeric_top_level_status_is_never_treated_as_payment_status(): void
    {
        // 只有业务码、没有账单状态：不能把 200 当成支付的字符串状态
        $response = ['status' => 200, 'msg' => 'ok', 'data' => []];

        $this->assertSame('', ZjmfUpstreamResponse::invoiceStatus($response));
        $this->assertFalse(ZjmfUpstreamResponse::isInvoicePaid($response));
    }

    public function test_invoice_status_reads_response_level_invoices_node_without_data_wrapper(): void
    {
        $response = [
            'status' => 200,
            'invoices' => ['status' => 'Paid'],
        ];

        $this->assertSame('paid', ZjmfUpstreamResponse::invoiceStatus($response));
    }

    public function test_paid_detection_falls_back_to_payment_completed_business_codes(): void
    {
        // /check_order 这类端点只回业务码：1000/1001 表示已付，其余都不是
        $this->assertTrue(ZjmfUpstreamResponse::isInvoicePaid(['status' => 1001]));
        $this->assertTrue(ZjmfUpstreamResponse::isInvoicePaid(['status' => 1000]));
        $this->assertFalse(ZjmfUpstreamResponse::isInvoicePaid(['status' => 200]));
        $this->assertFalse(ZjmfUpstreamResponse::isInvoicePaid(['status' => 400, 'msg' => 'Bill paid']));
    }

    public function test_http_code_meta_survives_and_decides_failure(): void
    {
        $ok = ZjmfUpstreamResponse::withHttpCode([
            'response' => ['status' => 200, 'data' => []],
            'headers' => [],
            'http_code' => 200,
            'content_type' => 'application/json',
        ]);
        $this->assertSame(200, $ok[ZjmfUpstreamResponse::HTTP_CODE_META_KEY]);
        $this->assertTrue(ZjmfUpstreamResponse::hasSuccessfulHttpStatus($ok));

        // 5xx 即使业务码/账单状态看起来正常，也必须判失败
        $broken = ZjmfUpstreamResponse::withHttpCode([
            'response' => ['status' => 200, 'data' => ['invoices' => ['status' => 'Paid']]],
            'headers' => [],
            'http_code' => 502,
            'content_type' => 'text/html',
        ]);
        $this->assertFalse(ZjmfUpstreamResponse::hasSuccessfulHttpStatus($broken));

        // 裸响应体（没有 requestWithMeta 元信息）会得到 http_code=0，恒判失败——
        // 这正是「用 get() 的返回体去套 withHttpCode()」的老写法。
        $raw = ZjmfUpstreamResponse::withHttpCode(['status' => 200, 'data' => []]);
        $this->assertSame(0, $raw[ZjmfUpstreamResponse::HTTP_CODE_META_KEY]);
        $this->assertFalse(ZjmfUpstreamResponse::hasSuccessfulHttpStatus($raw));
    }

    public function test_payload_and_status_helpers_handle_missing_data_segment(): void
    {
        $this->assertSame(['a' => 1], ZjmfUpstreamResponse::payload(['data' => ['a' => 1]]));
        $this->assertSame(['status' => 200], ZjmfUpstreamResponse::payload(['status' => 200]));

        $this->assertSame(200, ZjmfUpstreamResponse::status(['status' => 200]));
        $this->assertSame(200, ZjmfUpstreamResponse::status(['data' => ['status' => 200]]));
        $this->assertSame(0, ZjmfUpstreamResponse::status([]));
        $this->assertSame(-1, ZjmfUpstreamResponse::status([], -1));
    }
}
