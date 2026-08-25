<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\Setting;
use App\Services\Site\SiteHomeService;
use Illuminate\Support\Facades\Cache;

/**
 * 官网首页 Hero 轮播内容服务。
 *
 * 以 settings 表的 home_hero 分组承载结构化内容：
 *  - home_hero.slides   JSON 数组，左侧轮播切换项
 *  - home_hero.features JSON 数组，底部特色卡片
 *
 * 未配置时返回与 `frontend-client/src/views/website/components/HomeHeroCarousel.vue`
 * 保持一致的默认数据，确保前台始终有内容可渲染。
 */
class HomeHeroService
{
    public const GROUP = 'home_hero';

    public const KEY_SLIDES = 'slides';

    public const KEY_FEATURES = 'features';

    private const CACHE_KEY = 'site:home:hero';

    private const CACHE_TTL_SECONDS = 300; // 5分钟：首页内容不频繁变化

    public const SHAPE_OPTIONS = ['computer', 'connection', 'security', 'value', 'support'];

    public const RIBBON_TYPE_OPTIONS = ['hot', 'warm', 'new'];

    private const LEGACY_HERO_VIDEO_PREFIX = '/uploads/hero-videos/';

    private const MEDIA_LIBRARY_HERO_VIDEO_PREFIX = '/uploads/media/videos/hero-videos/';

    private const FLAT_MEDIA_PREFIX = '/media/';

