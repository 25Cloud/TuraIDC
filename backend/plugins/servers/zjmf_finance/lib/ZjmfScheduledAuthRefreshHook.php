<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use App\Models\Supplier;
use App\Services\Automation\Contracts\ScheduleHook;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Upstream\Contracts\ProvidesScheduledAuthRefresh;
use App\Services\Upstream\ProviderKey;
use App\Services\Upstream\ProviderResolver;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ZjmfScheduledAuthRefreshHook implements ScheduleHook
{
    public function __construct(
        private readonly ProviderResolver $providerResolver,
        private readonly PluginBindingResolver $bindingResolver,
    ) {}

    public function handle(string $hook, array $context = []): array
    {
        $summary = [
            'matched' => 0,
            'refreshed' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        Supplier::query()
            ->enabled()
            ->orderBy('id')
            ->get()
            ->each(function (Supplier $supplier) use (&$summary): void {
                $provider = $this->providerResolver->resolveForSupplier($supplier);
                $providerKey = trim((string) ($provider->key() ?? $provider->rawKey() ?? ''));
                if ($providerKey !== ProviderKey::ZJMF_FINANCE_API) {
                    return;
                }

                $summary['matched']++;

                if (! $provider->supports(ProvidesScheduledAuthRefresh::class)) {
                    $summary['skipped']++;

                    return;
                }

                try {
                    $provider->require(ProvidesScheduledAuthRefresh::class, '当前供应商不支持认证刷新')
                        ->refreshJwt($this->bindingResolver->supplierWithRuntimeCredentials($supplier));
                    $summary['refreshed']++;
                } catch (Throwable $exception) {
                    $summary['failed']++;

                    Log::error('[定时任务] ZJMF 财务认证刷新失败', [
                        'supplier_id' => $supplier->id,
                        'provider_key' => ProviderKey::ZJMF_FINANCE_API,
                        'error' => $exception->getMessage(),
                        'exception' => $exception::class,
                    ]);
                }
            });

        Log::info('[定时任务] ZJMF 财务认证刷新执行完成', $summary);

        return $summary;
    }
}
