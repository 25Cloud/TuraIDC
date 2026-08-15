<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Site\SiteHomeService;
use Tests\TestCase;

class SiteHomeControllerTest extends TestCase
{
    public function test_site_home_controller_returns_aggregated_home_payload(): void
    {
        $service = $this->createMock(SiteHomeService::class);
        $service->expects($this->once())
            ->method('overview')
            ->willReturn([
                'site_config' => [
                    'site_name' => 'Cloud Console',
                    'browser_title' => 'Cloud Console',
                    'site_logo' => '/branding/logo1.svg',
                    'site_favicon' => '/branding/logo1.svg',
                ],
                'notices' => [
                    ['id' => 1, 'title' => 'System notice'],
                ],
                'help_articles' => [
                    ['id' => 2, 'title' => '婵″倷缍嶇拹顓濇嫳娴滄垶婀囬崝鈥虫珤'],
                ],
                'root_groups' => [
                    ['id' => 11, 'name' => 'Cloud Servers', 'product_type_id' => 1],
                ],
                'group_catalog_map' => [
                    11 => [
                        'preview_products' => [
                            ['id' => 101, 'name' => '缂囧骸娴楁禍?2H2G'],
                        ],
                    ],
                ],
            ]);

        $this->app->instance(SiteHomeService::class, $service);

        $this->getJson('/api/v2/site/home')
            ->assertOk()
            ->assertJsonPath('data.site_config.site_name', 'Cloud Console')
            ->assertJsonPath('data.notices.0.title', 'System notice')
            ->assertJsonPath('data.root_groups.0.name', 'Cloud Servers')
            ->assertJsonPath('data.group_catalog_map.11.preview_products.0.name', '缂囧骸娴楁禍?2H2G');
    }
}