    /**
     * 返回前台与管理端均可直接消费的 hero 内容。
     *
     * @return array{slides: array<int, array<string, mixed>>, features: array<int, array<string, mixed>>}
     */
    public function getHero(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            function (): array {
                return [
                    'slides' => $this->readSlides(),
                    'features' => $this->readFeatures(),
                ];
            }
        );
    }

    /**
     * 保存 hero 内容。上层控制器完成校验后调用。
     *
     * @param  array<int, array<string, mixed>>  $slides
     * @param  array<int, array<string, mixed>>  $features
     * @return array{slides: array<int, array<string, mixed>>, features: array<int, array<string, mixed>>}
     */
    public function saveHero(array $slides, array $features): array
    {
        $normalizedSlides = [];
        foreach (array_values($slides) as $index => $slide) {
            $normalizedSlides[] = $this->normalizeSlide((array) $slide, $index);
        }

        $normalizedFeatures = [];
        foreach (array_values($features) as $index => $feature) {
            $normalizedFeatures[] = $this->normalizeFeature((array) $feature, $index);
        }

        Setting::setValues(self::GROUP, [
            self::KEY_SLIDES => $this->encodeJson($normalizedSlides),
            self::KEY_FEATURES => $this->encodeJson($normalizedFeatures),
        ]);

        Cache::forget(self::CACHE_KEY);
        // 首页聚合缓存的键由 SiteHomeService 统一构造：这里改 hero，内容版本号不会变，
        // 必须按当前版本把实际在用的两个变体显式清掉。'site:home:4:50:4' 是遗留的无版本键。
        Cache::forget(SiteHomeService::overviewCacheKey(0, 50, 4));
        Cache::forget(SiteHomeService::overviewCacheKey(4, 50, 4));
        Cache::forget('site:home:4:50:4');

        return [
            'slides' => $normalizedSlides,
            'features' => $normalizedFeatures,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function defaultSlides(): array
    {
        return [
            [
                'key' => 'refresh',
                'rail_title' => '官网换新',
                'title' => '官网焕新 · 云上新体验',
                'desc' => '产品目录、下单支付、自动开通、账单结算与服务支持统一打通；首页即是控制台的入口，让资源采购和后续管理始终在同一条链路里完成。',
                'primary_text' => '立即体验',
                'primary_path' => '/products',
                'secondary_text' => '查看详情',
                'secondary_path' => '/about',
                'shape' => 'computer',
                'video' => $this->defaultHeroVideoPath('hero-1.mp4'),
                'ribbon' => '',
                'ribbon_type' => 'new',
            ],
            [
                'key' => 'global',
                'rail_title' => '全球互联',
                'title' => '多地节点 · 全球低延迟互联',
                'desc' => '覆盖香港、美国与国内多地优质节点，三网 CN2 / BGP 线路优化回国；跨区域组网、跨境业务秒级响应，适合建站、代理、跨境电商与出海 SaaS。',
                'primary_text' => '选购节点',
                'primary_path' => '/products',
                'secondary_text' => '查看线路',
                'secondary_path' => '/help',
                'shape' => 'connection',
                'video' => $this->defaultHeroVideoPath('hero-2.mp4'),
                'ribbon' => '',
                'ribbon_type' => 'new',
            ],
            [
                'key' => 'security',
                'rail_title' => '安全防护',
                'title' => '企业级安全 · 稳定可靠交付',
                'desc' => 'T3+ 数据中心 + BGP 多线接入 + 100G 抗 DDoS 防护；实名认证、权限分级与操作留痕保障账户安全，长期业务和合规场景稳定承载。',
                'primary_text' => '查看防护',
                'primary_path' => '/products',
                'secondary_text' => '在线咨询',
                'secondary_path' => '/help',
                'shape' => 'security',
                'video' => $this->defaultHeroVideoPath('hero-3.mp4'),
                'ribbon' => '',
                'ribbon_type' => 'new',
            ],
            [
                'key' => 'value',
                'rail_title' => '实惠专区',
                'title' => '低门槛套餐 · 直享实惠价',
                'desc' => '新客首单 2 核 2G 云服务器 39 元/年起，轻量云电脑按月订阅即开即用；优惠券按单抵扣，配置随业务弹性升级，先用后付更省心。',
                'primary_text' => '立即抢购',
                'primary_path' => '/products',
                'secondary_text' => '查看优惠',
                'secondary_path' => '/products',
                'shape' => 'value',
                'video' => $this->defaultHeroVideoPath('hero-4.mp4'),
                'ribbon' => '',
                'ribbon_type' => 'warm',
            ],
            [
                'key' => 'support',
                'rail_title' => '企业客服',
                'title' => '企业客服 · 一对一专属服务',
                'desc' => '7×24 小时工单、官方QQ群与一对一商务对接，覆盖选型、部署、迁移、运维与结算；支持对公结算、批量采购、子账号协作与统一对账。',
                'primary_text' => '联系客服',
                'primary_path' => '/help',
                'secondary_text' => '企业采购',
                'secondary_path' => '/about',
                'shape' => 'support',
                'video' => $this->defaultHeroVideoPath('hero-5.mp4'),
                'ribbon' => '',
                'ribbon_type' => 'new',
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function defaultFeatures(): array
    {
        return [
            [
                'key' => 'dynamic',
                'kicker' => '产品动态',
                'title' => '香港 CN2 精品线路 上线',
                'desc' => '三网 CN2 GIA 优化回国，跨境业务低时延稳定承载。',
                'path' => '/products',
            ],
            [
                'key' => 'activity',
                'kicker' => '活动内容',
                'title' => '新客首单 39 元/年',
                'desc' => '2H2G 云服务器覆盖建站、代理、轻量业务全场景。',
                'path' => '/products',
            ],
            [
                'key' => 'enterprise',
                'kicker' => '企业专区',
                'title' => 'IDC 企业采购通道',
                'desc' => '统一账单、多子账号协作与对公结算能力同步上线。',
                'path' => '/about',
            ],
            [
                'key' => 'cloud-desktop',
                'kicker' => '轻量产品',
                'title' => '西安云电脑 即开即用',
                'desc' => '西安节点低延迟，支持远程办公、外包协作、教学实训。',
                'path' => '/products',
            ],
            [
                'key' => 'new',
                'kicker' => '新开产品',
                'title' => '十堰高防独立服务器',
                'desc' => '100G 抗 DDoS + BGP 多线，面向长期稳定业务承载。',
                'path' => '/products',
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readSlides(): array
    {
        $stored = $this->decodeJson(Setting::getValue(self::GROUP, self::KEY_SLIDES));

        if ($stored === null || $stored === []) {
            return $this->defaultSlides();
        }

        $normalized = [];
        foreach (array_values($stored) as $index => $slide) {
            $normalized[] = $this->normalizeSlide((array) $slide, $index);
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readFeatures(): array
    {
        $stored = $this->decodeJson(Setting::getValue(self::GROUP, self::KEY_FEATURES));

        if ($stored === null || $stored === []) {
            return $this->defaultFeatures();
        }

        $normalized = [];
        foreach (array_values($stored) as $index => $feature) {
            $normalized[] = $this->normalizeFeature((array) $feature, $index);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $slide
     * @return array<string, mixed>
     */
    private function normalizeSlide(array $slide, int $index = 0): array
    {
        // 未显式指定时按索引循环分配右侧插画，保证默认视觉效果。
        $shape = (string) ($slide['shape'] ?? '');
        if (! in_array($shape, self::SHAPE_OPTIONS, true)) {
            $shape = self::SHAPE_OPTIONS[$index % count(self::SHAPE_OPTIONS)];
        }

        $ribbonType = (string) ($slide['ribbon_type'] ?? $slide['ribbonType'] ?? '');
        if (! in_array($ribbonType, self::RIBBON_TYPE_OPTIONS, true)) {
            $ribbonType = 'new';
        }

        $key = trim((string) ($slide['key'] ?? ''));
        if ($key === '') {
            $seed = (string) ($slide['title'] ?? $slide['rail_title'] ?? $slide['railTitle'] ?? $index);
            $key = 'slide-'.substr(md5($seed.'-'.$index), 0, 8);
        }

        return [
            'key' => mb_substr($key, 0, 64),
            'rail_title' => mb_substr(trim((string) ($slide['rail_title'] ?? $slide['railTitle'] ?? '')), 0, 20),
            'title' => mb_substr(trim((string) ($slide['title'] ?? '')), 0, 80),
            'desc' => mb_substr(trim((string) ($slide['desc'] ?? '')), 0, 300),
            'primary_text' => mb_substr(trim((string) ($slide['primary_text'] ?? $slide['primaryText'] ?? '')), 0, 20),
            'primary_path' => mb_substr(trim((string) ($slide['primary_path'] ?? $slide['primaryPath'] ?? '')), 0, 255),
            'secondary_text' => mb_substr(trim((string) ($slide['secondary_text'] ?? $slide['secondaryText'] ?? '')), 0, 20),
            'secondary_path' => mb_substr(trim((string) ($slide['secondary_path'] ?? $slide['secondaryPath'] ?? '')), 0, 255),
            'shape' => $shape,
            'video' => $this->normalizeVideoPath($slide['video'] ?? ''),
            'ribbon' => mb_substr(trim((string) ($slide['ribbon'] ?? '')), 0, 10),
            'ribbon_type' => $ribbonType,
        ];
    }

    /**
     * @param  array<string, mixed>  $feature
     * @return array<string, mixed>
     */
    private function normalizeFeature(array $feature, int $index = 0): array
    {
        $key = trim((string) ($feature['key'] ?? ''));
        if ($key === '') {
            $seed = (string) ($feature['title'] ?? $feature['kicker'] ?? $index);
            $key = 'feature-'.substr(md5($seed.'-'.$index), 0, 8);
        }

        return [
            'key' => mb_substr($key, 0, 64),
            'kicker' => mb_substr(trim((string) ($feature['kicker'] ?? '')), 0, 20),
            'title' => mb_substr(trim((string) ($feature['title'] ?? '')), 0, 50),
            'desc' => mb_substr(trim((string) ($feature['desc'] ?? '')), 0, 120),
            'path' => mb_substr(trim((string) ($feature['path'] ?? '')), 0, 255),
        ];
    }

    private function normalizeVideoPath(mixed $value): string
    {
        $path = trim((string) $value);
        if ($path === '') {
            return '';
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $path = (string) (parse_url($path, PHP_URL_PATH) ?: '');
        }

        $path = '/'.ltrim(str_replace('\\', '/', $path), '/');
        $isFlatMediaPath = preg_match('/^\/media\/[^\/]+\.(mp4|webm|ogg|mov|m4v)$/i', $path) === 1;

        if (
            ! $isFlatMediaPath
            && ! str_starts_with($path, self::LEGACY_HERO_VIDEO_PREFIX)
            && ! str_starts_with($path, self::MEDIA_LIBRARY_HERO_VIDEO_PREFIX)
        ) {
            return '';
        }

        $filename = basename($path);
        if (! preg_match('/\.(mp4|webm|ogg|mov|m4v)$/i', $filename)) {
            return '';
        }

        if ($isFlatMediaPath) {
            return $path;
        }

        if (str_starts_with($path, self::MEDIA_LIBRARY_HERO_VIDEO_PREFIX)) {
            return $path;
        }

        return self::LEGACY_HERO_VIDEO_PREFIX.$filename;
    }

    private function defaultHeroVideoPath(string $filename): string
    {
        $libraryPath = MediaFileService::relativePath($filename);
        if (@is_file(public_path(ltrim($libraryPath, '/')))) {
            return $libraryPath;
        }

        $legacyPath = self::LEGACY_HERO_VIDEO_PREFIX.$filename;
        if (@is_file(public_path(ltrim($legacyPath, '/')))) {
            return $legacyPath;
        }

        return '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $value
     */
    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function decodeJson(mixed $raw): ?array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        return array_values(array_filter($decoded, 'is_array'));
    }
}
