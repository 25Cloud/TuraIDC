<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ServiceStatus;
use App\Services\System\ServiceMigrationService;
use Tests\TestCase;

class ServiceMigrationServiceTest extends TestCase
{
    public function test_it_builds_reconstructed_service_instance_from_paid_new_order(): void
    {
        $service = new ServiceMigrationService;

        $product = [
            'id' => 30,
            'name' => 'Referral Group eec78359 SERVER',
            'product_type' => 'server',
        ];

        $payload = $service->buildServiceInstanceFromOrder(
            legacyOrder: [
                'id' => 22,
                'order_no' => 'RWEEC78359',
                'type' => 'new',
                'user_id' => 65,
                'product_id' => 30,
                'service_id' => null,
                'amount' => '99.00',
                'billing_cycle' => 'monthly',
                'status' => 1,
                'paid_at' => '2026-05-18 01:40:02',
                'created_at' => '2026-05-18 01:40:02',
                'updated_at' => '2026-05-18 01:40:02',
                'product_type_snapshot' => 'server',
                'product_spec_snapshot' => '未配置规格 #30',
                'config_snapshot' => null,
                'config_pricing_snapshot' => null,
                'trace_id' => 'order-search-eec78359',
                'remark' => null,
            ],
            product: $product,
            supplierProduct: null,
            sourceInvoiceHint: [
                'legacy_invoice_id' => 16,
                'legacy_invoice_no' => 'INV-OLD-16',
            ],
            legacyService: null,
            confidence: 'C'
        );

        $this->assertSame(65, $payload['user_id']);
        $this->assertSame(30, $payload['product_id']);
        $this->assertNull($payload['source_invoice_id']);
        $this->assertSame('R-22', $payload['service_no']);
        $this->assertSame('Referral Group eec78359 SERVER', $payload['name']);
        $this->assertSame('monthly', $payload['billing_cycle']);
        $this->assertSame('99.00', $payload['renewal_price']);
        $this->assertSame(ServiceStatus::ACTIVE, $payload['status']);
        $this->assertSame('2026-06-18 01:40:02', $payload['expires_at']);
        $this->assertStringContainsString('[C级推导]', (string) $payload['remark']);
        $this->assertJson($payload['product_snapshot_json']);
        $this->assertNull($payload['pricing_snapshot_json']);
        $this->assertJson($payload['provision_snapshot_json']);
    }

    public function test_it_builds_placeholder_service_instance_from_ticket_reference(): void
    {
        $service = new ServiceMigrationService;

        $payload = $service->buildPlaceholderServiceInstanceFromTicket(
            legacyTicket: [
                'id' => 7,
                'user_id' => 75,
                'service_id' => 12,
                'subject' => 'Ticket Detail Regression',
                'created_at' => '2026-05-18 01:40:20',
                'updated_at' => '2026-05-18 01:40:20',
            ],
            fallbackProductId: 2
        );

        $this->assertSame(75, $payload['user_id']);
        $this->assertSame(2, $payload['product_id']);
        $this->assertSame('P-12', $payload['service_no']);
        $this->assertSame('占位服务 #12', $payload['name']);
        $this->assertSame('monthly', $payload['billing_cycle']);
        $this->assertSame(ServiceStatus::PENDING, $payload['status']);
        $this->assertStringContainsString('[D级占位]', (string) $payload['remark']);
    }

    public function test_it_builds_renew_linked_service_instance_from_legacy_service_reference(): void
    {
        $service = new ServiceMigrationService;

        $product = [
            'id' => 6,
            'name' => '商品 #6',
            'product_type' => 'server',
        ];

        $supplierProduct = [
            'id' => 19,
            'supplier_id' => 2,
            'product_id' => 21,
            'upstream_product_code' => '39885',
        ];

        $payload = $service->buildServiceInstanceFromOrder(
            legacyOrder: [
                'id' => 11,
                'order_no' => 'ORDRENEWSYNCE0007053',
                'type' => 'renew',
                'user_id' => 37,
                'product_id' => 6,
                'service_id' => 5,
                'amount' => '16.00',
                'billing_cycle' => 'monthly',
                'status' => 1,
                'paid_at' => '2026-05-18 01:39:52',
                'created_at' => '2026-05-18 01:39:52',
                'updated_at' => '2026-05-18 01:39:52',
                'product_type_snapshot' => 'server',
                'product_spec_snapshot' => '未配置规格 #6',
                'config_snapshot' => '[]',
                'config_pricing_snapshot' => '[]',
                'trace_id' => null,
                'remark' => null,
            ],
            product: $product,
            supplierProduct: $supplierProduct,
            sourceInvoiceHint: null,
            legacyService: null,
            confidence: 'A'
        );

        $this->assertSame('S-5', $payload['service_no']);
        $this->assertSame(2, $payload['supplier_id']);
        $this->assertSame(19, $payload['supplier_product_id']);
        $this->assertSame(ServiceStatus::ACTIVE, $payload['status']);
        $this->assertStringContainsString('orders.service_id=5', (string) $payload['remark']);
    }

