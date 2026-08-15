<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PublicUrl;
use LogicException;
use Tests\TestCase;

class PublicUrlTest extends TestCase
{
    public function test_builds_http_urls_for_four_independent_origins(): void
    {
        config([
            'app.url' => 'http://api.example.test:8000',
            'app.frontend_url' => 'http://www.example.test',
            'app.client_console_url' => 'http://console.example.test',
            'app.admin_url' => 'http://admin.example.test',
        ]);

        $this->assertSame('http://api.example.test:8000/api/v2/site/config', PublicUrl::api('/api/v2/site/config'));
        $this->assertSame('http://www.example.test/products', PublicUrl::website('/products'));
        $this->assertSame('http://console.example.test/client/tickets', PublicUrl::console('/client/tickets'));
        $this->assertSame('http://admin.example.test', PublicUrl::admin());
    }

    public function test_builds_https_urls_without_changing_scheme(): void
    {
        config([
            'app.url' => 'https://api.example.test/',
            'app.frontend_url' => 'https://www.example.test/',
            'app.client_console_url' => 'https://console.example.test:8443/',
            'app.admin_url' => 'https://admin.example.test/',
        ]);

        $this->assertSame('https://api.example.test/uploads/logo.svg', PublicUrl::api('/uploads/logo.svg'));
        $this->assertSame('https://www.example.test/products', PublicUrl::website('products'));
        $this->assertSame('https://console.example.test:8443/client/tickets', PublicUrl::console('/client/tickets'));
    }

    public function test_rejects_public_url_with_a_path(): void
    {
        config(['app.client_console_url' => 'https://console.example.test/client']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('CLIENT_CONSOLE_URL');

        PublicUrl::console();
    }

    public function test_rejects_non_http_public_url(): void
    {
        config(['app.admin_url' => 'ftp://admin.example.test']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ADMIN_URL');

        PublicUrl::admin();
    }
}
