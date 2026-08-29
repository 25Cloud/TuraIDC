<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/**
 * 插件市场：拉取索引、按 slug 定位条目、下载审核锁定版本的插件包并安装。
 *
 * 分发全部依赖 GitHub：索引是 raw 上的 plugins.json，插件代码是 GitHub archive
 * （仅下载索引条目里 tag / sha 固定引用的版本，防止审核后内容被替换），
 * 国内访问可通过配置的加速镜像前缀（config/plugins.php）解决。
 */
class PluginMarketService
{
    private const INDEX_CACHE_KEY = 'plugin_market_index_v1';

    private const INDEX_CACHE_TTL = 300;

    /** 插件包解压后总大小上限（zip 炸弹防护）。 */
    private const MAX_EXTRACTED_BYTES = 100 * 1024 * 1024;

    public function __construct(
        private readonly IntegrationPluginService $pluginService,
    ) {}

    /**
     * 拉取（并缓存）市场索引。失败时抛连接/格式异常。
     *
     * @return array{schema: int, updated_at: string, plugins: list<array<string, mixed>>}
     */
    public function fetchIndex(bool $force = false): array
    {
        if (! $force && Cache::has(self::INDEX_CACHE_KEY)) {
            return (array) Cache::get(self::INDEX_CACHE_KEY);
        }

        $url = (string) config('plugins.market.index_url');
        $mirror = (string) config('plugins.market.raw_mirror', '');
        $response = Http::timeout($this->timeout())->get($mirror.$url);
        $response->throw();

        $payload = $response->json();
        if (! is_array($payload) || ($payload['schema'] ?? null) !== 1 || ! is_array($payload['plugins'] ?? null)) {
            throw new BusinessException('插件市场索引格式非法，请检查索引仓库', 42200);
        }

        $index = [
            'schema' => 1,
            'updated_at' => (string) ($payload['updated_at'] ?? ''),
            'plugins' => $this->normalizeEntries($payload['plugins']),
        ];

        Cache::put(self::INDEX_CACHE_KEY, $index, self::INDEX_CACHE_TTL);

        return $index;
    }

    /**
     * 返回市场可安装插件条目列表。
     *
     * @return list<array<string, mixed>>
     */
    public function list(bool $force = false): array
    {
        return $this->fetchIndex($force)['plugins'];
    }

    /**
     * @return array<string, mixed>
     */
    public function findEntry(string $slug): array
    {
        foreach ($this->fetchIndex()['plugins'] as $entry) {
            if ($entry['slug'] === $slug) {
                return $entry;
            }
        }

        throw new BusinessException("市场索引中不存在插件：{$slug}", 40400);
    }

