<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Services\Content\HomeHeroService;
use App\Services\Content\MediaFileService;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class V2AdminHomeHeroApiTest extends TestCase
{
    public function test_home_hero_show_requires_content_list_and_returns_whitelisted_payload(): void
    {
        $this->mock(HomeHeroService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getHero')
                ->once()
                ->andReturn([
                    'slides' => [$this->heroSlide(['secret' => 'must-not-leak'])],
                    'features' => [$this->heroFeature(['api_key' => 'must-not-leak'])],
                ]);
            $mock->shouldReceive('defaultSlides')
                ->once()
                ->andReturn([$this->heroSlide(['password' => 'must-not-leak'])]);
            $mock->shouldReceive('defaultFeatures')
                ->once()
                ->andReturn([$this->heroFeature(['raw_response' => 'must-not-leak'])]);
        });
        $this->mock(MediaFileService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('listHeroVideos')
                ->once()
                ->andReturn([$this->heroVideo(['third_party_response' => 'must-not-leak'])]);
        });

        $this->getJson('/api/v2/admin/site/home-hero')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_VIEW]));

        $this->getJson('/api/v2/admin/site/home-hero')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::CONTENT_LIST]));

        $this->getJson('/api/v2/admin/site/home-hero?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->getJson('/api/v2/admin/site/home-hero')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.slides.0.title', '高性能云服务器')
            ->assertJsonPath('data.features.0.title', '即时交付')
            ->assertJsonPath('data.options.videos.0.filename', 'hero.mp4')
            ->assertJsonMissingPath('data.slides.0.secret')
            ->assertJsonMissingPath('data.features.0.api_key')
            ->assertJsonMissingPath('data.defaults.slides.0.password')
            ->assertJsonMissingPath('data.defaults.features.0.raw_response')
            ->assertJsonMissingPath('data.options.videos.0.third_party_response');

        $payload = $response->json();
        $this->assertSame(['code', 'message', 'data', 'timestamp'], array_keys($payload));
        $this->assertSame(['slides', 'features', 'defaults', 'options'], array_keys($payload['data']));
        $this->assertSame($this->slideFields(), array_keys($payload['data']['slides'][0]));
        $this->assertSame($this->featureFields(), array_keys($payload['data']['features'][0]));
        $this->assertSame($this->videoFields(), array_keys($payload['data']['options']['videos'][0]));
        $this->assertNoSensitiveKeys($payload);
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_home_hero_update_requires_content_manage_and_returns_whitelisted_payload(): void
    {
        $this->mock(HomeHeroService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('saveHero')
                ->once()
                ->withArgs(fn (array $slides, array $features): bool => ($slides[0]['title'] ?? null) === '高性能云服务器'
                    && ($features[0]['title'] ?? null) === '即时交付')
                ->andReturn([
                    'slides' => [$this->heroSlide(['secret' => 'must-not-leak'])],
                    'features' => [$this->heroFeature(['api_key' => 'must-not-leak'])],
                ]);
            $mock->shouldReceive('defaultSlides')
                ->once()
                ->andReturn([$this->heroSlide()]);
            $mock->shouldReceive('defaultFeatures')
                ->once()
                ->andReturn([$this->heroFeature()]);
        });
        $this->mock(MediaFileService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('listHeroVideos')
                ->once()
                ->andReturn([$this->heroVideo(['api_key' => 'must-not-leak'])]);
        });

        Sanctum::actingAs($this->createAdmin([AdminPermissions::CONTENT_LIST]));

        $this->postJson('/api/v2/admin/site/home-hero', $this->validPayload())
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::CONTENT_MANAGE]));

        $this->postJson('/api/v2/admin/site/home-hero', array_merge($this->validPayload(), ['per_page' => 20]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->postJson('/api/v2/admin/site/home-hero', $this->validPayload())
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '首页 Banner 已保存')
            ->assertJsonPath('data.slides.0.title', '高性能云服务器')
            ->assertJsonPath('data.options.videos.0.filename', 'hero.mp4')
            ->assertJsonMissingPath('data.slides.0.secret')
            ->assertJsonMissingPath('data.features.0.api_key')
            ->assertJsonMissingPath('data.options.videos.0.api_key');

        $payload = $response->json();
        $this->assertSame(['slides', 'features', 'defaults', 'options'], array_keys($payload['data']));
        $this->assertSame($this->slideFields(), array_keys($payload['data']['slides'][0]));
        $this->assertSame($this->featureFields(), array_keys($payload['data']['features'][0]));
        $this->assertSame($this->videoFields(), array_keys($payload['data']['options']['videos'][0]));
        $this->assertNoSensitiveKeys($payload);
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_home_hero_video_options_are_capped_to_control_response_size(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::CONTENT_LIST]));

        $this->mock(HomeHeroService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getHero')
                ->once()
                ->andReturn([
                    'slides' => [$this->heroSlide()],
                    'features' => [$this->heroFeature()],
                ]);
            $mock->shouldReceive('defaultSlides')
                ->once()
                ->andReturn([$this->heroSlide()]);
            $mock->shouldReceive('defaultFeatures')
                ->once()
                ->andReturn([$this->heroFeature()]);
        });
        $this->mock(MediaFileService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('listHeroVideos')
                ->once()
                ->andReturn(
                    collect(range(1, 150))
                        ->map(fn (int $index): array => $this->heroVideo([
                            'id' => $index,
                            'filename' => 'hero-'.$index.'.mp4',
                        ]))
                        ->all()
                );
        });

        $response = $this->getJson('/api/v2/admin/site/home-hero')
            ->assertOk()
            ->assertJsonCount(100, 'data.options.videos');

        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-home-hero-'.$suffix,
            'label' => 'V2 Home Hero',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-home-hero-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Home Hero',
            'email' => 'v2-home-hero-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function heroSlide(array $overrides = []): array
    {
        return array_merge([
            'key' => 'cloud',
            'rail_title' => '云服务器',
            'title' => '高性能云服务器',
            'desc' => '稳定弹性资源',
            'primary_text' => '立即选购',
            'primary_path' => '/products/cloud',
            'secondary_text' => '查看文档',
            'secondary_path' => '/help',
            'shape' => 'computer',
            'video' => '/uploads/hero/hero.mp4',
            'ribbon' => '推荐',
            'ribbon_type' => 'hot',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function heroFeature(array $overrides = []): array
    {
        return array_merge([
            'key' => 'delivery',
            'kicker' => '交付',
            'title' => '即时交付',
            'desc' => '订单支付后自动开通',
            'path' => '/products',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function heroVideo(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'filename' => 'hero.mp4',
            'path' => '/uploads/hero/hero.mp4',
            'url' => '/uploads/hero/hero.mp4',
            'mime_type' => 'video/mp4',
            'size' => 1024,
            'width' => 1920,
            'height' => 1080,
            'group' => 'home_hero',
            'type' => 'video',
            'created_at' => '2026-07-05 00:00:00',
        ], $overrides);
    }

    /**
     * @return array{slides: list<array<string, mixed>>, features: list<array<string, mixed>>}
     */
    private function validPayload(): array
    {
        return [
            'slides' => [$this->heroSlide()],
            'features' => [$this->heroFeature()],
        ];
    }

    /**
     * @return list<string>
     */
    private function slideFields(): array
    {
        return [
            'key',
            'rail_title',
            'title',
            'desc',
            'primary_text',
            'primary_path',
            'secondary_text',
            'secondary_path',
            'shape',
            'video',
            'ribbon',
            'ribbon_type',
        ];
    }

    /**
     * @return list<string>
     */
    private function featureFields(): array
    {
        return [
            'key',
            'kicker',
            'title',
            'desc',
            'path',
        ];
    }

    /**
     * @return list<string>
     */
    private function videoFields(): array
    {
        return [
            'id',
            'filename',
            'path',
            'url',
            'mime_type',
            'size',
            'width',
            'height',
            'group',
            'type',
            'created_at',
        ];
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response', 'token'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
