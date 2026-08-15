<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class Stay33ClientLoggingPolicyTest extends TestCase
{
    public function test_stay33_client_does_not_log_routine_api_requests_at_info_level(): void
    {
        $source = (string) file_get_contents(base_path('plugins/certification/stay33/logic/Stay33Client.php'));

        $this->assertStringNotContainsString("Log::info('[实名认证] API请求'", $source);
        $this->assertStringContainsString("Log::error('[实名认证] CURL请求失败'", $source);
    }
}
