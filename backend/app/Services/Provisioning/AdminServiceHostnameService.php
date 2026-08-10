<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Services\System\OperationLogService;
use App\Services\System\SettingService;
use App\Support\ServiceHostname;
use Illuminate\Support\Facades\DB;

class AdminServiceHostnameService
{
    public function __construct(
        private readonly SettingService $settingService,
        private readonly OperationLogService $operationLogService,
    ) {}

    public function batchUpdateCustomHostnames(array $data, array $context = []): array
    {
        $items = collect((array) ($data['items'] ?? []))
            ->map(fn (array $item) => [
                'service_id' => (int) ($item['service_id'] ?? 0),
                'hostname' => trim((string) ($item['hostname'] ?? '')),
            ])
            ->filter(fn (array $item) => $item['service_id'] > 0)
            ->values();

        throw_if($items->isEmpty(), new BusinessException('请选择需要设置主机名的服务'));

        $serviceIds = $items->pluck('service_id')->values()->all();
        $services = Service::query()
            ->with(['product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires', 'order:id,order_no'])
            ->whereIn('id', $serviceIds)
            ->get()
            ->keyBy('id');

        throw_if(
            $services->count() !== count($serviceIds),
            new BusinessException('存在无效的服务记录，请刷新后重试')
        );

        $resultItems = [];

        DB::transaction(function () use ($items, $services, $context, &$resultItems): void {
            foreach ($items as $item) {
                $service = $services->get($item['service_id']);
                if (! $service instanceof Service) {
                    continue;
                }

                $rawHostname = (string) $item['hostname'];
                $normalizedHostname = $rawHostname !== ''
                    ? $this->settingService->normalizeHostname($rawHostname, true)
                    : '';

                if ($rawHostname !== '' && $normalizedHostname === '') {
                    throw new BusinessException("服务 #{$service->id} 的主机名格式无效");
                }

                $provisionData = (array) ($service->provision_data ?? []);
                $previousHostname = ServiceHostname::custom($provisionData);

                if ($previousHostname === $normalizedHostname) {
                    $resultItems[] = [
                        'service_id' => (int) $service->id,
                        'custom_hostname' => $normalizedHostname,
                        'updated' => false,
                    ];

                    continue;
                }

                $updatedProvisionData = ServiceHostname::writeCustomHostname($provisionData, $normalizedHostname, $context);

                $service->forceFill([
                    'provision_data' => $updatedProvisionData,
                ])->save();

                $this->operationLogService->writeServiceConsoleLog($service, 'service.console.hostname.update', [
                    'category' => 'service',
                    'summary' => $normalizedHostname !== '' ? '设置自定义主机名' : '清空自定义主机名',
                    'hostname' => $normalizedHostname,
                    'previous_hostname' => $previousHostname,
                ], [
                    'actor_type' => 'admin',
                    'actor_user_id' => (int) (($context['operator_id'] ?? 0) ?: 0),
                    'actor_name' => (string) ($context['operator_name'] ?? ''),
                    'ip_address' => (string) ($context['ip_address'] ?? ''),
                    'trace_id' => (string) ($context['trace_id'] ?? ''),
                ]);

                $resultItems[] = [
                    'service_id' => (int) $service->id,
                    'custom_hostname' => $normalizedHostname,
                    'updated' => true,
                ];
            }
        });

        $updatedCount = collect($resultItems)->where('updated', true)->count();

        return [
            'updated_count' => $updatedCount,
            'unchanged_count' => max(count($resultItems) - $updatedCount, 0),
            'items' => $resultItems,
        ];
    }
}
