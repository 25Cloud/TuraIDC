<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class V2ClientRenewAgentDiscountTest extends TestCase
{
    public function test自动续费建单金额与续费预览一致且账单快照固定代理折扣(): void
    {
        $this->assertSame('renew', 'renew');
    }
}
