<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Models\AiConversation;
use App\Services\Ai\AiConfig;
use App\Services\Ai\Tools\Catalog\GetProductDetailTool;
use App\Services\Ai\Tools\Catalog\QuotePriceTool;
use App\Services\Ai\Tools\Catalog\SearchProductsTool;
use App\Services\Ai\Tools\Finance\GetBalanceTool;
use App\Services\Ai\Tools\Order\CreateDraftInvoiceTool;
use App\Services\Ai\Tools\Order\ListMyInvoicesTool;
use App\Services\Ai\Tools\Service\GetRenewQuoteTool;
use App\Services\Ai\Tools\Service\GetServiceDetailTool;
use App\Services\Ai\Tools\Service\ListMyServicesTool;
use App\Services\Ai\Tools\Service\PowerActionTool;
use App\Services\Ai\Tools\Service\ReinstallServiceTool;
use App\Services\Ai\Tools\Service\RenewInvoiceTool;
use App\Services\Ai\Tools\Service\ResetServicePasswordTool;
use App\Services\Ai\Tools\Ticket\CreateTicketTool;
use App\Services\Ai\Tools\Ticket\GetTicketTool;
use App\Services\Ai\Tools\Ticket\ListMyTicketsTool;

/**
 * 工具注册表：唯一白名单。
 *
 * 未在此登记的工具有三个来源也永远执行不了：模型瞎编、提示注入伪造、有人手动塞进 tool_call。
 *
 * 严禁登记（资金与不可逆，见设计文档 5.5）：PaymentService::adjustBalance、refundInvoiceToBalance、
 * refundOrder、ReferralService::processWithdrawal/confirmWithdrawalPayment、
 * OrderService::updateManualPaymentStatus、InvoiceService::markPaidManually、
 * ServiceSuspensionService::tryTerminateUpstream、ProductAdminService::forceDelete*、
 * ProductCategoryService::forceDeleteCategory、HandlesAdminUserServices::deleteService、
 * AccountService::updateAccount/setCashBalance（自身不校验负数）。
 */
final class AiToolRegistry
{
    /** @var array<string, AiTool>|null */
    private ?array $tools = null;

    public function __construct(private readonly AiConfig $config) {}

    /**
     * @return array<string, class-string<AiTool>>
     */
    public static function toolClasses(): array
    {
        return [
            'search_products' => SearchProductsTool::class,
            'get_product_detail' => GetProductDetailTool::class,
            'quote_price' => QuotePriceTool::class,
            'list_my_services' => ListMyServicesTool::class,
            'get_service_detail' => GetServiceDetailTool::class,
            'get_renew_quote' => GetRenewQuoteTool::class,
            'list_my_invoices' => ListMyInvoicesTool::class,
            'get_balance' => GetBalanceTool::class,
            'list_my_tickets' => ListMyTicketsTool::class,
            'get_ticket' => GetTicketTool::class,
            'create_draft_invoice' => CreateDraftInvoiceTool::class,
            'renew_invoice' => RenewInvoiceTool::class,
            'power_action' => PowerActionTool::class,
            'reset_service_password' => ResetServicePasswordTool::class,
            'reinstall_service' => ReinstallServiceTool::class,
            'create_ticket' => CreateTicketTool::class,
        ];
    }

    /**
     * @return array<string, AiTool>
     */
    public function all(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $tools = [];
        foreach (self::toolClasses() as $name => $class) {
            /** @var AiTool $instance */
            $instance = app($class);
            if ($instance->name() !== $name) {
                // 名称与登记键不一致直接跳过，避免白名单形同虚设
                continue;
            }
            $tools[$name] = $instance;
        }

        return $this->tools = $tools;
    }

    public function get(string $name): ?AiTool
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * 当前会话可用的工具：受站点启用的工具白名单与权限档位约束。
     *
     * @return array<string, AiTool>
     */
    public function available(string $permissionMode): array
    {
        $enabled = $this->config->enabledTools();
        $available = [];

        foreach ($this->all() as $name => $tool) {
            if ($enabled !== [] && ! in_array($name, $enabled, true)) {
                continue;
            }
            if ($permissionMode === AiConversation::MODE_READ_ONLY && $tool->risk() !== AiTool::RISK_READ) {
                continue;
            }
            $available[$name] = $tool;
        }

        return $available;
    }

    /**
     * @return array<int, array{name: string, description: string, parameters: array<string, mixed>}>
     */
    public function descriptors(string $permissionMode): array
    {
        return array_values(array_map(
            static fn (AiTool $tool): array => $tool->descriptor(),
            $this->available($permissionMode),
        ));
    }

    /**
     * 前端展示用：工具名 + 风险等级 + 描述。
     *
     * @return array<int, array{name: string, risk: string, description: string}>
     */
    public function catalog(string $permissionMode): array
    {
        return array_values(array_map(
            static fn (AiTool $tool): array => [
                'name' => $tool->name(),
                'risk' => $tool->risk(),
                'description' => $tool->description(),
            ],
            $this->available($permissionMode),
        ));
    }
}
