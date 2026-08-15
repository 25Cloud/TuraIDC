<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Services\Content\HomeHeroService;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminHomeHeroControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        // 避免测试写入的 hero 配置影响真实前台，清理后让 HomeHeroService 回落到默认值。
        DB::table('settings')->where('group_key', 'home_hero')->delete();
        Cache::forget('site:home:hero');
        Cache::forget('site:home:4:50:4');

        parent::tearDown();
    }

    public function test_admin_can_fetch_and_update_home_hero(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'admin-hero-'.$suffix,
            'label' => 'Admin Hero',
            'permissions' => [AdminPermissions::ALL],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'admin-hero-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Admin Hero',
            'email' => 'admin-hero-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v2/admin/site/home-hero')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    'slides',
                    'features',
                    'defaults' => ['slides', 'features'],
                    'options' => ['shape', 'ribbon_type', 'videos'],
                ],
                'timestamp',
            ]);

        $payload = [
            'slides' => [
                [
                    'key' => 'refresh',
                    'rail_title' => '官网换新',
                    'title' => '官网焕新 · 新版本上线',
                    'desc' => '新版首页已经发布，欢迎立即体验。',
                    'primary_text' => '立即体验',
                    'primary_path' => '/products',
                    'secondary_text' => '查看详情',
                    'secondary_path' => '/about',
                    'shape' => 'computer',
                    'ribbon' => 'NEW',
                    'ribbon_type' => 'new',
                    'video' => '/uploads/hero-videos/hero-1.mp4',
                ],
            ],
            'features' => [
                [
                    'key' => 'dynamic',
                    'kicker' => '产品动态',
                    'title' => '香港 CN2 精品线路 上线',
                    'desc' => '新版线路已经发布，欢迎体验。',
                    'path' => '/products',
                ],
            ],
        ];

        $this->postJson('/api/v2/admin/site/home-hero', $payload)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.slides.0.rail_title', '官网换新')
            ->assertJsonPath('data.slides.0.video', '/uploads/hero-videos/hero-1.mp4')
            ->assertJsonPath('data.features.0.title', '香港 CN2 精品线路 上线');

        $service = app(HomeHeroService::class);
        $stored = $service->getHero();
        $this->assertSame('官网焕新 · 新版本上线', $stored['slides'][0]['title'] ?? '');
        $this->assertSame('产品动态', $stored['features'][0]['kicker'] ?? '');

        $this->getJson('/api/v2/site/home-hero')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.slides.0.title', '官网焕新 · 新版本上线')
            ->assertJsonPath('data.features.0.title', '香港 CN2 精品线路 上线');
    }

    public function test_admin_update_home_hero_accepts_media_library_video_path(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'admin-hero-media-'.$suffix,
            'label' => 'Admin Hero Media',
            'permissions' => [AdminPermissions::ALL],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'admin-hero-media-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Admin Hero Media',
            'email' => 'admin-hero-media-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v2/admin/site/home-hero', [
            'slides' => [
                [
                    'key' => 'refresh',
                    'rail_title' => '官网焕新',
                    'title' => '官网焕新 · 新版已上线',
                    'desc' => '新版首页已经发布，欢迎立即体验。',
                    'primary_text' => '立即体验',
                    'primary_path' => '/products',
                    'secondary_text' => '查看详情',
                    'secondary_path' => '/about',
                    'shape' => 'computer',
                    'ribbon' => 'NEW',
                    'ribbon_type' => 'new',
                    'video' => '/media/hero.mp4',
                ],
            ],
            'features' => [
                [
                    'key' => 'dynamic',
                    'kicker' => '产品动态',
                    'title' => '首页视频已切到媒体库目录',
                    'desc' => '媒体库视频可以直接给首页 Hero 使用。',
                    'path' => '/products',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.slides.0.video', '/media/hero.mp4');
    }

    public function test_admin_update_home_hero_auto_fills_shape_and_key_when_missing(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'admin-hero-auto-'.$suffix,
            'label' => 'Admin Hero Auto',
            'permissions' => [AdminPermissions::ALL],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'admin-hero-auto-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Admin Hero Auto',
            'email' => 'admin-hero-auto-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v2/admin/site/home-hero', [
            'slides' => [
                [
                    'rail_title' => '第一屏',
                    'title' => '第一屏标题',
                    'desc' => '第一屏描述',
                    'primary_text' => '立即体验',
                    'primary_path' => '/products',
                    'secondary_text' => '查看详情',
                    'secondary_path' => '/about',
                ],
                [
                    'rail_title' => '第二屏',
                    'title' => '第二屏标题',
                    'desc' => '第二屏描述',
                    'primary_text' => '选购节点',
                    'primary_path' => '/products',
                    'secondary_text' => '查看线路',
                    'secondary_path' => '/help',
                ],
            ],
            'features' => [
                [
                    'kicker' => '产品动态',
                    'title' => '第一张卡片',
                    'desc' => '卡片描述',
                ],
            ],
        ])->assertOk()->assertJsonPath('code', 0);

        $slides = $response->json('data.slides');
        $features = $response->json('data.features');

        $this->assertSame(HomeHeroService::SHAPE_OPTIONS[0], $slides[0]['shape'] ?? null);
        $this->assertSame(HomeHeroService::SHAPE_OPTIONS[1], $slides[1]['shape'] ?? null);
        $this->assertNotSame('', trim((string) ($slides[0]['key'] ?? '')));
        $this->assertNotSame('', trim((string) ($slides[1]['key'] ?? '')));
        $this->assertNotSame('', trim((string) ($features[0]['key'] ?? '')));
    }

    public function test_admin_update_home_hero_validates_required_fields(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'admin-hero-val-'.$suffix,
            'label' => 'Admin Hero Val',
            'permissions' => [AdminPermissions::ALL],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'admin-hero-val-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Admin Hero Val',
            'email' => 'admin-hero-val-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v2/admin/site/home-hero', [
            'slides' => [],
            'features' => [],
        ])->assertStatus(422);
    }

    public function test_admin_update_home_hero_rejects_more_than_five_slides(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'admin-hero-max-'.$suffix,
            'label' => 'Admin Hero Max',
            'permissions' => [AdminPermissions::ALL],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'admin-hero-max-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Admin Hero Max',
            'email' => 'admin-hero-max-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        Sanctum::actingAs($admin);

        $slides = [];
        for ($index = 1; $index <= 6; $index++) {
            $slides[] = [
                'rail_title' => '第'.$index.'屏',
                'title' => '第'.$index.'屏标题',
                'desc' => '第'.$index.'屏描述',
                'primary_text' => '立即体验',
                'primary_path' => '/products',
                'secondary_text' => '查看详情',
                'secondary_path' => '/about',
            ];
        }

        $this->postJson('/api/v2/admin/site/home-hero', [
            'slides' => $slides,
            'features' => [
                [
                    'kicker' => '产品动态',
                    'title' => '第一张卡片',
                    'desc' => '卡片描述',
                ],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200)
            ->assertJsonPath('data.errors.slides.0', '轮播项最多 5 个');
    }

    public function test_admin_update_home_hero_rejects_more_than_five_features(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'admin-hero-feature-max-'.$suffix,
            'label' => 'Admin Hero Feature Max',
            'permissions' => [AdminPermissions::ALL],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'admin-hero-feature-max-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Admin Hero Feature Max',
            'email' => 'admin-hero-feature-max-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        Sanctum::actingAs($admin);

        $features = [];
        for ($index = 1; $index <= 6; $index++) {
            $features[] = [
                'kicker' => '标签'.$index,
                'title' => '第'.$index.'张卡片',
                'desc' => '第'.$index.'张卡片描述',
                'path' => '/products',
            ];
        }

        $this->postJson('/api/v2/admin/site/home-hero', [
            'slides' => [
                [
                    'rail_title' => '第一屏',
                    'title' => '第一屏标题',
                    'desc' => '第一屏描述',
                    'primary_text' => '立即体验',
                    'primary_path' => '/products',
                    'secondary_text' => '查看详情',
                    'secondary_path' => '/about',
                ],
            ],
            'features' => $features,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200)
            ->assertJsonPath('data.errors.features.0', '特色卡片最多 5 个');
    }
}
