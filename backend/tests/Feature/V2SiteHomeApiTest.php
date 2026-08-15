<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Content\ContentArticleService;
use App\Services\Content\HomeHeroService;
use App\Services\Site\SiteHomeService;
use App\Services\Site\SiteProductReadService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class V2SiteHomeApiTest extends TestCase
{
    public function test_site_config_uses_v2_envelope_and_public_whitelist(): void
    {
        $this->getJson('/api/v2/site/config?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->getJson('/api/v2/site/config')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['code', 'message', 'data', 'timestamp']);

        $this->assertSame([
            'site_name',
            'browser_title',
            'site_logo',
            'site_favicon',
            'client_console_icon',
            'service_qq_group',
            'service_phone',
            'service_email',
            'service_hours',
            'support_group_title',
            'support_group_text',
            'support_group_qr',
            'support_group_link',
            'terms_url',
            'privacy_url',
        ], array_keys($response->json('data')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(50 * 1024, strlen((string) $response->getContent()));
    }

    public function test_site_home_uses_limited_v2_projection(): void
    {
        $service = $this->createMock(SiteHomeService::class);
        $service->expects($this->once())
            ->method('overview')
            ->with(8, 6, 4)
            ->willReturn($this->homePayload());

        $this->app->instance(SiteHomeService::class, $service);

        $response = $this->getJson('/api/v2/site/home')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.site_config.site_name', '图拉云')
            ->assertJsonPath('data.hero.slides.0.title', 'Fast Cloud')
            ->assertJsonPath('data.notices.0.title', 'Notice')
            ->assertJsonPath('data.root_groups.0.name', 'VPS')
            ->assertJsonPath('data.root_groups.0.first_product_group_code', 'vps')
            ->assertJsonPath('data.root_groups.0.first_product_group_name', 'VPS');

        $catalogMap = $response->json('data.group_catalog_map');
        $this->assertIsArray($catalogMap);

        $this->assertTrue(array_key_exists(11, $catalogMap) || array_key_exists('11', $catalogMap));
        $catalogItem = $catalogMap[11] ?? $catalogMap['11'] ?? null;

        $this->assertIsArray($catalogItem);
        $this->assertSame('2C2G', $catalogItem['preview_products'][0]['display_name'] ?? null);

        $this->assertSame([
            'site_config',
            'hero',
            'notices',
            'help_articles',
            'root_groups',
            'group_catalog_map',
        ], array_keys($response->json('data')));
        $this->assertSame([
            'id',
            'effective_product_group_id',
            'name',
            'display_name',
            'instance_spec_text',
            'instance_spec_alias',
            'primary_price',
        ], array_keys($catalogItem['preview_products'][0]));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(50 * 1024, strlen((string) $response->getContent()));
    }

    public function test_site_home_service_returns_complete_root_groups_and_prioritizes_catalog_previews(): void
    {
        Cache::flush();

        $contentArticles = $this->createMock(ContentArticleService::class);
        $contentArticles->expects($this->once())
            ->method('publishedOverview')
            ->with(1, 1)
            ->willReturn([
                'notices' => collect(),
                'help_articles' => collect(),
            ]);

        $siteProducts = $this->createMock(SiteProductReadService::class);
        $siteProducts->expects($this->once())
            ->method('productTypes')
            ->willReturn([
                'list' => [
                    ['value' => 'vps', 'label' => '云服务器'],
                    ['value' => 'dedicated', 'label' => '游戏云'],
                    ['value' => 'domain', 'label' => '云电脑'],
                    ['value' => 'bare', 'label' => '裸金属'],
                ],
            ]);
        $siteProducts->expects($this->once())
            ->method('productGroups')
            ->willReturn([
                'list' => [
                    $this->homeRootGroup(10, '裸金属 A', 'bare', '裸金属'),
                    $this->homeRootGroup(20, 'Gold', 'dedicated', '游戏云'),
                    $this->homeRootGroup(21, 'Platinum', 'dedicated', '游戏云'),
                    $this->homeRootGroup(30, '襄阳', 'vps', '云服务器'),
                    $this->homeRootGroup(40, '云电脑 A', 'domain', '云电脑'),
                ],
            ]);
        $siteProducts->expects($this->once())
            ->method('groupCatalogMap')
            ->with($this->callback(fn (array $ids): bool => $ids === [30, 20, 40, 10]))
            ->willReturn([]);

        $homeHero = $this->createMock(HomeHeroService::class);
        $homeHero->expects($this->once())
            ->method('getHero')
            ->willReturn(['slides' => [], 'features' => []]);

        $service = new SiteHomeService($contentArticles, $siteProducts, $homeHero);
        $payload = $service->overview(groupLimit: 4, noticeLimit: 1, helpLimit: 1);

        $this->assertSame(
            ['裸金属 A', 'Gold', 'Platinum', '襄阳', '云电脑 A'],
            array_column($payload['root_groups'], 'name')
        );
        $this->assertSame(
            ['bare', 'dedicated', 'dedicated', 'vps', 'domain'],
            array_column($payload['root_groups'], 'product_type')
        );
        $this->assertSame(
            ['bare', 'dedicated', 'dedicated', 'vps', 'domain'],
            array_column($payload['root_groups'], 'first_product_group_code')
        );
    }

    public function test_site_home_hero_uses_v2_projection(): void
    {
        $service = $this->createMock(SiteHomeService::class);
        $service->expects($this->once())
            ->method('hero')
            ->willReturn($this->homePayload()['hero']);

        $this->app->instance(SiteHomeService::class, $service);

        $response = $this->getJson('/api/v2/site/home-hero')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.slides.0.title', 'Fast Cloud')
            ->assertJsonPath('data.features.0.title', 'Stable');

        $this->assertSame(['slides', 'features'], array_keys($response->json('data')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(50 * 1024, strlen((string) $response->getContent()));
    }

    /**
     * @return array<string, mixed>
     */
    private function homePayload(): array
    {
        return [
            'site_config' => [
                'site_name' => '图拉云',
                'browser_title' => '图拉云',
                'site_logo' => '/logo.svg',
                'site_favicon' => '/favicon.ico',
                'client_console_icon' => '/console.svg',
                'service_phone' => '10000',
                'service_qq_group' => '10000',
                'service_email' => 'support@example.com',
                'service_hours' => '10:00-18:00',
                'support_group_title' => 'Support',
                'support_group_text' => 'Join us',
                'support_group_qr' => '/qr.png',
                'support_group_link' => '/support',
                'terms_url' => '/terms',
                'privacy_url' => '/privacy',
                'api_key' => 'must-not-leak',
            ],
            'hero' => [
                'slides' => [[
                    'key' => 'fast',
                    'rail_title' => 'Cloud',
                    'title' => 'Fast Cloud',
                    'desc' => 'Fast instances',
                    'primary_text' => 'Buy',
                    'primary_path' => '/products',
                    'secondary_text' => 'Docs',
                    'secondary_path' => '/help',
                    'shape' => 'square',
                    'video' => '/hero.mp4',
                    'ribbon' => 'New',
                    'ribbon_type' => 'info',
                    'secret' => 'must-not-leak',
                ]],
                'features' => [[
                    'key' => 'stable',
                    'kicker' => 'SLA',
                    'title' => 'Stable',
                    'desc' => 'Stable network',
                    'path' => '/products',
                    'raw_response' => ['must-not-leak' => true],
                ]],
            ],
            'notices' => [[
                'id' => 1,
                'title' => 'Notice',
                'summary' => 'Summary',
                'excerpt' => 'Excerpt',
                'cover_image' => null,
                'category' => 'Notice',
                'is_pinned' => 1,
                'is_recommended' => 0,
                'publish_at' => '2026-07-05 00:00:00',
                'updated_at' => '2026-07-05 00:00:00',
                'password' => 'must-not-leak',
            ]],
            'help_articles' => [[
                'id' => 2,
                'title' => 'Help',
                'summary' => null,
                'excerpt' => 'Help excerpt',
                'cover_image' => null,
                'category' => 'Help',
                'is_pinned' => 0,
                'is_recommended' => 1,
                'publish_at' => null,
                'updated_at' => null,
            ]],
            'root_groups' => [[
                'id' => 11,
                'name' => 'VPS',
                'slogan' => 'Compute',
                'product_count' => 3,
                'product_type' => 'vps',
                'product_type_id' => 1,
                'product_type_label' => 'VPS',
                'first_product_group_code' => 'vps',
                'first_product_group_name' => 'VPS',
                'third_party_response' => 'must-not-leak',
            ]],
            'group_catalog_map' => [
                11 => [
                    'preview_products' => [[
                        'id' => 101,
                        'effective_product_group_id' => 11,
                        'name' => 'vps-2c2g',
                        'display_name' => '2C2G',
                        'instance_spec_text' => '2C / 2G',
                        'instance_spec_alias' => '2C2G',
                        'primary_price' => '19.90',
                        'secret' => 'must-not-leak',
                    ]],
                    'featured_product' => [
                        'id' => 101,
                        'effective_product_group_id' => 11,
                        'name' => 'vps-2c2g',
                        'display_name' => '2C2G',
                        'instance_spec_text' => '2C / 2G',
                        'instance_spec_alias' => '2C2G',
                        'primary_price' => '19.90',
                    ],
                ],
            ],
        ];
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function homeRootGroup(int $id, string $name, string $type, string $typeLabel): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'slogan' => '',
            'product_count' => 1,
            'product_type' => $type,
            'first_product_group_code' => $type,
            'product_type_id' => $id,
            'product_type_label' => $typeLabel,
        ];
    }
}
