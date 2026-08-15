<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\UploadUrl;
use Tests\TestCase;

class UploadUrlTest extends TestCase
{
    public function test_absolute_upload_url_stays_original_when_local_file_is_missing(): void
    {
        config()->set('app.frontend_url', 'http://127.0.0.1:5173');

        $resolved = UploadUrl::resolve('https://www.coyjs.cn/uploads/content/20260419/img_181559_6183.png');

        $this->assertSame('https://www.coyjs.cn/uploads/content/20260419/img_181559_6183.png', $resolved);
    }

    public function test_media_relative_path_uses_api_base_url(): void
    {
        config()->set('app.url', 'http://127.0.0.1:8000');

        $resolved = UploadUrl::resolve('/media/logo.svg');

        $this->assertSame('http://127.0.0.1:8000/media/logo.svg', $resolved);
    }

    public function test_frontend_branding_path_stays_relative(): void
    {
        $this->assertSame('/branding/logo.svg', UploadUrl::resolve('/branding/logo.svg'));
    }
}
