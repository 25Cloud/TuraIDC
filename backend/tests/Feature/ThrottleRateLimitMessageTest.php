<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 限流触发统一返回中文 429 消息（ThrottleRequestsException 渲染）。
 */
class ThrottleRateLimitMessageTest extends TestCase
{
    public function test_throttle_returns_chinese_429_message(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $uri = '/api/test/throttle-429-'.$suffix;

        Route::get($uri, fn () => response()->json(['ok' => true]))
            ->middleware('api', 'throttle:2,1');

        $this->getJson($uri)->assertOk();
        $this->getJson($uri)->assertOk();
        $this->getJson($uri)
            ->assertStatus(429)
            ->assertJsonPath('code', 42900)
            ->assertJsonPath('message', '请求过于频繁，请稍后再试');
    }
}
