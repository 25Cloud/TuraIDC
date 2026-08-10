<?php

declare(strict_types=1);

namespace App\Services\Upstream\Data;

use App\Services\Upstream\Contracts\ProvidesSupplierFormSchema;
use App\Services\Upstream\Contracts\UpstreamDriver;

final readonly class UpstreamProviderDescriptor
{
    /**
     * 上游 provider 对外只暴露可审计元数据，避免业务层继续读取驱动内部实现。
     *
     * @param  array<int, class-string>  $capabilities
     * @param  array<string, mixed>  $supplierForm
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $capabilities = [],
        public array $supplierForm = [],
    ) {}

    public static function fromDriver(UpstreamDriver $driver): self
    {
        return new self(
            key: $driver->key(),
            label: $driver->label(),
            capabilities: $driver->capabilities(),
            supplierForm: self::normalizeSupplierForm($driver),
        );
    }

    /**
     * @return array{key:string,label:string,capabilities:array<int, class-string>,supplier_form:array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'capabilities' => $this->capabilities,
            'supplier_form' => $this->supplierForm,
        ];
    }

    /**
     * @return array{value:string,label:string,supplier_form:array<string, mixed>}
     */
    public function toOption(): array
    {
        return [
            'value' => $this->key,
            'label' => $this->label,
            'supplier_form' => $this->supplierForm,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeSupplierForm(UpstreamDriver $driver): array
    {
        $schema = $driver instanceof ProvidesSupplierFormSchema
            ? $driver->supplierFormSchema()
            : [];

        $fields = array_values(array_filter(
            $schema['fields'] ?? [],
            static fn (array $field): bool => trim((string) ($field['key'] ?? '')) !== ''
        ));

        if ($fields === []) {
            $fields = self::defaultCredentialFields();
        }

        return [
            'fields' => $fields,
            'help' => trim((string) ($schema['help'] ?? '')),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function defaultCredentialFields(): array
    {
        return [
            [
                'key' => 'api_url',
                'label' => '接口地址',
                'type' => 'url',
                'required' => true,
                'placeholder' => 'https://example.com',
            ],
            [
                'key' => 'api_username',
                'label' => '用户名',
                'type' => 'text',
                'required' => true,
            ],
            [
                'key' => 'api_key',
                'label' => 'API 密钥',
                'type' => 'password',
                'required' => true,
                'secret' => true,
            ],
        ];
    }
}
