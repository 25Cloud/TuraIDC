<?php

declare(strict_types=1);

namespace App\Support\Seo;

/**
 * 官网 SEO 落地页的静态营销数据（与前端 src/data/seoLandingMeta.js 保持一致）。
 * 供后端动态渲染落地页的 head meta / JSON-LD / 正文使用。
 */
class SeoLandingPages
{
    /**
     * @return array<int, array{
     *   slug: string,
     *   path: string,
     *   keyword: string,
     *   title: string,
     *   description: string,
     *   keywords: string,
     *   changefreq: string,
     *   priority: string,
     *   hero_title: string,
     *   hero_summary: string,
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'slug' => 'cloud-server',
                'path' => '/cloud-server',
                'keyword' => '云服务器',
                'title' => '云服务器 - 稳定弹性计算与 IDC 云主机 - 图拉云',
                'description' => '图拉云云服务器面向企业网站、业务系统和开发测试场景，提供稳定弹性计算、灵活配置和 IDC 运维支持。',
                'keywords' => '云服务器,云主机,弹性云服务器,IDC 云服务器,图拉云',
                'changefreq' => 'weekly',
                'priority' => '0.9',
                'hero_title' => '稳定易用的云服务器',
                'hero_summary' => '面向网站托管、业务系统、接口服务和开发测试，按实际业务规模选择 CPU、内存、带宽和系统镜像，减少前期硬件投入。',
            ],
            [
                'slug' => 'hong-kong-server',
                'path' => '/hong-kong-server',
                'keyword' => '香港服务器',
                'title' => '香港服务器 - 面向出海与跨境访问的云服务器',
                'description' => '图拉云香港服务器适合跨境网站、外贸业务和亚太访问场景，提供云服务器配置选择与工单支持。',
                'keywords' => '香港服务器,香港云服务器,香港云主机,跨境服务器,图拉云',
                'changefreq' => 'weekly',
                'priority' => '0.8',
                'hero_title' => '面向亚太业务的香港服务器',
                'hero_summary' => '适用于外贸站点、跨境业务、亚太用户访问和业务中转场景，兼顾部署效率、访问体验和日常运维支持。',
            ],
            [
                'slug' => 'us-server',
                'path' => '/us-server',
                'keyword' => '美国服务器',
                'title' => '美国服务器 - 海外业务部署与网站托管',
                'description' => '图拉云美国服务器面向海外网站、跨境业务和开发测试场景，提供云服务器配置选择、系统部署和售后支持。',
                'keywords' => '美国服务器,美国云服务器,海外服务器,海外云主机,图拉云',
                'changefreq' => 'weekly',
                'priority' => '0.8',
                'hero_title' => '适合海外部署的美国服务器',
                'hero_summary' => '面向海外展示站、应用服务、跨境业务和测试环境，帮助团队用较低门槛部署海外访问入口。',
            ],
            [
                'slug' => 'high-defense-server',
                'path' => '/high-defense-server',
                'keyword' => '高防服务器',
                'title' => '高防服务器 - 面向攻击防护场景的云服务器 - 图拉云',
                'description' => '图拉云高防服务器适合游戏、业务接口和高风险网站等防护需求场景，提供配置选择与运维支持。',
                'keywords' => '高防服务器,高防云服务器,防护服务器,游戏服务器防护,图拉云',
                'changefreq' => 'weekly',
                'priority' => '0.8',
                'hero_title' => '面向防护需求的高防服务器',
                'hero_summary' => '适用于容易受到异常流量影响的网站、游戏和接口服务，帮助业务在风险场景下保持更清晰的资源与工单管理路径。',
            ],
            [
                'slug' => 'cloud-pc',
                'path' => '/cloud-pc',
                'keyword' => '云电脑',
                'title' => '云电脑 - 远程办公与轻量桌面云方案',
                'description' => '图拉云云电脑适合远程办公、轻量桌面、软件测试和临时工作环境，提供云端资源选择与账号管理能力。',
                'keywords' => '云电脑,云桌面,远程办公云电脑,桌面云,图拉云',
                'changefreq' => 'weekly',
                'priority' => '0.7',
                'hero_title' => '灵活可用的云电脑',
                'hero_summary' => '为远程办公、软件测试、临时桌面和轻量操作环境提供云端资源，减少本地设备差异带来的维护成本。',
            ],
        ];
    }

    /**
     * 按 path（如 /cloud-server）查找落地页。
     *
     * @return array<string, mixed>|null
     */
    public static function findByPath(string $path): ?array
    {
        $normalized = '/'.ltrim(trim($path), '/');

        foreach (self::all() as $page) {
            if ($page['path'] === $normalized) {
                return $page;
            }
        }

        return null;
    }
}
