<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SensitiveDataSanitizer;
use Tests\TestCase;

class SensitiveDataSanitizerTest extends TestCase
{
    public function test_sanitize_text_redacts_oauth_client_credentials_in_urls(): void
    {
        $message = 'cURL error for https://example.test/oauth/token?client_id=api-key&client_secret=secret-value&grant_type=client_credentials';

        $sanitized = SensitiveDataSanitizer::sanitizeText($message);

        $this->assertStringNotContainsString('api-key', $sanitized);
        $this->assertStringNotContainsString('secret-value', $sanitized);
        $this->assertStringContainsString('client_id="[REDACTED]"', $sanitized);
        $this->assertStringContainsString('client_secret="[REDACTED]"', $sanitized);
    }

    public function test_sanitize_text_redacts_pii_and_bearer_tokens(): void
    {
        $message = 'user demo@example.com phone 13800138000 ip 203.0.113.10 Authorization: Bearer abc.def.ghi';

        $sanitized = SensitiveDataSanitizer::sanitizeText($message);

        $this->assertStringNotContainsString('demo@example.com', $sanitized);
        $this->assertStringNotContainsString('13800138000', $sanitized);
        $this->assertStringNotContainsString('203.0.113.10', $sanitized);
        $this->assertStringNotContainsString('abc.def.ghi', $sanitized);
        $this->assertStringContainsString('[REDACTED_EMAIL]', $sanitized);
        $this->assertStringContainsString('[REDACTED_PHONE]', $sanitized);
        $this->assertStringContainsString('[REDACTED_IP]', $sanitized);
        $this->assertStringContainsString('Authorization: "[REDACTED]"', $sanitized);
    }

    public function test_sanitize_redacts_identity_fields(): void
    {
        $sanitized = SensitiveDataSanitizer::sanitize([
            'email' => 'demo@example.com',
            'phone' => '13800138000',
            'account' => 'demo@example.com',
            'nested' => [
                'ip_address' => '203.0.113.10',
            ],
        ]);

        $this->assertSame('[REDACTED]', $sanitized['email'] ?? null);
        $this->assertSame('[REDACTED]', $sanitized['phone'] ?? null);
        $this->assertSame('[REDACTED]', $sanitized['account'] ?? null);
        $this->assertSame('[REDACTED]', $sanitized['nested']['ip_address'] ?? null);
    }
}
