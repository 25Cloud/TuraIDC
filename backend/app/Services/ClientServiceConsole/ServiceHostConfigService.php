<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\System\OperationLogService;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Log;

/**
 * 实例规格变更（管理端）。
 *
 * 为什么只在管理端开放：改规格会改变上游计费与资源占用，但本平台没有「按配置项补差价」的
 * 订单模型（魔方云那边也没有套餐概念，实例就是一组显式的 cpu/内存/磁盘/带宽）。
 * 所以这里只提供一个**管理员手工改配**的入口，计费口径由运营在订单侧自行处理，
 * 不放进用户控制台，避免出现「用户自己免费升配」的口子。
 *
 * 上游能力名固定为 `changeHostConfig(Supplier, int $hostId, array $params, ?string $jwt)`，
 * 键与「未指定」语义见 `MofangCloudAdapter::changeHostConfig()`。
 */
class ServiceHostConfigService
{
    /**
     * 允许管理员提交的字段白名单，值为 [最小, 最大]。
     *
     * 上限不是上游限制的精确复刻，而是「明显不合理的值不进请求」的护栏；
     * 真正的约束仍由上游判定，超限时报错原文会透给管理员。
     */
    private const FIELD_RULES = [
        'cpu' => [1, 256],
        'memory' => [128, 1048576],
        'in_bw' => [1, 100000],
        'out_bw' => [1, 100000],
        'flow' => [1, 10485760],
        'ip_num' => [1, 253],
        'ipv6_num' => [1, 253],
        'disk_size' => [1, 65536],
        'disk_id' => [1, PHP_INT_MAX],
        'snap_num' => [0, 1024],
        'backup_num' => [0, 1024],
    ];

    public function __construct(
        private readonly OperationLogService $operationLogService,
        private readonly ServiceDetailService $detailService,
    ) {}

    /**
     * 变更实例规格。
     *
     * @param  array<string, mixed>  $data  见 {@see self::FIELD_RULES} 的白名单，另加 `ip_group`
     * @return array<string, mixed> `['message' => string, 'applied' => array<int, string>, 'detail' => array]`
     */
    public function changeConfigForService(User $user, int $serviceId, array $data, array $context = []): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, ['product.supplier']);

        $params = $this->normalizeParams($data);
        if ($params === []) {
            throw new BusinessException('请至少填写一项要变更的规格', 42200);
        }

        [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);

        throw_if(
            ! is_callable([$runtime, 'changeHostConfig']),
            new BusinessException('当前上游不支持在线变更规格，请到上游面板操作', 42200)
        );

        try {
            $response = $runtime->changeHostConfig($supplier, (int) $hostId, $params, $jwt);
        } catch (BusinessException $exception) {
            Log::warning('[服务控制台] 变更规格失败', [
                'service_id' => (int) $service->id,
                'params' => $params,
                'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
            ]);

            throw $exception;
        }

        $this->detailService->assertSuccess($response, '变更实例规格');
        $payload = $this->detailService->extractPayload($response);
        $detail = is_array($payload['host_detail'] ?? null) ? $payload['host_detail'] : [];
        $applied = is_array($payload['applied'] ?? null)
            ? array_values(array_filter($payload['applied'], 'is_string'))
            : [];

        if ($detail !== []) {
            $this->detailService->syncServiceFromRemote($service, $detail);
        }

        $message = trim((string) ($response['msg'] ?? '')) ?: '规格变更已提交';

        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.host_config.change', [
            'category' => 'host_config',
            'summary' => '变更实例规格',
            'host_id' => (int) $hostId,
            'applied' => $applied,
            'params' => $params,
            'message' => $message,
        ], $context);

        return [
            'message' => $message,
            'applied' => $applied,
            'detail' => $detail,
        ];
    }

    /**
     * 白名单 + 范围校验后的入参。
     *
     * 空值（`null` / `''` / 未提交）一律剔除：调用方不必区分「没传」与「传了空」，
     * 也别指望传 0 能把某项改回 0——那是「不动这一项」，不是「清零」。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int|string>
     */
    private function normalizeParams(array $data): array
    {
        $params = [];

        foreach (self::FIELD_RULES as $field => [$min, $max]) {
            $value = $data[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            if (! is_numeric($value)) {
                throw new BusinessException('规格项「'.$this->fieldLabel($field).'」必须是数字', 42200);
            }

            $number = (int) $value;
            if ($number < $min || $number > $max) {
                throw new BusinessException(
                    '规格项「'.$this->fieldLabel($field).'」必须在 '.$min.' - '.$max.' 之间',
                    42200
                );
            }

            if ($number === 0 && ! in_array($field, ['snap_num', 'backup_num'], true)) {
                continue;
            }

            $params[$field] = $number;
        }

        $ipGroup = $data['ip_group'] ?? null;
        if (is_scalar($ipGroup) && trim((string) $ipGroup) !== '') {
            $params['ip_group'] = trim((string) $ipGroup);
        }

        return $params;
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'cpu' => 'CPU 核数',
            'memory' => '内存（MB）',
            'in_bw' => '入方向带宽（Mbps）',
            'out_bw' => '出方向带宽（Mbps）',
            'flow' => '流量上限（GB）',
            'ip_num' => '附加 IPv4 数量',
            'ipv6_num' => '附加 IPv6 数量',
            'disk_size' => '数据盘容量（GB）',
            'disk_id' => '数据盘 ID',
            'snap_num' => '快照配额',
            'backup_num' => '备份配额',
            default => $field,
        };
    }
}
