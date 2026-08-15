<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ApiResponseBuilder;
use Tests\TestCase;

class JsonEncodingTest extends TestCase
{
    public function test_api_response_builder_outputs_unescaped_unicode(): void
    {
        $content = ApiResponseBuilder::success(['foo' => 'bar'])->getContent();

        $this->assertIsString($content);
        $this->assertStringContainsString('操作成功', $content);
        $this->assertStringNotContainsString('\u64cd\u4f5c\u6210\u529f', $content);
    }

    public function test_api_middleware_keeps_exception_message_unescaped(): void
    {
        $response = $this->getJson('/api/v2/admin/auth/info');
        $content = $response->getContent();

        $response->assertStatus(401);
        $this->assertIsString($content);
        $this->assertStringContainsString('未登录或登录已过期', $content);
        $this->assertStringNotContainsString('\u672a\u767b\u5f55\u6216\u767b\u5f55\u5df2\u8fc7\u671f', $content);
    }
}
