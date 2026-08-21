<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfCredentialParser;

class ZjmfCredentialParserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 2).'/plugins/servers/zjmf_finance/lib/ZjmfCredentialParser.php';
    }

    public function test_it_extracts_zjmf_zjmf_cookie_from_raw_notes(): void
    {
        $cookie = (new ZjmfCredentialParser)->parseWebSessionCookie(
            "接口登录不可用时使用\nZJMF_SESSION=abc123; ZJMF_TOKEN=def456\n不要外泄"
        );

        $this->assertSame('ZJMF_SESSION=abc123; ZJMF_TOKEN=def456', $cookie);
    }

    public function test_it_ignores_generic_cookie_notes(): void
    {
        $cookie = (new ZjmfCredentialParser)->parseWebSessionCookie(
            'web_session_cookie=PHPSESSID=abc123'
        );

        $this->assertSame('', $cookie);
    }
}
