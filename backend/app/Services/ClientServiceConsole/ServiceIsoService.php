<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\System\OperationLogService;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ISO 挂载与启动项子服务（控制台）。
 *
 * 面板一次给全三件事：可选镜像清单、当前已挂载的镜像、当前启动项。
 * 挂载/卸载本身不会重启实例——是否重启由用户自己决定，所以返回文案里必须写「重启后生效」，
 * 不能让用户以为挂上就进安装界面了。
 *
 * 三态含义同 {@see ServiceSnapshotService}。
 */
class ServiceIsoService
{
    /** 允许的启动项取值：硬盘优先 / 光驱优先 */
    private const BOOT_ORDERS = ['disk', 'cdrom'];

    private const BOOT_ORDER_LABELS = [
        'disk' => '硬盘优先',
        'cdrom' => '光驱优先',
    ];

    public function __construct(
        private readonly OperationLogService $operationLogService,
        private readonly ServiceDetailService $detailService,
        private readonly ServiceTransformService $transformService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getIsoPanelForUser(User $user, int $serviceId): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, ['product.supplier']);

        if (! $this->transformService->canManageService($service)) {
            return $this->degraded('当前服务暂不支持 ISO 挂载');
        }

        try {
            [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
        } catch (Throwable $exception) {
            return $this->failed($service->id, $exception, '读取 ISO 列表失败，请稍后重试');
        }

        if (! is_callable([$runtime, 'getIsoImages'])) {
            return $this->degraded('当前上游不提供 ISO 挂载能力');
        }

        try {
            $response = $runtime->getIsoImages($supplier, (int) $hostId, $jwt);
            $this->detailService->assertSuccess($response, '读取 ISO 列表');
            $payload = $this->detailService->extractPayload($response);
        } catch (Throwable $exception) {
            return $this->failed($service->id, $exception, '读取 ISO 列表失败，请稍后重试');
        }

        $images = [];
        foreach ((array) ($payload['list'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $isoId = trim((string) ($row['id'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($isoId === '' || $name === '') {
                continue;
            }

            $images[] = ['id' => $isoId, 'name' => $name];
        }

        $currentIso = trim((string) ($payload['current_iso'] ?? ''));
        $bootorder = trim((string) ($payload['bootorder'] ?? ''));
        $canMount = is_callable([$runtime, 'mountIso']);
        $canUnmount = is_callable([$runtime, 'unmountIso']) && $currentIso !== '';

        return [
            'supported' => true,
            'message' => $images === [] ? '当前节点没有可挂载的 ISO' : '',
            'error' => '',
            'can_mount' => $canMount && $images !== [],
            'can_unmount' => $canUnmount,
            'can_set_bootorder' => is_callable([$runtime, 'setBootOrder']),
            'current_iso' => $currentIso,
            'current_iso_name' => $this->resolveIsoName($images, $currentIso),
            'bootorder' => $bootorder,
            'bootorder_label' => self::BOOT_ORDER_LABELS[$bootorder] ?? $bootorder,
            'bootorder_options' => [
                ['value' => 'disk', 'label' => self::BOOT_ORDER_LABELS['disk']],
                ['value' => 'cdrom', 'label' => self::BOOT_ORDER_LABELS['cdrom']],
            ],
            'images' => $images,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  `iso_id`
     * @return array<string, mixed>
     */
    public function mountIsoForUser(User $user, int $serviceId, array $data, array $context = []): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, ['product.supplier']);
        $isoId = trim((string) ($data['iso_id'] ?? ''));

        throw_if($isoId === '', new BusinessException('请选择要挂载的 ISO', 42200));

        [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
        throw_if(! is_callable([$runtime, 'mountIso']), new BusinessException('当前上游不提供 ISO 挂载能力', 42200));

        $response = $runtime->mountIso($supplier, (int) $hostId, $isoId, $jwt);
        $this->detailService->assertSuccess($response, '挂载 ISO');

        $message = trim((string) ($response['msg'] ?? '')) ?: 'ISO 已挂载，重启实例后生效';

        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.iso.mount', [
            'category' => 'iso',
            'summary' => '挂载 ISO '.$isoId,
            'host_id' => (int) $hostId,
            'iso_id' => $isoId,
            'message' => $message,
        ], $context);

        return ['message' => $message];
    }

    /**
     * @return array<string, mixed>
     */
    public function unmountIsoForUser(User $user, int $serviceId, array $context = []): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, ['product.supplier']);

        [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
        throw_if(! is_callable([$runtime, 'unmountIso']), new BusinessException('当前上游不提供 ISO 卸载能力', 42200));

        $response = $runtime->unmountIso($supplier, (int) $hostId, $jwt);
        $this->detailService->assertSuccess($response, '卸载 ISO');

        $message = trim((string) ($response['msg'] ?? '')) ?: 'ISO 已卸载';

        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.iso.unmount', [
            'category' => 'iso',
            'summary' => '卸载 ISO',
            'host_id' => (int) $hostId,
            'message' => $message,
        ], $context);

        return ['message' => $message];
    }

    /**
     * @param  array<string, mixed>  $data  `bootorder`（disk|cdrom）
     * @return array<string, mixed>
     */
    public function setBootOrderForUser(User $user, int $serviceId, array $data, array $context = []): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, ['product.supplier']);
        $order = strtolower(trim((string) ($data['bootorder'] ?? '')));

        throw_if($order === '', new BusinessException('请选择启动项', 42200));
        throw_if(
            ! in_array($order, self::BOOT_ORDERS, true),
            new BusinessException('启动项只能是硬盘优先或光驱优先', 42200)
        );

        [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
        throw_if(! is_callable([$runtime, 'setBootOrder']), new BusinessException('当前上游不提供启动项设置', 42200));

        $response = $runtime->setBootOrder($supplier, (int) $hostId, $order, $jwt);
        $this->detailService->assertSuccess($response, '设置启动项');

        $message = trim((string) ($response['msg'] ?? '')) ?: '启动项已更新为'.self::BOOT_ORDER_LABELS[$order];

        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.iso.bootorder', [
            'category' => 'iso',
            'summary' => '启动项改为'.self::BOOT_ORDER_LABELS[$order],
            'host_id' => (int) $hostId,
            'bootorder' => $order,
            'bootorder_label' => self::BOOT_ORDER_LABELS[$order],
            'message' => $message,
        ], $context);

        return ['message' => $message];
    }

    /**
     * @param  array<int, array{id:string, name:string}>  $images
     */
    private function resolveIsoName(array $images, string $isoId): string
    {
        if ($isoId === '') {
            return '';
        }

        foreach ($images as $image) {
            if ($image['id'] === $isoId) {
                return $image['name'];
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function degraded(string $message): array
    {
        return [
            'supported' => false,
            'message' => $message,
            'error' => '',
            'can_mount' => false,
            'can_unmount' => false,
            'can_set_bootorder' => false,
            'current_iso' => '',
            'current_iso_name' => '',
            'bootorder' => '',
            'bootorder_label' => '',
            'bootorder_options' => [],
            'images' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failed(int $serviceId, Throwable $exception, string $fallback): array
    {
        Log::warning('[服务控制台] 读取 ISO 失败', [
            'service_id' => $serviceId,
            'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
        ]);

        return [
            'supported' => true,
            'message' => '',
            'error' => $fallback,
            'can_mount' => false,
            'can_unmount' => false,
            'can_set_bootorder' => false,
            'current_iso' => '',
            'current_iso_name' => '',
            'bootorder' => '',
            'bootorder_label' => '',
            'bootorder_options' => [],
            'images' => [],
        ];
    }
}