    /**
     * 安装市场插件。
     *
     * - 未传 $zipPath：从索引条目下载 tag/sha 固定引用的 archive 安装；
     * - 传 $zipPath：手动加载本地插件包（zip），slug 取包内 manifest 并与参数比对。
     *
     * @return array{plugin: array<string, mixed>, entry: array<string, mixed>|null}
     */
    public function install(string $slug, ?string $zipPath = null, bool $force = false): array
    {
        $entry = $zipPath === null ? $this->findEntry($slug) : null;
        $workDir = $this->workDir();
        $this->ensureDirectory($workDir);

        $archive = null;
        $extractDir = null;
        $registered = null;

        try {
            $archive = $zipPath === null
                ? $this->downloadArchive((array) $entry, $slug, $workDir)
                : $this->copyLocalZip((string) $zipPath, $slug, $workDir);

            $extractDir = $workDir.DIRECTORY_SEPARATOR.'tmp-'.bin2hex(random_bytes(6));
            $this->extractZip($archive, $extractDir);

            if ($entry !== null && trim((string) ($entry['sha'] ?? '')) !== '') {
                $this->assertArchiveTopMatches($extractDir, (string) $entry['repo'], (string) $entry['sha'], $slug);
            }

            $pluginRoot = $this->locatePluginRoot($extractDir);
            $manifest = $this->readManifest($pluginRoot, $slug);
            $destDir = $this->pluginDestination($manifest['domain'], $slug);

            if (is_dir($destDir) && ! $force) {
                throw new BusinessException("插件目录已存在：{$destDir}（加 --force 覆盖）", 42200);
            }

            $this->moveIntoPlace($pluginRoot, $destDir);
            $registered = $this->pluginService->install($manifest['domain'], $slug);
        } finally {
            if ($archive !== null) {
                @unlink($archive);
            }
            if ($extractDir !== null) {
                $this->removeDirectory($extractDir);
            }
        }

        return [
            'plugin' => $registered,
            'entry' => $entry,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function downloadArchive(array $entry, string $slug, string $workDir): string
    {
        $repo = trim((string) ($entry['repo'] ?? ''));
        $tag = trim((string) ($entry['tag'] ?? ''));
        $sha = trim((string) ($entry['sha'] ?? ''));

        if ($repo === '' || ! str_contains($repo, '/')) {
            throw new BusinessException("插件条目缺少合法的 repo：{$slug}", 42200);
        }
        if ($tag === '' && $sha === '') {
            throw new BusinessException("插件条目缺少 tag/sha：{$slug}", 42200);
        }

        // sha 优先：审核锁定到具体 commit；否则锁定到 tag。
        $ref = $sha !== '' ? $sha : 'refs/tags/'.$tag;
        $url = str_replace(
            ['{repo}', '{ref}'],
            [$repo, $ref],
            (string) config('plugins.market.archive_zip_url')
        );
        $mirror = (string) config('plugins.market.download_mirror', '');

        $response = Http::timeout($this->timeout())->get($mirror.$url);
        $response->throw();

        $zipPath = $workDir.DIRECTORY_SEPARATOR.$slug.'-'.($sha !== '' ? $sha : $tag).'.zip';
        file_put_contents($zipPath, $response->body());

        return $zipPath;
    }

    private function copyLocalZip(string $zipPath, string $slug, string $workDir): string
    {
        if (! is_file($zipPath)) {
            throw new BusinessException("本地插件包不存在：{$zipPath}", 40400);
        }

        $target = $workDir.DIRECTORY_SEPARATOR.$slug.'-local-'.bin2hex(random_bytes(4)).'.zip';
        if (! copy($zipPath, $target)) {
            throw new BusinessException('无法读取本地插件包', 42200);
        }

        return $target;
    }

    private function extractZip(string $zipPath, string $destDir): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new BusinessException('插件包不是有效的 zip 文件', 42200);
        }

        try {
            $totalBytes = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $normalized = str_replace('\\', '/', $name);
                if (str_starts_with($normalized, '/') || preg_match('/(^|\/)\.\.(\/|$)/', $normalized) === 1) {
                    throw new BusinessException("插件包含非法路径：{$name}", 42200);
                }

                $stat = $zip->statIndex($i);
                $totalBytes += (int) ($stat['size'] ?? 0);
            }

            if ($totalBytes > self::MAX_EXTRACTED_BYTES) {
                throw new BusinessException('插件包解压后体积超过 100MB 上限', 42200);
            }

            $this->ensureDirectory($destDir);
            $zip->extractTo($destDir);
        } finally {
            $zip->close();
        }
    }

    /**
     * sha 模式强校验：GitHub archive 顶层目录名为 {repo-basename}-{sha}，
     * 确保下载的就是审核锁定的 commit。
     */
    private function assertArchiveTopMatches(string $extractDir, string $repo, string $sha, string $slug): void
    {
        $expected = str_contains($repo, '/') ? substr($repo, (int) strrpos($repo, '/') + 1) : $repo;
        $expected .= '-'.$sha;

        $top = new RecursiveDirectoryIterator($extractDir, RecursiveDirectoryIterator::SKIP_DOTS);
        foreach ($top as $item) {
            /** @var SplFileInfo $item */
            if (! $item->isDir()) {
                continue;
            }
            if ($item->getFilename() === $expected) {
                return;
            }
        }

        throw new BusinessException("插件包与审核锁定的 commit（{$sha}）不一致，已拒绝安装：{$slug}", 42200);
    }

    private function locatePluginRoot(string $extractDir): string
    {
        $candidates = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extractDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->getFilename() !== 'config.php') {
                continue;
            }
            $candidates[] = $file->getPath();
        }

        if ($candidates === []) {
            throw new BusinessException('插件包缺少 config.php，不是有效的插件包', 42200);
        }

        // 深度最小者作为插件根目录（GitHub archive 顶层为 {repo}-{ref}）。
        usort($candidates, fn (string $a, string $b): int => substr_count($a, DIRECTORY_SEPARATOR) <=> substr_count($b, DIRECTORY_SEPARATOR));

        return $candidates[0];
    }

    /**
     * @return array{domain: string, info: array<string, mixed>}
     */
    private function readManifest(string $pluginRoot, string $slug): array
    {
        $manifest = require $pluginRoot.DIRECTORY_SEPARATOR.'config.php';
        $info = is_array($manifest['info'] ?? null) ? $manifest['info'] : [];
        $domain = trim((string) ($info['domain'] ?? ''));
        $manifestSlug = trim((string) ($info['slug'] ?? ''));

        try {
            PluginDomain::assertValid($domain);
        } catch (InvalidArgumentException) {
            throw new BusinessException("插件 domain 非法：{$domain}", 42200);
        }

        if ($manifestSlug !== $slug) {
            throw new BusinessException("插件包 slug（{$manifestSlug}）与安装目标（{$slug}）不一致", 42200);
        }

        return [
            'domain' => $domain,
            'info' => $info,
        ];
    }

    private function pluginDestination(string $domain, string $slug): string
    {
        return base_path(
            'plugins'.DIRECTORY_SEPARATOR.PluginDomain::directoryName($domain).DIRECTORY_SEPARATOR.$slug
        );
    }

    private function moveIntoPlace(string $pluginRoot, string $destDir): void
    {
        if (is_dir($destDir)) {
            $this->removeDirectory($destDir);
        }

        $parent = dirname($destDir);
        $this->ensureDirectory($parent);

        if (! @rename($pluginRoot, $destDir)) {
            throw new BusinessException("无法移动插件到目标目录：{$destDir}", 42200);
        }
    }

    /**
     * 校验索引条目字段并补齐默认值。
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function normalizeEntries(array $entries): array
    {
        $normalized = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $slug = trim((string) ($entry['slug'] ?? ''));
            $domain = trim((string) ($entry['domain'] ?? ''));
            if ($slug === '' || $domain === '') {
                continue;
            }
            $normalized[] = [
                'slug' => $slug,
                'domain' => $domain,
                'name' => (string) ($entry['name'] ?? $slug),
                'description' => (string) ($entry['description'] ?? ''),
                'developer' => (string) ($entry['developer'] ?? ''),
                'repo' => (string) ($entry['repo'] ?? ''),
                'tag' => (string) ($entry['tag'] ?? ''),
                'sha' => (string) ($entry['sha'] ?? ''),
                'released_at' => (string) ($entry['released_at'] ?? ''),
                'license' => (string) ($entry['license'] ?? ''),
                'homepage' => (string) ($entry['homepage'] ?? ''),
            ];
        }

        return $normalized;
    }

    private function workDir(): string
    {
        $relative = trim((string) config('plugins.market.work_dir', 'plugin-market'), '/\\');

        return storage_path('app/private'.DIRECTORY_SEPARATOR.$relative);
    }

    private function timeout(): int
    {
        return max(5, (int) config('plugins.market.timeout', 30));
    }

    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
