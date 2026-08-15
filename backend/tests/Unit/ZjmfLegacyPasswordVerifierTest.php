<?php

declare(strict_types=1);

namespace Tests\Unit;

use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfLegacyPasswordVerifier;
use PHPUnit\Framework\TestCase;

class ZjmfLegacyPasswordVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 2).'/plugins/servers/zjmf_finance/lib/ZjmfLegacyPasswordVerifier.php';
    }

    public function test_it_accepts_zjmf_md5_password_and_requests_rehash(): void
    {
        $needsRehash = false;

        $matched = (new ZjmfLegacyPasswordVerifier)->verify(
            'Secret123',
            '###'.md5('Secret123'),
            $needsRehash
        );

        $this->assertTrue($matched);
        $this->assertTrue($needsRehash);
    }

    public function test_it_rejects_invalid_zjmf_md5_password(): void
    {
        $needsRehash = false;

        $matched = (new ZjmfLegacyPasswordVerifier)->verify(
            'Secret123',
            '###'.md5('OtherSecret'),
            $needsRehash
        );

        $this->assertFalse($matched);
        $this->assertFalse($needsRehash);
    }

    public function test_it_ignores_non_zjmf_hashes(): void
    {
        $needsRehash = false;

        $matched = (new ZjmfLegacyPasswordVerifier)->verify(
            'Secret123',
            '$2y$10$not-a-real-bcrypt-hash',
            $needsRehash
        );

        $this->assertNull($matched);
        $this->assertFalse($needsRehash);
    }
}
