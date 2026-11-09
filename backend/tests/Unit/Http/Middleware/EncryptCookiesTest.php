<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\EncryptCookies;
use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EncryptCookiesTest extends TestCase
{
    #[Test]
    public function console_area_ticket_cookies_are_not_re_encrypted_by_stateful_sanctum(): void
    {
        $middleware = new EncryptCookies(new Encrypter(str_repeat('a', 32), 'AES-256-CBC'));

        $this->assertTrue($middleware->isDisabled('tura_console_area_ticket_3458'));
        $this->assertFalse($middleware->isDisabled('tura_session'));
    }
}
