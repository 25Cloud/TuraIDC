<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginInstaller;
use App\Services\Integrations\Plugins\UpstreamBindingWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 上游同步（幂等）：从旧库 shd_zjmf_finance_api 同步供应商主数据，
 * 解密 API 密钥并写入 supplier_plugin_bindings；从 shd_products 的
 * zjmf_api_id / upstream_pid 建立产品级上游绑定。
 *
 * DES-CBC 解密说明：PHP 8.3 / OpenSSL 3 已移除单 DES，
 * 本命令通过 openssl CLI（--provider legacy）解密；无 CLI 时可用
 * --api-keys-file 传入预解密明文（JSON：{"api_id": "明文key"}）。
 */
class SyncUpstreamsCommand extends Command
{
    protected $signature = 'app:sync-upstreams
        {--api-keys-file= : 预解密好的明文密钥 JSON 文件（{"api_id":"明文"}）}
        {--skip-products : 只同步供应商，不同步产品级绑定}
        {--dry-run : 只统计，不写入数据库}
        {--json : 以 JSON 输出结果}';

    protected $description = '从旧库同步上游供应商并解密密钥、建立产品绑定';

    public function handle(UpstreamBindingWriter $bindingWriter, PluginInstaller $pluginInstaller): int
    {
        $sourceConnection = (string) config('catalog_migration.source_connection', 'mysql');
        $dryRun = (bool) $this->option('dry-run');
        $json = (bool) $this->option('json');
        $skipProducts = (bool) $this->option('skip-products');

        if (! DB::connection($sourceConnection)->getSchemaBuilder()->hasTable('shd_zjmf_finance_api')) {
            $this->error('旧库不存在 shd_zjmf_finance_api');

            return self::FAILURE;
        }

        $decryptedKeys = $this->loadDecryptedKeys((string) $this->option('api-keys-file'));

        // 0. 确保 zjmf_finance 插件已安装启用（provider_key 解析依赖 integration_plugins）
        if (! $dryRun) {
            try {
                $plugin = $pluginInstaller->install('upstream', 'zjmf_finance');
                $pluginInstaller->enable($plugin);
            } catch (\Throwable $exception) {
                $this->warn("zjmf_finance 插件安装/启用失败：{$exception->getMessage()}");
            }
        }

        $rows = DB::connection($sourceConnection)
            ->table('shd_zjmf_finance_api')
            ->orderBy('id')
            ->get();

        $syncedSuppliers = 0;
        $skippedSuppliers = 0;

        foreach ($rows as $row) {
            $apiId = (int) ($row->id ?? 0);
            $name = trim((string) ($row->name ?? $row->api_name ?? ''));
            $baseUrl = $this->normalizeBaseUrl((string) ($row->api_url ?? $row->base_url ?? ''));
            $accountName = trim((string) ($row->account ?? $row->username ?? $row->account_name ?? ''));
            $encryptedPassword = trim((string) ($row->password ?? $row->api_key ?? ''));

            if ($apiId <= 0 || $baseUrl === '') {
                $skippedSuppliers++;
                continue;
            }

            $apiKey = $decryptedKeys[(string) $apiId]
                ?? $this->decryptDesKey($encryptedPassword)
                ?? ($encryptedPassword !== '' ? $encryptedPassword : null);

            $this->info("供应商 #{$apiId} {$name}（{$baseUrl}）".($apiKey !== null ? '密钥已就绪' : '密钥缺失'));

            if ($dryRun) {
                $syncedSuppliers++;
                continue;
            }

            $supplier = Supplier::query()->updateOrCreate(
                ['code' => "zjmf_api_{$apiId}"],
                [
                    'name' => $name !== '' ? $name : "ZJMF #{$apiId}",
                    'website' => $baseUrl,
                    'status' => 1,
                    'sort_order' => $apiId,
                ]
            );

            $bindingWriter->syncSupplierBinding($supplier, [
                'provider_key' => 'zjmf_finance',
                'base_url' => $baseUrl,
                'account_name' => $accountName !== '' ? $accountName : null,
                'api_key' => $apiKey,
                'status' => 1,
            ]);
            $syncedSuppliers++;
        }

        $syncedProducts = 0;
        if (! $skipProducts) {
            $syncedProducts = $this->syncProductBindings($sourceConnection, $bindingWriter, $dryRun);
        }

        if ($json) {
            $this->line(json_encode([
                'suppliers' => $syncedSuppliers,
                'skipped_suppliers' => $skippedSuppliers,
                'products' => $syncedProducts,
                'dry_run' => $dryRun,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info($dryRun ? '=== 上游同步预检（--dry-run）===' : '=== 上游同步完成 ===');
        $this->line('供应商: '.$syncedSuppliers);
        $this->line('跳过: '.$skippedSuppliers);
        $this->line('产品绑定: '.$syncedProducts);

        return self::SUCCESS;
    }

    private function syncProductBindings(string $sourceConnection, UpstreamBindingWriter $bindingWriter, bool $dryRun): int
    {
        $sourceSchema = DB::connection($sourceConnection)->getSchemaBuilder();
        if (! $sourceSchema->hasTable('shd_products')
            || ! $sourceSchema->hasColumn('shd_products', 'zjmf_api_id')
            || ! $sourceSchema->hasColumn('shd_products', 'upstream_pid')) {
            $this->warn('旧库 shd_products 缺少 zjmf_api_id / upstream_pid 列，跳过产品绑定');

            return 0;
        }

        $rows = DB::connection($sourceConnection)
            ->table('shd_products')
            ->select(['id', 'zjmf_api_id', 'upstream_pid'])
            ->whereNotNull('zjmf_api_id')
            ->where('zjmf_api_id', '<>', '')
            ->whereNotNull('upstream_pid')
            ->where('upstream_pid', '<>', '')
            ->orderBy('id')
            ->get();

        $synced = 0;
        foreach ($rows as $row) {
            $product = Product::query()->find((int) $row->id);
            if (! $product instanceof Product) {
                continue;
            }

            $supplier = Supplier::query()
                ->where('code', "zjmf_api_{$row->zjmf_api_id}")
                ->first();
            if (! $supplier instanceof Supplier) {
                continue;
            }

            if (! $dryRun) {
                $bindingWriter->syncProductBinding($product, $supplier, (string) $row->upstream_pid);
            }
            $synced++;
        }

        return $synced;
    }

    /**
     * @return array<string, string>
     */
    private function loadDecryptedKeys(string $filePath): array
    {
        $keys = [];
        if ($filePath === '' || ! is_file($filePath)) {
            return $keys;
        }

        $decoded = json_decode((string) file_get_contents($filePath), true);
        if (! is_array($decoded)) {
            return $keys;
        }

        foreach ($decoded as $apiId => $key) {
            $keys[(string) $apiId] = trim((string) $key);
        }

        return $keys;
    }

    /**
     * DES-CBC 解密（key/IV = md5("shundai") 前 8 字节）。
     * PHP 8.3 / OpenSSL 3 移除了单 DES，这里优先调用 openssl CLI（legacy provider）。
     */
    private function decryptDesKey(string $encrypted): ?string
    {
        $encrypted = trim($encrypted);
        if ($encrypted === '') {
            return null;
        }

        $key = substr(md5('shundai'), 0, 8);
        $hexKey = bin2hex($key);

        $command = sprintf(
            'printf %%s %s | openssl enc -d -des-cbc -provider legacy -provider default -K %s -iv %s -nopad 2>/dev/null',
            escapeshellarg($encrypted),
            $hexKey,
            $hexKey,
        );

        $output = null;
        $exitCode = 1;
        @exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            return null;
        }

        $decoded = base64_decode($encrypted, true);
        $padding = ord(substr((string) $decoded, -1));
        $plain = implode('', $output);
        // openssl -nopad 输出原始字节；正常场景密文带 PKCS#7 填充，此处去掉尾部 0x01-0x10 填充
        if (is_string($decoded) && $decoded !== '' && $padding >= 1 && $padding <= 16) {
            $plain = substr($plain, 0, max(0, strlen($plain) - $padding));
        }

        return trim($plain) !== '' ? $plain : null;
    }

    private function normalizeBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            return '';
        }

        // 生产环境强制 HTTPS；文档记录 http:// 会被拒绝
        if (! str_starts_with($url, 'https://') && str_starts_with($url, 'http://')) {
            $this->warn("上游地址为 http，建议改为 https：{$url}");
        }

        return $url;
    }
}
