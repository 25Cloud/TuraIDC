<?php

declare(strict_types=1);

namespace EwyFinance\Plugins\Servers\ZjmfFinance\Logic;

use App\Constants\ProductType;
use App\Exceptions\BusinessException;
use App\Models\Supplier;
use App\Services\ProductCatalog\ProductCatalogService;
use App\Services\Upstream\Contracts\ProvidesRenewal;
use EwyFinance\Plugins\Servers\ZjmfFinance\Lib\ZjmfFinanceDriver;

class ZjmfFinance
{
    public function __construct(
        private readonly ZjmfFinanceDriver $driver,
    ) {}

    public function key(): string
    {
        return $this->driver->key();
    }

    public function label(): string
    {
        return $this->driver->label();
    }

    public function capabilities(): array
    {
        return $this->driver->capabilities();
    }

    public function supports(string $capability): bool
    {
        return $this->driver->supports($capability);
    }

    public function resolve(string $capability): ?object
    {
        return $this->driver->resolve($capability);
    }

    public function execute(array $request): array
    {
        $action = trim((string) ($request['action'] ?? ''));
        $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];

        return match ($action) {
            'server.metadata' => [
                'success' => true,
                'action' => $action,
                'data' => [
                    'key' => $this->key(),
                    'label' => $this->label(),
                    'capabilities' => $this->capabilities(),
                ],
            ],
            'server.supports' => [
                'success' => true,
                'action' => $action,
                'data' => [
                    'supported' => $this->supports((string) ($payload['capability'] ?? '')),
                ],
            ],
            'server.resolve_capability' => [
                'success' => true,
                'action' => $action,
                'data' => [
                    'resolved' => $this->resolve((string) ($payload['capability'] ?? '')),
                ],
            ],
            'server.supplier_form_schema' => [
                'success' => true,
                'action' => $action,
                'data' => $this->driver->supplierFormSchema(),
            ],
            'server.supplier.refresh_card' => $this->refreshSupplierCard($action, $request),
            'server.supplier.bulk_connect' => $this->bulkConnectSupplierProducts($action, $request, $payload),
            default => [
                'success' => false,
                'action' => $action,
                'message' => 'Unsupported plugin action',
                'data' => [],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function renderCard(Supplier $supplier, array $context = []): array
    {
        $binding = is_array($context['binding'] ?? null) ? (array) $context['binding'] : [];
        $remote = is_array($context['remote'] ?? null) ? (array) $context['remote'] : [];
        $client = is_array($remote['client'] ?? null) ? (array) $remote['client'] : [];
        $accountName = $this->firstFilled([
            $client['username'] ?? null,
            $client['email'] ?? null,
            $binding['account_name'] ?? null,
            $supplier->api_username ?? null,
        ]);
        $balance = array_key_exists('balance', $remote)
            ? '¥ '.$this->moneyText($remote['balance'])
            : '-';
        $lastUpdated = $this->formatCardDateTime(
            $context['checked_at']
                ?? $remote['checked_at']
                ?? $binding['last_checked_at']
                ?? $supplier->updated_at
                ?? null
        );
        $hasCredentials = $this->hasSupplierCredentials($supplier, $binding, requireUsername: true);

        return [
            'title' => trim((string) ($supplier->name ?? '')) ?: $this->label(),
            'subtitle' => $this->label(),
            'status' => [
                'label' => (int) ($supplier->status ?? 0) === 1 ? '启用中' : '已停用',
                'theme' => (int) ($supplier->status ?? 0) === 1 ? 'success' : 'default',
                'variant' => 'light',
            ],
            'fields' => [
                [
                    'key' => 'username',
                    'label' => '用户名',
                    'value' => $accountName !== '' ? $accountName : '-',
                ],
                [
                    'key' => 'upstream_balance',
                    'label' => '上游余额',
                    'value' => $balance,
                ],
                [
                    'key' => 'updated_at',
                    'label' => '最近更新时间',
                    'value' => $lastUpdated,
                ],
            ],
            'actions' => [
                [
                    'key' => 'refresh_card',
                    'label' => '同步余额',
                    'action' => 'supplier.remote_metric.refresh',
                    'request_action' => 'server.supplier.refresh_card',
                    'theme' => 'primary',
                    'variant' => 'text',
                    'disabled' => ! $hasCredentials,
                    'disabled_reason' => '接口配置不完整，暂时无法同步余额',
                ],
                [
                    'key' => 'bulk_connect',
                    'label' => '批量导入/对接',
                    'action' => 'supplier.batch_connect',
                    'request_action' => 'server.supplier.bulk_connect',
                    'variant' => 'text',
                    'disabled' => ! $hasCredentials,
                    'disabled_reason' => '接口配置不完整，暂时无法批量对接商品',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function refreshSupplierCard(string $action, array $request): array
    {
        $supplier = $this->supplierFromContext($request);
        $renewal = $this->driver->resolve(ProvidesRenewal::class);
        if (! $renewal instanceof ProvidesRenewal || ! method_exists($renewal, 'getBalance')) {
            throw new BusinessException('ZJMF 财务插件暂不支持余额同步', 42200);
        }

        $result = $renewal->getBalance($supplier);
        $remote = is_array($result['data'] ?? null) ? array_replace($result, (array) $result['data']) : $result;
        $checkedAt = now()->format('Y-m-d H:i:s');
        $remote['checked_at'] = $checkedAt;

        return [
            'success' => true,
            'action' => $action,
            'message' => '余额同步成功',
            'data' => [
                'remote' => $remote,
                'card' => $this->renderCard($supplier, [
                    'binding' => $this->bindingFromContext($request),
                    'remote' => $remote,
                    'checked_at' => $checkedAt,
                ]),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function bulkConnectSupplierProducts(string $action, array $request, array $payload): array
    {
        $supplier = $this->supplierFromContext($request);
        $result = app(ProductCatalogService::class)->bulkConnectSupplierProducts(
            $supplier,
            $this->validateBulkConnectPayload($payload)
        );

        return [
            'success' => true,
            'action' => $action,
            'message' => '批量对接完成',
            'data' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateBulkConnectPayload(array $payload): array
    {
        $firstGroupCode = trim((string) ($payload['first_product_group_code'] ?? ''));
        if ($firstGroupCode === '' || ! in_array($firstGroupCode, ProductType::allowedValues(), true)) {
            throw new BusinessException('请选择有效的商品种类', 42200);
        }

        $productIds = collect($payload['product_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($productIds === []) {
            throw new BusinessException('请选择至少一个上游商品', 42200);
        }

        return [
            'first_product_group_code' => $firstGroupCode,
            'first_product_group_id' => $this->positiveInt($payload['first_product_group_id'] ?? null),
            'second_product_group_id' => $this->positiveInt($payload['second_product_group_id'] ?? null),
            'third_product_group_id' => $this->positiveInt($payload['third_product_group_id'] ?? null),
            'second_product_group_name' => trim((string) ($payload['second_product_group_name'] ?? '')),
            'third_product_group_name' => trim((string) ($payload['third_product_group_name'] ?? '')),
            'product_ids' => $productIds,
            'default_status' => (int) ($payload['default_status'] ?? 1) === 1 ? 1 : 0,
            'default_auto_setup' => (int) ($payload['default_auto_setup'] ?? 1) === 1 ? 1 : 0,
            'sync_config_options' => (int) ($payload['sync_config_options'] ?? 0) === 1 ? 1 : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function supplierFromContext(array $request): Supplier
    {
        $supplier = $request['context']['supplier'] ?? null;
        if (! $supplier instanceof Supplier) {
            throw new BusinessException('供应商上下文缺失，无法执行插件动作', 42200);
        }

        return $supplier;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function bindingFromContext(array $request): array
    {
        return is_array($request['context']['binding'] ?? null) ? (array) $request['context']['binding'] : [];
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function firstFilled(array $values): string
    {
        foreach ($values as $value) {
            $string = trim((string) ($value ?? ''));
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }

    private function moneyText(mixed $value): string
    {
        $string = trim((string) ($value ?? ''));
        if ($string === '') {
            return '0.00';
        }

        return is_numeric($string) ? number_format((float) $string, 2, '.', '') : $string;
    }

    /**
     * @param  array<string, mixed>  $binding
     */
    private function hasSupplierCredentials(Supplier $supplier, array $binding, bool $requireUsername): bool
    {
        $secretValues = is_array($binding['has_secret_values'] ?? null) ? (array) $binding['has_secret_values'] : [];
        $hasBaseUrl = trim((string) ($binding['base_url'] ?? $supplier->api_url ?? '')) !== ''
            || (bool) ($binding['has_base_url'] ?? false);
        $hasUsername = ! $requireUsername
            || trim((string) ($binding['account_name'] ?? $supplier->api_username ?? '')) !== '';
        $hasApiKey = (bool) ($binding['has_api_key'] ?? false)
            || (bool) ($secretValues['api_key'] ?? false)
            || trim((string) ($supplier->api_key ?? '')) !== '';

        return $hasBaseUrl && $hasUsername && $hasApiKey;
    }

    private function formatCardDateTime(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $string = trim((string) ($value ?? ''));

        return $string !== '' ? $string : '-';
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }
}
