<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MessageLog;
use App\Models\User;
use App\Services\System\AdminLogService;
use App\Services\User\UserService;
use Tests\TestCase;

class SmsLogSanitizationTest extends TestCase
{
    public function test_user_and_admin_sms_log_reads_return_raw_verification_codes(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $email = "sms-log-user-{$suffix}@example.com";
        $phone = '138'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $requestId = 'req-otp-'.$suffix;

        $user = User::query()->create([
            'email' => $email,
            'password' => 'secret123',
            'phone' => $phone,
            'status' => 1,
        ]);

        MessageLog::query()->create([
            'channel' => 'sms',
            'recipient' => $phone,
            'template_code' => '100001',
            'content' => '您的验证码为123456，5分钟内有效。',
            'params_json' => ['code' => '123456', 'min' => '5'],
            'provider' => 'aliyun',
            'request_id' => $requestId,
            'status' => 'success',
            'origin_type' => 'sms_verify',
            'origin_id' => 0,
        ]);

        $userLogs = app(UserService::class)->smsLogs($user, 20);
        $userItem = (array) ($userLogs->items()[0] ?? []);

        $this->assertSame($phone, (string) ($userItem['phone'] ?? ''));
        $this->assertSame('您的验证码为123456，5分钟内有效。', $userItem['content'] ?? '');
        $this->assertSame('123456', data_get($userItem, 'params_json.code'));

        $adminLogs = app(AdminLogService::class)->getSmsLogs(['keyword' => $requestId], 20);
        $adminItem = (array) (($adminLogs['data'][0] ?? $adminLogs['list'][0] ?? []) ?: []);

        $this->assertSame($phone, (string) ($adminItem['phone'] ?? ''));
        $this->assertSame('您的验证码为123456，5分钟内有效。', $adminItem['content'] ?? '');
        $this->assertSame('123456', data_get($adminItem, 'params.code'));
    }

    public function test_sms_service_source_keeps_raw_otp_log_content_without_provider_raw_response_log(): void
    {
        $content = file_get_contents(base_path('app/Services/System/SmsService.php'));

        $this->assertIsString($content);
        $this->assertStringNotContainsString('内容已脱敏', $content);
        $this->assertStringNotContainsString("'code' => '***'", $content);
        $this->assertStringNotContainsString("'raw' => \$result", $content);
        $this->assertStringNotContainsString("'decoded' => \$decoded", $content);
        $this->assertStringNotContainsString("'provider' => 'aliyun'", $content);
    }
}
