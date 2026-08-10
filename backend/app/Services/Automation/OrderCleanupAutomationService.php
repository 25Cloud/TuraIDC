<?php

namespace App\Services\Automation;

use App\Services\System\SettingService;

/**
 * @deprecated 已由 InvoiceCleanupAutomationService 取代，保留以便任何尚未更新的依赖注入点能够回落到账单清理。
 */
class OrderCleanupAutomationService
{
    public function __construct(
        private SettingService $settingService,
        private InvoiceCleanupAutomationService $invoiceCleanupAutomationService,
    ) {}

    public function handle(): array
    {
        return $this->invoiceCleanupAutomationService->handle();
    }
}
