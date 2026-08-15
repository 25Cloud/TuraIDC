<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Integrations\Support\ProviderErrorMapper;
use PHPUnit\Framework\TestCase;

final class ProviderErrorMapperTest extends TestCase
{
    public function test_zjmf_provider_key_uses_the_generic_upstream_error_message(): void
    {
        $message = (new ProviderErrorMapper)->toUserMessage('zjmf_finance_api', 'provision');

        $this->assertSame('服务开通失败，请稍后重试', $message);
    }
}
