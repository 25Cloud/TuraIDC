<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\ZjmfBridge\Services;

use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Models\User;

class ZjmfServiceService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function hosts(User $user, array $filters): array
    {
        $paginator = Service::query()
            ->with(['product:id,product_type,service_type_code', 'invoice:id,invoice_no,status,amount'])
            ->where('user_id', (int) $user->id)
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($query) => $query->where('status', (int) $filters['status']))
            ->orderByDesc('id')
            ->paginate($this->pageSize($filters, 20, 100), ['*'], 'page', $this->page($filters));

        return [
            'list' => collect($paginator->items())
                ->filter(fn (mixed $service): bool => $service instanceof Service)
                ->map(fn (Service $service): array => $this->servicePayload($service))
                ->values()
                ->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function host(User $user, int $serviceId): array
    {
        return [
            'host' => $this->servicePayload($this->findService($user, $serviceId), includeDetail: true),
        ];
    }

    private function findService(User $user, int $serviceId): Service
    {
        $service = Service::query()
            ->with(['product:id,product_type,service_type_code', 'invoice:id,invoice_no,status,amount'])
            ->where('user_id', (int) $user->id)
            ->find($serviceId);

        if (! $service instanceof Service) {
            throw new BusinessException('服务不存在', 40400, 404);
        }

        return $service;
    }

    /**
     * @return array<string, mixed>
     */
    private function servicePayload(Service $service, bool $includeDetail = false): array
    {
        $provisionData = $this->removeSensitiveKeys($service->provision_data ?? []);
        $payload = [
            'id' => (int) $service->id,
            'hostid' => (int) $service->id,
            'name' => (string) ($service->name ?? ''),
            'domain' => (string) ($service->domain ?? ''),
            'product_id' => (int) ($service->product_id ?? 0),
            'order_id' => (int) ($service->order_id ?? 0),
            'invoice_id' => (int) ($service->invoice_id ?? 0),
            'invoice_no' => (string) ($service->invoice?->invoice_no ?? ''),
            'product_type' => (string) ($service->product?->product_type ?? ''),
            'billing_cycle' => (string) ($service->billing_cycle ?? ''),
            'amount' => $this->money($service->amount ?? 0),
            'status' => (int) $service->status,
            'status_label' => $this->statusLabel((int) $service->status),
            'auto_renew' => (int) ($service->auto_renew ?? 0),
            'expires_at' => $service->expires_at?->format('Y-m-d H:i:s'),
            'created_at' => $service->created_at?->format('Y-m-d H:i:s'),
            'ip' => (string) ($provisionData['ip'] ?? $provisionData['main_ip'] ?? $provisionData['server_ip'] ?? ''),
        ];

        if ($includeDetail) {
            $payload['locked_pricing'] = $this->removeSensitiveKeys($service->locked_pricing ?? []);
            $payload['provision_data'] = $provisionData;
            $payload['suspended_reason'] = (string) ($service->suspended_reason ?? '');
        }

        return $payload;
    }

    private function statusLabel(int $status): string
    {
        return ServiceStatus::$labels[$status] ?? '未知';
    }

    private function removeSensitiveKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/password|secret|api_key|token|raw_response|third_party_response/i', $key) === 1) {
                continue;
            }

            $clean[$key] = $this->removeSensitiveKeys($item);
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function page(array $filters): int
    {
        return max((int) ($filters['page'] ?? 1), 1);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function pageSize(array $filters, int $default, int $max): int
    {
        $value = (int) ($filters['page_size'] ?? $filters['limit'] ?? $default);

        return min(max($value, 1), $max);
    }

    private function money(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : '0.00';
    }
}
