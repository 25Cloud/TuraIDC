<?php

declare(strict_types=1);

namespace App\Services\Site;

use App\Models\ContentArticle;
use App\Services\Content\ContentArticleService;
use App\Services\Content\HomeHeroService;
use App\Support\ContentPublishedCacheVersion;
use App\Support\SiteConfigPayload;
use App\Support\UploadUrl;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SiteHomeService
{
    private const HOME_CACHE_TTL_SECONDS = 600; // 10分钟：首页聚合数据可适当延长

    public function __construct(
        private readonly ContentArticleService $contentArticleService,
        private readonly SiteProductReadService $siteProductReadService,
        private readonly HomeHeroService $homeHeroService,
    ) {}

    public function overview(int $groupLimit = 0, int $noticeLimit = 50, int $helpLimit = 4): array
    {
        $groupLimit = max(0, $groupLimit);
        $contentVersion = ContentPublishedCacheVersion::current();
        $cacheKey = sprintf(
            'site:home:%s:%d:%d:v%d',
            $groupLimit > 0 ? (string) $groupLimit : 'all',
            $noticeLimit,
            $helpLimit,
            $contentVersion
        );

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(self::HOME_CACHE_TTL_SECONDS),
            function () use ($groupLimit, $noticeLimit, $helpLimit): array {
                $contentOverview = $this->contentArticleService->publishedOverview($noticeLimit, $helpLimit);
                $productTypes = $this->siteProductReadService->productTypes()['list'] ?? [];
                $allRootGroups = collect($this->siteProductReadService->productGroups()['list'] ?? []);

                $catalogRootGroups = $groupLimit > 0
                    ? $this->selectRootGroupsForHome($allRootGroups, $productTypes, $groupLimit)
                    : $allRootGroups->values()->all();
                $rootGroups = $allRootGroups->values()->all();

                $groupCatalogMap = $this->siteProductReadService->groupCatalogMap(
                    array_map(
                        static fn (array $group): int => (int) ($group['id'] ?? 0),
                        $catalogRootGroups
                    )
                );

                return [
                    'site_config' => $this->resolveSiteConfig(),
                    'hero' => $this->homeHeroService->getHero(),
                    'notices' => $this->transformArticles($contentOverview['notices'] ?? collect()),
                    'help_articles' => $this->transformArticles($contentOverview['help_articles'] ?? collect()),
                    'root_groups' => $this->transformRootGroups($rootGroups),
                    'group_catalog_map' => $groupCatalogMap,
                ];
            }
        );
    }

    /**
     * @return array{slides: array<int, array<string, mixed>>, features: array<int, array<string, mixed>>}
     */
    public function hero(): array
    {
        return $this->homeHeroService->getHero();
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function transformRootGroups(array $groups): array
    {
        return collect($groups)
            ->map(fn (array $group): array => [
                'id' => (int) ($group['id'] ?? 0),
                'name' => (string) ($group['name'] ?? ''),
                'slogan' => (string) ($group['slogan'] ?? ''),
                'product_count' => (int) ($group['product_count'] ?? 0),
                'product_type' => (string) ($group['product_type'] ?? ''),
                'product_type_id' => (int) ($group['product_type_id'] ?? 0),
                'product_type_label' => (string) ($group['product_type_label'] ?? ''),
                'first_product_group_code' => (string) ($group['first_product_group_code'] ?? ''),
                'first_product_group_name' => (string) ($group['first_product_group_name'] ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * 首页 tab 来自产品类型，卡片来自二级分组；优先保证每个 tab 至少有一个可展示分组。
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rootGroups
     * @param  array<int, array<string, mixed>>  $productTypes
     * @return array<int, array<string, mixed>>
     */
    private function selectRootGroupsForHome($rootGroups, array $productTypes, int $groupLimit): array
    {
        $limit = max(0, $groupLimit);
        if ($limit === 0 || $rootGroups->isEmpty()) {
            return [];
        }

        $selected = collect();
        $selectedIds = [];
        $typeValues = collect($productTypes)
            ->map(fn (array $type): string => $this->typeValue($type))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values();

        foreach ($typeValues as $typeValue) {
            if ($selected->count() >= $limit) {
                break;
            }

            $matchedGroup = $rootGroups->first(
                fn (array $group): bool => $this->groupProductType($group) === $typeValue
                    && ! isset($selectedIds[(int) ($group['id'] ?? 0)])
            );

            if (is_array($matchedGroup)) {
                $groupId = (int) ($matchedGroup['id'] ?? 0);
                $selected->push($matchedGroup);
                $selectedIds[$groupId] = true;
            }
        }

        if ($selected->count() < $limit) {
            $rootGroups
                ->reject(fn (array $group): bool => isset($selectedIds[(int) ($group['id'] ?? 0)]))
                ->take($limit - $selected->count())
                ->each(function (array $group) use ($selected, &$selectedIds): void {
                    $groupId = (int) ($group['id'] ?? 0);
                    $selected->push($group);
                    $selectedIds[$groupId] = true;
                });
        }

        return $selected->take($limit)->values()->all();
    }

    /**
     * @param  array<string, mixed>  $type
     */
    private function typeValue(array $type): string
    {
        return trim((string) ($type['first_product_group_code'] ?? $type['value'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function groupProductType(array $group): string
    {
        return trim((string) ($group['first_product_group_code'] ?? ''));
    }

    /**
     * @param  Collection<int, ContentArticle>|iterable<int, ContentArticle>  $articles
     * @return array<int, array<string, mixed>>
     */
    private function transformArticles(iterable $articles): array
    {
        return collect($articles)
            ->filter(fn ($article) => $article instanceof ContentArticle)
            ->map(function (ContentArticle $article): array {
                $excerpt = $article->summary ?: Str::limit(
                    preg_replace(
                        '/\s+/u',
                        ' ',
                        strip_tags(Str::markdown((string) $article->content))
                    ),
                    120,
                    '...'
                );

                return [
                    'id' => (int) $article->id,
                    'title' => (string) $article->title,
                    'summary' => $article->summary,
                    'excerpt' => $excerpt,
                    'cover_image' => $this->resolveCoverImageUrl($article->cover_image),
                    'category' => (string) ($article->contentCategory?->name ?: $article->category_name ?: ''),
                    'is_pinned' => (int) $article->is_pinned,
                    'is_recommended' => (int) $article->is_recommended,
                    'publish_at' => optional($article->publish_at)?->toDateTimeString(),
                    'updated_at' => optional($article->updated_at)?->toDateTimeString(),
                ];
            })
            ->values()
            ->all();
    }

    private function resolveCoverImageUrl(?string $value): ?string
    {
        return UploadUrl::resolve($value);
    }

    /**
     * @return array<string, string>
     */
    private function resolveSiteConfig(): array
    {
        return SiteConfigPayload::payload();
    }
}