    public function test_it_maps_order_statuses_to_service_statuses(): void
    {
        $service = new ServiceMigrationService;

        $this->assertSame(ServiceStatus::PENDING, $service->mapOrderStatusToServiceStatus(0));
        $this->assertSame(ServiceStatus::ACTIVE, $service->mapOrderStatusToServiceStatus(1));
        $this->assertSame(ServiceStatus::PENDING, $service->mapOrderStatusToServiceStatus(2));
        $this->assertSame(ServiceStatus::ACTIVE, $service->mapOrderStatusToServiceStatus(3));
        $this->assertSame(ServiceStatus::CANCELLED, $service->mapOrderStatusToServiceStatus(4));
        $this->assertSame(ServiceStatus::CANCELLED, $service->mapOrderStatusToServiceStatus(5));
    }

    public function test_it_derives_service_expiry_from_billing_cycle(): void
    {
        $service = new ServiceMigrationService;

        $this->assertSame('2026-06-18 01:39:52', $service->calculateExpiresAt('monthly', '2026-05-18 01:39:52'));
        $this->assertSame('2026-08-18 01:39:52', $service->calculateExpiresAt('quarterly', '2026-05-18 01:39:52'));
        $this->assertSame('2027-05-18 01:39:52', $service->calculateExpiresAt('annually', '2026-05-18 01:39:52'));
        $this->assertNull($service->calculateExpiresAt('unknown', '2026-05-18 01:39:52'));
    }

    public function test_it_merges_legacy_service_runtime_data_into_reconstructed_payload(): void
    {
        $service = new ServiceMigrationService;

        $payload = $service->buildServiceInstanceFromOrder(
            legacyOrder: [
                'id' => 78,
                'order_no' => 'ORD-78',
                'type' => 'renew',
                'user_id' => 122,
                'product_id' => 5,
                'service_id' => 1,
                'amount' => '420.00',
                'billing_cycle' => 'monthly',
                'status' => 1,
                'paid_at' => '2026-05-18 01:40:02',
                'created_at' => '2026-05-18 01:40:02',
                'updated_at' => '2026-05-18 01:40:02',
                'product_type_snapshot' => 'server',
                'product_spec_snapshot' => '美国1区精品网 4H8G',
                'config_snapshot' => '{"cpu":"4","memory":"8192"}',
                'config_pricing_snapshot' => null,
            ],
            product: [
                'id' => 5,
                'name' => '美国1区精品网 4H8G',
                'product_type' => 'server',
            ],
            supplierProduct: null,
            sourceInvoiceHint: null,
            legacyService: [
                'id' => 1,
                'order_id' => 78,
                'invoice_id' => 556,
                'name' => '美国1区精品网 4H8G',
                'domain' => 'ser323229463323',
                'billing_cycle' => 'annually',
                'amount' => '420.00',
                'status' => 1,
                'auto_renew' => 0,
                'expires_at' => '2027-05-04 23:02:17',
                'created_at' => '2025-04-01 23:02:06',
                'updated_at' => '2026-05-23 04:30:08',
                'locked_pricing' => '{"monthly":{"enabled":true,"base_amount":"35.00","manual_amount":null}}',
                'provision_data' => '{"domain":"ser323229463323","dedicated_ip":"156.238.224.23","username":"root","upstream_host_id":45548,"runtime_status":"on","upstream_status":"Active","host_config_option":[{"key":"cpu","name":"CPU","value":"4核"},{"key":"memory","name":"内存","value":"8G"}],"connection_secret":"secret-token"}',
            ],
            confidence: 'A'
        );

        $this->assertSame('S-1', $payload['service_no']);
        $this->assertSame('ser323229463323', $payload['instance_identifier']);
        $this->assertSame('420.00', $payload['renewal_price']);
        $this->assertSame(ServiceStatus::ACTIVE, $payload['status']);
        $this->assertSame(0, $payload['auto_renew']);
        $this->assertSame(556, $payload['source_invoice_id']);
        $this->assertSame('2027-05-04 23:02:17', $payload['expires_at']);
        $this->assertSame('2026-05-23 04:30:08', $payload['updated_at']);

        $provision = json_decode((string) $payload['provision_snapshot_json'], true);
        $pricing = json_decode((string) $payload['pricing_snapshot_json'], true);

        $this->assertIsArray($provision);
        $this->assertSame('156.238.224.23', $provision['dedicated_ip'] ?? null);
        $this->assertSame('ser323229463323', $provision['requested_host'] ?? null);
        $this->assertSame('root', $provision['username'] ?? null);
        $this->assertSame('secret-token', $provision['connection_secret'] ?? null);
        $this->assertIsArray($provision['host_config_option'] ?? null);
        $this->assertIsArray($pricing);
        $this->assertSame('35.00', $pricing['monthly']['base_amount'] ?? null);
    }
}
