<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class BackendStructureBoundaryTest extends TestCase
{
    public function test_plugin_provider_does_not_register_plugin_migrations(): void
    {
        $source = $this->appSource('Providers/PluginServiceProvider.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsString('loadMigrationsFrom', $source);
        $this->assertStringNotContainsString('PluginScanner $scanner', $source);
    }

    public function test_client_order_and_payment_controllers_do_not_query_models(): void
    {
        foreach ([
            'Http/Controllers/Client/V2/InvoiceWorkflowController.php',
            'Http/Controllers/Client/V2/OrderController.php',
            'Http/Controllers/Client/V2/PaymentController.php',
            'Http/Controllers/Client/V2/RechargeController.php',
            'Http/Controllers/Client/V2/TicketController.php',
            'Http/Controllers/Client/V2/TicketWorkflowController.php',
        ] as $path) {
            $source = $this->appSource($path);

            $this->assertIsString($source);
            $this->assertStringNotContainsString('App\\Models\\', $source, $path);
            $this->assertStringNotContainsString('::query()', $source, $path);
        }
    }

    public function test_payment_service_delegates_gateway_protocol_operations(): void
    {
        $source = $this->appSource('Services/Finance/PaymentService.php');

        $this->assertIsString($source);
        $this->assertStringContainsString('PaymentGatewayOperationService', $source);
        $this->assertStringNotContainsString('new PaymentPrecreateRequest', $source);
        $this->assertStringNotContainsString('new PaymentRefundRequest', $source);
    }

    private function appSource(string $path): string|false
    {
        return file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.$path);
    }
}
