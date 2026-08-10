<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Constants\InvoiceStatus;
use App\Constants\InvoiceType;
use App\Constants\OrderStatus;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Http\Resources\Finance\FinanceLedgerResource;
use App\Models\Invoice;
use App\Models\MessageLog;
use App\Models\OperationLog;
use App\Models\Payment;
use App\Models\ReferralReward;
use App\Models\ReferralWithdrawal;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Automation\ServiceStatusSyncService;
use App\Services\ClientServiceConsole\ClientServiceConsoleService;
use App\Services\Finance\FinanceLedgerQueryService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentService;
use App\Services\Provisioning\ProvisionService;
use App\Services\Referral\ReferralService;
use App\Services\System\NotificationService;
use App\Services\System\OperationLogService;
use App\Services\System\SettingService;
use App\Services\User\Concerns\HandlesAdminUserServices;
use App\Support\AccountIdentifier;
use App\Support\TextSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserService
{
    use HandlesAdminUserServices;

    public function __construct(
        private ClientServiceConsoleService $clientServiceConsoleService,
        private ReferralService $referralService,
        private InvoiceService $invoiceService,
        private PaymentService $paymentService,
        private FinanceLedgerQueryService $financeLedgerQueryService,
        private NotificationService $notificationService,
        private OperationLogService $operationLogService,
        private ProvisionService $provisionService,
        private ServiceStatusSyncService $serviceStatusSyncService,
        private SettingService $settingService,
        private ?AccountService $accountService = null,
    ) {}

    /**
     * 用户列表 (管理端)
     */
    public function list(array $filters, int $perPage = 20)
    {
        $query = User::query()
            ->withReadAggregates()
            ->select([
                'id',
                'email',
                'phone',
                'nickname',
                'real_name',
                'verification_status',
                'is_verified',
                'status',
                'created_at',
            ])
            ->withCount([
                'services as opened_product_count' => fn ($serviceQuery) => $serviceQuery->where('status', ServiceStatus::ACTIVE),
            ]);

        if (! empty($filters['user_id'])) {
            $query->where('id', (int) $filters['user_id']);
        }
        if (! empty($filters['keyword'])) {
            $query->search($filters['keyword']);
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['is_verified']) && $filters['is_verified'] !== '') {
            if ((int) $filters['is_verified'] === 1) {
                $query->where('verification_status', 2);
            } else {
                $query->where('verification_status', '<>', 2);
            }
        }
        if (isset($filters['verification_status']) && $filters['verification_status'] !== '') {
            $targetStatus = (int) $filters['verification_status'];

            $query->where('verification_status', $targetStatus);
        }

        return $query->orderByDesc('id')->paginate($perPage);
    }

    /**
     * 创建用户
     */
    public function create(array $data): User
    {
        $exists = User::where('email', $data['email'])->exists();
        throw_if($exists, new BusinessException('邮箱已存在'));
        $phone = AccountIdentifier::normalizeOptionalPhone((string) ($data['phone'] ?? ''));
        $this->assertUniquePhone($phone);

        $user = DB::transaction(function () use ($data, $phone) {
            $initialBalance = number_format((float) ($data['balance'] ?? 0), 2, '.', '');

            $user = User::create([
                'email' => $data['email'],
                'password' => $data['password'],
                'phone' => $phone,
                'status' => $data['status'] ?? 1,
                'nickname' => TextSanitizer::clean((string) ($data['nickname'] ?? '')),
            ]);

            $this->accounts()->updateAccount($user, [
                'cash_balance' => $initialBalance,
                'credit_limit' => number_format((float) ($data['credit_limit'] ?? 0), 2, '.', ''),
            ]);

            $this->referralService->ensureReferralCode($user);

            return $user;
        });

        return $this->reloadUserReadRelations($user);
    }

    /**
     * 更新用户
     */
    public function update(User $user, array $data): User
    {
        $baseUpdateData = collect($data)->only([
            'phone', 'status', 'nickname', 'company', 'qq', 'admin_note',
        ])->toArray();

        foreach (['nickname', 'company', 'qq'] as $field) {
            if (array_key_exists($field, $baseUpdateData)) {
                $baseUpdateData[$field] = TextSanitizer::clean((string) $baseUpdateData[$field]);
            }
        }

        if (array_key_exists('admin_note', $baseUpdateData)) {
            $baseUpdateData['admin_note'] = TextSanitizer::clean((string) $baseUpdateData['admin_note'], true);
        }

        if (array_key_exists('phone', $baseUpdateData)) {
            $baseUpdateData['phone'] = AccountIdentifier::normalizeOptionalPhone((string) $baseUpdateData['phone']);
            $this->assertUniquePhone($baseUpdateData['phone'], (int) $user->id);
        }

        if (! empty($data['password'])) {
            $baseUpdateData['password'] = $data['password'];
        }

        $accountUpdateData = [];
        if (array_key_exists('credit_limit', $data)) {
            $accountUpdateData['credit_limit'] = number_format((float) $data['credit_limit'], 2, '.', '');
        }

        DB::transaction(function () use ($user, $baseUpdateData, $accountUpdateData) {
            if ($baseUpdateData !== []) {
                $user->update($baseUpdateData);
            }

            if ($accountUpdateData !== []) {
                $this->accounts()->updateAccount($user, $accountUpdateData);
                $user->unsetRelation('account');
            }
        });

        return $this->reloadUserReadRelations($user);
    }

    /**
     * 禁用/启用
     */
    public function toggleStatus(User $user): User
    {
        $user->update(['status' => $user->status === 1 ? 0 : 1]);

        return $this->reloadUserReadRelations($user);
    }

    /**
     * 用户详情（含完整统计）
     */
    public function detail(User $user): array
    {
        $user->loadMissing([
            'memberLevel',
            'account',
        ]);
        $memberLevel = $user->memberLevel;
        $countStats = User::query()
            ->whereKey($user->id)
            ->withCount([
                'services as service_active' => fn ($query) => $query->where('status', 1),
                'services as service_total',
                'orders as order_total',
                'orders as order_pending' => fn ($query) => $query->where('status', 0),
                'tickets as ticket_open' => fn ($query) => $query->whereIn('status', [0, 1, 2]),
                'tickets as ticket_closed' => fn ($query) => $query->where('status', 3),
                'tickets as ticket_total',
                'invoices as invoice_unpaid' => fn ($query) => $query->where('status', 0),
                'invoices as invoice_paid' => fn ($query) => $query->where('status', 1),
            ])
            ->first();
        $orderTotal = (int) ($countStats?->order_total ?? 0);
        $orderPending = (int) ($countStats?->order_pending ?? 0);
        $invoiceUnpaid = (int) ($countStats?->invoice_unpaid ?? 0);
        $invoicePaid = (int) ($countStats?->invoice_paid ?? 0);

        if ($orderTotal === 0 && ($invoiceUnpaid > 0 || $invoicePaid > 0)) {
            $orderTotal = $invoiceUnpaid + $invoicePaid;
            $orderPending = $invoiceUnpaid;
        }

        $balanceSummary = $this->financeLedgerQueryService->summaryForClient($user, []);
        $invoiceSummary = Invoice::query()
            ->where('user_id', $user->id)
            ->selectRaw('COALESCE(SUM(CASE WHEN status = 0 THEN amount ELSE 0 END), 0) as unpaid_amount')
            ->first();
        $referralSummary = ReferralReward::query()
            ->where('referrer_user_id', $user->id)
            ->selectRaw('COUNT(*) as rewarded_orders_count')
            ->selectRaw('COALESCE(SUM(reward_amount), 0) as total_reward_amount')
            ->first();
        $directReferralCount = $this->referralService->directReferralCount((int) $user->id);
        $recentReferrals = $this->referralService->recentDirectReferrals((int) $user->id, 8, true);
        $withdrawSummary = ReferralWithdrawal::query()
            ->where('user_id', $user->id)
            ->selectRaw('COALESCE(SUM(CASE WHEN status = 0 THEN amount ELSE 0 END), 0) as withdrawing_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = 1 THEN amount ELSE 0 END), 0) as withdrawn_amount')
            ->first();

        return [
            'user' => $user,
            'stats' => [
                'service_active' => (int) ($countStats?->service_active ?? 0),
                'service_total' => (int) ($countStats?->service_total ?? 0),
                'order_total' => $orderTotal,
                'order_pending' => $orderPending,
                'total_income' => (float) ($balanceSummary['total_in'] ?? 0),
                'total_expense' => (float) ($balanceSummary['total_out'] ?? 0),
                'unpaid_amount' => (float) ($invoiceSummary?->unpaid_amount ?? 0),
                'ticket_open' => (int) ($countStats?->ticket_open ?? 0),
                'ticket_closed' => (int) ($countStats?->ticket_closed ?? 0),
                'ticket_total' => (int) ($countStats?->ticket_total ?? 0),
                'invoice_unpaid' => $invoiceUnpaid,
                'invoice_paid' => $invoicePaid,
                'direct_referral_count' => $directReferralCount,
                'rewarded_orders_count' => (int) ($referralSummary?->rewarded_orders_count ?? 0),
                'total_referral_reward' => (float) ($referralSummary?->total_reward_amount ?? 0),
            ],
            'referral' => [
                'referral_code' => $user->referral_code,
                'referrer_user_id' => $user->referrer_user_id,
                'member_level' => $memberLevel ? [
                    'id' => $memberLevel->id,
                    'name' => $memberLevel->name,
                    'code' => $memberLevel->code,
                    'reward_rate' => (float) $memberLevel->reward_rate,
                ] : null,
                'total_sales_amount' => (float) $user->total_sales_amount,
                'referral_frozen_amount' => (float) $user->referral_frozen_amount,
                'referral_available_amount' => (float) $user->referral_available_amount,
                'referral_withdrawing_amount' => (float) ($withdrawSummary?->withdrawing_amount ?? $user->referral_withdrawing_amount),
                'referral_withdrawn_amount' => (float) ($withdrawSummary?->withdrawn_amount ?? $user->referral_withdrawn_amount),
                'recent_referrals' => $recentReferrals->map(fn (User $item) => [
                    'id' => $item->id,
                    'email' => $item->email,
                    'nickname' => $item->nickname,
                    'display_name' => $item->display_name,
                    'created_at' => $item->created_at?->format('Y-m-d H:i:s'),
                    'referred_at' => $item->referred_at?->format('Y-m-d H:i:s'),
                ])->values()->all(),
            ],
        ];
    }

    /**
     * 用户账单列表
     */
    public function invoices(User $user, array $filters, int $perPage = 20)
    {
        $query = $user->invoices()->with([
            'order:id,order_no,status,type,service_id,paid_at,product_id,billing_cycle',
            'order.product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'service:id,name,status,expires_at',
            'payments',
            'items',
        ]);

        if (($filters['status'] ?? '') === '5') {
            $query->where(function ($builder) {
                $builder->whereHas('payments', fn ($paymentQuery) => $paymentQuery
                    ->whereGatewayKeyIn(PaymentGatewayCode::thirdPartyGateways())
                    ->where('status', PaymentStatus::REFUNDED))
                    ->orWhereHas('order', fn ($orderQuery) => $orderQuery->where('status', OrderStatus::REFUNDED));
            });
        } elseif (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['type'])) {
            $type = (string) $filters['type'];
            if (in_array($type, ['new', 'normal'], true)) {
                $query->whereIn('type', ['new', 'normal']);
            } else {
                $query->where('type', $type);
            }
        }

        $paginator = $query->orderByDesc('id')->paginate($perPage);
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Invoice $invoice) => $this->transformInvoiceListItem($invoice))
        );

        return $paginator;
    }

    public function invoiceDetail(User $user, int $invoiceId): array
    {
        $this->findUserInvoice($user, $invoiceId);

        return $this->invoiceService->adminDetail($invoiceId);
    }

    public function manualInvoiceEntry(User $user, int $invoiceId, array $data, array $context = []): array
    {
        $invoice = $this->findUserInvoice($user, $invoiceId);

        $this->invoiceService->markPaidManually($invoice, [
            'amount' => $data['amount'],
            'paid_at' => $data['paid_at'],
            'payment_gateway' => $data['payment_gateway'] ?? 'manual',
            'trade_no' => $data['trade_no'] ?? '',
            'send_email' => (bool) ($data['send_email'] ?? false),
            'remark' => $data['remark'] ?? '',
            'sync_business_flow' => (bool) ($data['sync_business_flow'] ?? false),
        ], $context);

        return $this->invoiceDetail($user, $invoiceId);
    }

    public function sendInvoiceEmail(User $user, int $invoiceId, array $context = []): array
    {
        $invoice = $this->findUserInvoice($user, $invoiceId);
        $invoice->loadMissing([
            'order.product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'payments.callbacks',
            'items',
        ]);

        throw_if(trim((string) $user->email) === '', new BusinessException('用户未绑定邮箱，无法发送账单邮件'));

        $thirdPartyPayments = $invoice->payments
            ->filter(fn (Payment $payment) => $payment->isThirdPartyGateway())
            ->values();
        $latestPayment = $thirdPartyPayments->first(fn (Payment $payment) => (int) $payment->status === PaymentStatus::SUCCESS)
            ?? $thirdPartyPayments->first();
        $isPaidInvoice = (int) $invoice->status === InvoiceStatus::PAID;
        $templateCode = $isPaidInvoice
            ? NotificationService::TEMPLATE_PAYMENT_SUCCESS
            : NotificationService::TEMPLATE_CLIENT_ORDER_PENDING;

        $this->notificationService->sendTemplateEmail((string) $user->email, $templateCode, [
            'site_name' => (string) config('idc.site_name', config('app.name', '二五云')),
            'display_name' => (string) $user->display_name,
            'notice_title' => $isPaidInvoice ? '账单支付确认' : '账单支付提醒',
            'invoice_no' => (string) $invoice->invoice_no,
            'order_no' => (string) ($invoice->order?->order_no ?? ''),
            'product_name' => (string) ($invoice->order?->display_product_name ?? ''),
            'amount' => number_format((float) $invoice->amount, 2, '.', ''),
            'paid_amount' => number_format((float) ($latestPayment?->amount ?? $invoice->amount), 2, '.', ''),
            'status_label' => (string) (InvoiceStatus::$labels[$invoice->status] ?? (string) $invoice->status),
            'due_at' => $invoice->due_date ? ($invoice->due_date?->format('Y-m-d H:i:s') ?? $invoice->due_date?->format('Y-m-d')) : '',
            'paid_at' => $invoice->paid_at ? $invoice->paid_at->format('Y-m-d H:i:s') : '',
            'payment_method' => $latestPayment ? $this->resolvePaymentGatewayLabel($latestPayment->gatewayKey()) : '',
            'trade_no' => (string) ($latestPayment?->trade_no ?? ''),
            'notice_message' => $isPaidInvoice
                ? '该账单已支付完成，如有疑问请联系管理员。'
                : '该账单当前仍待支付，请尽快完成付款。',
        ]);

        $this->operationLogService->write(
            userId: ((int) ($context['operator_id'] ?? 0)) ?: null,
            userType: 'admin',
            action: 'invoice.email.sent',
            module: 'order',
            targetId: (int) ($invoice->order_id ?: 0) ?: null,
            detail: [
                'invoice_id' => $invoice->id,
                'invoice_no' => $invoice->invoice_no,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'operator_name' => (string) ($context['operator_name'] ?? ''),
            ],
            ipAddress: (string) ($context['ip_address'] ?? ''),
        );

        return $this->invoiceDetail($user, $invoiceId);
    }

    public function refundInvoice(User $user, int $invoiceId, array $data, array $context = []): array
    {
        $invoice = $this->findUserInvoice($user, $invoiceId);
        $invoice->loadMissing([
            'order',
            'payments.callbacks',
        ]);

        $paymentSummary = $this->buildInvoicePaymentSummary($invoice);
        $refundActions = $this->resolveInvoiceRefundActions($invoice, $paymentSummary);
        $refundMethod = trim((string) ($data['refund_method'] ?? 'balance'));
        $scope = (array) ($data['scope'] ?? ['order', 'payment']);

        throw_if(
            ! ($refundActions['can_balance'] ?? false) && ! ($refundActions['can_original'] ?? false),
            new BusinessException((string) ($refundActions['blocked_reason'] ?? '当前账单不支持退款'))
        );

        $result = match ($refundMethod) {
            'balance' => $this->paymentService->refundInvoiceToBalance($user, $invoice, [
                'amount' => $data['amount'] ?? null,
                'remark' => $data['remark'] ?? '',
                'scope' => $scope,
            ], $context),
            'original' => $this->refundInvoiceByOriginalRoute($user, $invoice, $paymentSummary, $refundActions, $data, $context),
            default => throw new BusinessException('不支持的退款方式'),
        };

        if (($result['already_refunded'] ?? false) !== true) {
            $refund = (array) ($result['refund'] ?? []);

            $this->operationLogService->write(
                userId: ((int) ($context['operator_id'] ?? 0)) ?: null,
                userType: 'admin',
                action: 'invoice.refund',
                module: 'order',
                targetId: (int) ($invoice->order_id ?: 0) ?: null,
                detail: [
                    'invoice_id' => (int) $invoice->id,
                    'invoice_no' => (string) $invoice->invoice_no,
                    'refund_method' => (string) ($refund['refund_method'] ?? $refundMethod),
                    'refund_amount' => (string) ($refund['refund_amount'] ?? ''),
                    'refund_reason' => (string) ($refund['refund_reason'] ?? ''),
                    'trade_no' => (string) ($refund['trade_no'] ?? ''),
                    'operator_name' => (string) ($context['operator_name'] ?? ''),
                ],
                ipAddress: (string) ($context['ip_address'] ?? ''),
            );
        }

        return array_merge($this->invoiceDetail($user, $invoiceId), [
            'document_links' => [
                'refund_id' => isset($result['refund_id']) ? (int) $result['refund_id'] : null,
                'refund_invoice_id' => isset($result['refund_invoice_id']) ? (int) $result['refund_invoice_id'] : null,
                'recharge_record_id' => isset($result['recharge_record_id']) ? (int) $result['recharge_record_id'] : null,
            ],
        ]);
    }

    /**
     * 从服务实例发起退款（管理端 → 用户 → 产品/服务 → 退款）
     */
    public function refundService(User $user, int $serviceId, array $data, array $context = []): array
    {
        $service = Service::query()
            ->where('id', $serviceId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $order = $service->order;
        throw_if(! $order, new BusinessException('该服务未关联账单，无法退款'));
        throw_if((int) $order->user_id !== (int) $user->id, new BusinessException('账单与用户不匹配'));

        $order->loadMissing(['invoice.payments', 'user']);

        $result = $this->paymentService->refundOrder($order, [
            'refund_method' => $data['refund_method'] ?? 'balance',
            'amount' => $data['amount'] ?? null,
            'remark' => $data['remark'] ?? '',
        ], $context);

        if (($result['already_refunded'] ?? false) !== true) {
            $refund = (array) ($result['refund'] ?? []);

            $this->operationLogService->write(
                userId: ((int) ($context['operator_id'] ?? 0)) ?: null,
                userType: 'admin',
                action: 'service.refund',
                module: 'order',
                targetId: (int) $order->id,
                detail: [
                    'service_id' => (int) $service->id,
                    'service_name' => (string) ($service->name ?? ''),
                    'order_no' => (string) $order->order_no,
                    'invoice_id' => (int) ($order->invoice?->id ?? 0),
                    'refund_method' => (string) ($refund['refund_method'] ?? ''),
                    'refund_amount' => (string) ($refund['refund_amount'] ?? ''),
                    'refund_reason' => (string) ($refund['refund_reason'] ?? ''),
                    'trade_no' => (string) ($refund['trade_no'] ?? ''),
                    'operator_name' => (string) ($context['operator_name'] ?? ''),
                ],
                ipAddress: (string) ($context['ip_address'] ?? ''),
            );
        }

        $service->refresh();
        $service->loadMissing(['order.invoice', 'product']);

        return [
            'service_id' => (int) $service->id,
            'order_id' => (int) $order->id,
            'order_status' => (int) $order->fresh()->status,
            'refund' => $result['refund'] ?? [],
            'already_refunded' => (bool) ($result['already_refunded'] ?? false),
        ];
    }

    /**
     * 用户余额变动记录
     */
    public function balanceLogs(User $user, array $filters, int $perPage = 20): array
    {
        $normalizedFilters = $this->normalizeBalanceLogFiltersToLedger($filters);
        $paginator = $this->financeLedgerQueryService->paginatorForUser($user, $normalizedFilters, $perPage);
        $summary = $this->financeLedgerQueryService->summaryForClient($user, $normalizedFilters);

        return [
            'paginator' => $paginator,
            'resource_class' => FinanceLedgerResource::class,
            'summary' => [
                'total_income' => (float) ($summary['total_in'] ?? 0),
                'total_expense' => (float) ($summary['total_out'] ?? 0),
                'cash_balance' => (float) $user->balance,
                'total_count' => (int) ($summary['total_count'] ?? 0),
            ],
        ];
    }

    /**
     * 用户工单列表
     */
    public function tickets(User $user, array $filters, int $perPage = 20): array
    {
        $query = $user->tickets()->with('service:id,name');

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['priority']) && $filters['priority'] !== '') {
            $query->where('priority', $filters['priority']);
        }

        $paginator = $query->orderByDesc('id')->paginate($perPage);
        $now = now();
        $currentMonthStart = $now->copy()->startOfMonth();
        $previousMonthStart = $currentMonthStart->copy()->subMonth();
        $currentYearStart = $now->copy()->startOfYear();
        $previousYearStart = $currentYearStart->copy()->subYear();

        $summary = Ticket::query()
            ->where('user_id', $user->id)
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) as this_month', [$currentMonthStart])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END), 0) as last_month',
                [$previousMonthStart, $currentMonthStart]
            )
            ->selectRaw('COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) as this_year', [$currentYearStart])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END), 0) as last_year',
                [$previousYearStart, $currentYearStart]
            )
            ->first();

        return [
            'paginator' => $paginator,
            'summary' => [
                'this_month' => (int) ($summary?->this_month ?? 0),
                'last_month' => (int) ($summary?->last_month ?? 0),
                'this_year' => (int) ($summary?->this_year ?? 0),
                'last_year' => (int) ($summary?->last_year ?? 0),
            ],
        ];
    }

    /**
     * 用户操作日志
     */
    public function operationLogs(int $userId, array $filters, int $perPage = 20)
    {
        $query = OperationLog::where('user_id', $userId)->where('user_type', 'client');

        if (! empty($filters['keyword'])) {
            $query->where('action', 'like', "%{$filters['keyword']}%");
        }

        if (! empty($filters['start_date'])) {
            $query->whereDate('created_at', '>=', $filters['start_date']);
        }

        if (! empty($filters['end_date'])) {
            $query->whereDate('created_at', '<=', $filters['end_date']);
        }

        if (! empty($filters['ip_address'])) {
            $query->where('ip_address', 'like', "%{$filters['ip_address']}%");
        }

        if (! empty($filters['source'])) {
            if ($filters['source'] === 'api') {
                $query->whereNotNull('context->method');
            } else {
                $query->whereNull('context->method');
            }
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * 用户短信日志
     */
    public function smsLogs(User $user, int $perPage = 20): LengthAwarePaginator
    {
        $phone = trim((string) $user->phone);
        if ($phone === '') {
            return $this->emptyPaginator($perPage);
        }

        $query = $this->buildUserSmsLogQuery($phone);
        if ($query === null) {
            return $this->emptyPaginator($perPage);
        }

        $paginator = $query
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $paginator->setCollection(
            $paginator->getCollection()->map(function ($log) {
                $item = $log->toArray();
                $item['params_json'] = $this->normalizeNotificationParams($item['params_json'] ?? []);

                return $item;
            })
        );

        return $paginator;
    }

    /**
     * 用户邮件日志
     */
    public function emailLogs(User $user, int $perPage = 20): LengthAwarePaginator
    {
        $email = trim((string) $user->email);
        if ($email === '') {
            return $this->emptyPaginator($perPage);
        }

        $query = $this->buildUserEmailLogQuery($email);
        if ($query === null) {
            return $this->emptyPaginator($perPage);
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    private function normalizeBalanceLogFiltersToLedger(array $filters): array
    {
        $eventType = trim((string) ($filters['event_type'] ?? ''));

        return array_filter([
            'tab' => match ($eventType) {
                'recharge', 'manual_recharge' => 'recharge',
                'consume', 'refund', 'invoice_payment', 'invoice_refund' => 'invoices',
                'adjust', 'admin_deduct', 'manual_deduction', 'system_adjustment' => 'adjustment',
                default => 'balance',
            },
            'event_type' => match ($eventType) {
                'consume' => 'invoice_payment',
                'refund' => 'invoice_refund',
                'adjust' => 'system_adjustment',
                'admin_deduct' => 'manual_deduction',
                'referral_withdraw_approved' => 'referral_credit_cash',
                default => $eventType !== '' ? $eventType : null,
            },
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function assertUniquePhone(?string $phone, ?int $ignoreUserId = null): void
    {
        if ($phone === null || $phone === '') {
            return;
        }

        $query = User::query()->where('phone', $phone);
        if ($ignoreUserId !== null) {
            $query->where('id', '<>', $ignoreUserId);
        }

        if ($query->exists()) {
            throw new BusinessException('手机号已被注册');
        }
    }

    private function reloadUserReadRelations(User $user): User
    {
        $relations = ['account'];

        return $user->fresh($relations) ?? $user->loadMissing($relations);
    }

    private function accounts(): AccountService
    {
        return $this->accountService ??= app(AccountService::class);
    }

    private function buildUserSmsLogQuery(string $phone): ?Builder
    {
        if (! Schema::hasTable('message_logs')) {
            return null;
        }

        return MessageLog::query()
            ->where('channel', 'sms')
            ->where('recipient', $phone)
            ->selectRaw('id, recipient as phone, template_code, content, params_json, status, provider, request_id, error_msg, sent_at, created_at, updated_at, origin_type');
    }

    private function buildUserEmailLogQuery(string $email): ?Builder
    {
        if (! Schema::hasTable('message_logs')) {
            return null;
        }

        return MessageLog::query()
            ->where('channel', 'email')
            ->where('recipient', $email)
            ->selectRaw('id, template_code, recipient as to_email, subject, content, status, error_msg, sent_at, created_at, updated_at');
    }

    private function emptyPaginator(int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, $perPage);
    }

    private function normalizeNotificationParams(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function findUserInvoice(User $user, int $invoiceId): Invoice
    {
        return $user->invoices()
            ->whereKey($invoiceId)
            ->firstOrFail();
    }

    private function refundInvoiceByOriginalRoute(
        User $user,
        Invoice $invoice,
        ?array $paymentSummary,
        array $refundActions,
        array $data,
        array $context = [],
    ): array {
        throw_if(
            ! ($refundActions['can_original'] ?? false),
            new BusinessException((string) ($refundActions['original_blocked_reason'] ?? $refundActions['blocked_reason'] ?? '当前支付方式不支持原路退款'))
        );

        $gateway = (string) ($paymentSummary['gateway'] ?? '');

        if ($gateway === 'balance') {
            return $this->paymentService->refundInvoiceToBalance($user, $invoice, [
                'amount' => $data['amount'] ?? null,
                'remark' => $data['remark'] ?? '后台按原余额路径退款',
            ], $context);
        }

        throw_if($gateway !== PaymentGatewayCode::ALIPAY, new BusinessException('当前支付方式不支持原路退款'));

        return $this->invoiceService->refundByPaymentMethod($invoice, [
            'amount' => $data['amount'] ?? ($paymentSummary['amount'] ?? null),
            'remark' => $data['remark'] ?? '后台发起原路退款',
        ], $context);
    }

    private function resolvePaymentGatewayLabel(string $gateway): string
    {
        return match ($gateway) {
            PaymentGatewayCode::ALIPAY => PaymentGatewayCode::label(PaymentGatewayCode::ALIPAY),
            PaymentGatewayCode::YIPAY => PaymentGatewayCode::label(PaymentGatewayCode::YIPAY),
            'wechat' => '微信支付',
            'balance' => '余额支付',
            'bank_transfer' => '银行转账',
            'offline' => '线下支付',
            default => '手动入账',
        };
    }

    private function transformInvoiceListItem(Invoice $invoice): array
    {
        $detail = $this->invoiceService->adminListItem($invoice);
        $paymentSummary = $this->buildInvoicePaymentSummary($invoice);
        $refundActions = $this->resolveInvoiceRefundActions($invoice, $paymentSummary);

        return [
            ...$detail,
            'created_at' => (string) ($detail['created_at'] ?? $invoice->created_at?->format('Y-m-d H:i:s')),
            'due_date' => (string) ($detail['due_date'] ?? $invoice->due_date?->format('Y-m-d')),
            'paid_at' => (string) ($detail['paid_at'] ?? $invoice->paid_at?->format('Y-m-d H:i:s')),
            'payment_summary' => $detail['payment_summary'] ?? $paymentSummary,
            'refund_actions' => $refundActions,
        ];
    }

    private function buildInvoicePaymentSummary(Invoice $invoice): ?array
    {
        if (! $invoice->relationLoaded('payments')) {
            $invoice->loadMissing(['payments' => fn ($query) => $query->orderByDesc('id')]);
        }

        $payment = $this->resolvePrimaryInvoicePayment($invoice->payments);

        if (! $payment instanceof Payment) {
            return null;
        }

        $refund = (array) data_get((array) ($payment->callback_raw ?? []), 'refund', []);

        return [
            'id' => (int) $payment->id,
            'payment_no' => (string) $payment->payment_no,
            'gateway' => $payment->gatewayKey(),
            'gateway_key' => $payment->gatewayKey(),
            'gateway_label' => $this->resolvePaymentGatewayLabel($payment->gatewayKey()),
            'trade_no' => (string) ($payment->trade_no ?? ''),
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'status' => (int) $payment->status,
            'status_label' => $this->resolvePaymentStatusLabel((int) $payment->status),
            'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s'),
            'refund_method' => (string) ($refund['refund_method'] ?? ''),
            'refund_method_label' => (string) ($refund['refund_method_label'] ?? ''),
            'refund_reason' => (string) ($refund['refund_reason'] ?? ''),
            'refunded_at' => (string) ($refund['refunded_at'] ?? ($refund['gmt_refund_pay'] ?? '')),
        ];
    }

    private function resolvePrimaryInvoicePayment(iterable $payments): ?Payment
    {
        $collection = collect($payments)
            ->filter(fn (Payment $payment) => $payment->isThirdPartyGateway())
            ->values();

        return $collection
            ->first(fn (Payment $payment) => ! (bool) data_get((array) ($payment->callback_raw ?? []), 'duplicate_paid', false)
                && in_array((int) $payment->status, [PaymentStatus::SUCCESS, PaymentStatus::REFUNDED], true))
            ?? $collection->first(fn (Payment $payment) => in_array((int) $payment->status, [PaymentStatus::SUCCESS, PaymentStatus::REFUNDED], true));
    }

    private function resolveInvoiceDisplayStatus(Invoice $invoice, ?array $paymentSummary): array
    {
        if (($paymentSummary['status'] ?? null) === PaymentStatus::REFUNDED || (int) $invoice->status === InvoiceStatus::REFUNDED) {
            return [
                'status' => InvoiceStatus::REFUNDED,
                'status_label' => '已退款',
            ];
        }

        return [
            'status' => (int) $invoice->status,
            'status_label' => InvoiceStatus::$labels[$invoice->status] ?? (string) $invoice->status,
        ];
    }

    private function resolveInvoiceRefundActions(Invoice $invoice, ?array $paymentSummary): array
    {
        $paymentGateway = (string) ($paymentSummary['gateway'] ?? '');
        $service = $invoice->service;
        $blockedReason = '';
        $originalBlockedReason = '';
        $canBalance = false;
        $canOriginal = false;

        if ((int) $invoice->status !== InvoiceStatus::PAID) {
            $blockedReason = '仅已支付账单支持退款';
        } elseif (! is_array($paymentSummary)) {
            $blockedReason = '未找到可退款的支付记录';
        } elseif (($paymentSummary['status'] ?? null) === PaymentStatus::REFUNDED) {
            $blockedReason = '该账单已完成退款';
        } elseif ($service && (int) $service->id > 0 && (int) ($service->status ?? 0) !== 0) {
            // 已开通服务需先在服务控制台处理资源再退款
            $blockedReason = '账单已开通服务，请先在服务控制台处理资源后再退款';
        } else {
            $canBalance = true;
            $canOriginal = in_array($paymentGateway, [PaymentGatewayCode::ALIPAY, PaymentGatewayCode::BALANCE], true);

            if (! $canOriginal) {
                $originalBlockedReason = '当前支付方式不支持原路退款';
            }
        }

        return [
            'can_balance' => $canBalance,
            'can_original' => $canOriginal,
            'blocked_reason' => $blockedReason,
            'original_blocked_reason' => $originalBlockedReason,
        ];
    }

    private function resolvePaymentStatusLabel(int $status): string
    {
        return match ($status) {
            PaymentStatus::SUCCESS => '已支付',
            PaymentStatus::FAILED => '失败',
            PaymentStatus::REFUNDED => '已退款',
            PaymentStatus::CANCELLED => '已取消',
            default => '未支付',
        };
    }

    private function resolveInvoiceTypeLabel(string $type, Invoice $invoice): string
    {
        return match ($type) {
            'new' => '新购账单',
            'normal' => '新购账单',
            'renew' => '续费账单',
            'recharge' => '充值账单',
            'deduction' => '扣款账单',
            'refund' => '退款红字账单',
            'referral_credit' => '推荐奖励账单',
            'manual' => '手工账单',
            default => ($invoice->order?->display_product_name ?? '') !== '' ? '产品账单' : '普通账单',
        };
    }

    private function resolveInvoiceScene(Invoice $invoice): array
    {
        $type = InvoiceType::normalize((string) $invoice->type);
        $remark = trim((string) ($invoice->config_snapshot['remark'] ?? $invoice->coupon_snapshot['remark'] ?? ''));
        $productName = trim((string) ($invoice->order?->display_product_name ?? ''));
        $billingCycle = trim((string) ($invoice->billing_cycle ?? $invoice->order?->billing_cycle ?? ''));
        $refundScene = $this->resolveRefundScene($invoice);

        if ($refundScene !== null) {
            return $refundScene;
        }

        return match ($type) {
            InvoiceType::NEW_PURCHASE => [
                'kind' => 'new_purchase',
                'headline' => '新购账单',
                'subheadline' => '首次购买产生的账单，通常包含产品价格、配置附加费与优惠信息。',
                'badge' => '新购',
                'highlight' => $productName !== '' ? $productName : (string) ($invoice->order?->order_no ?? ''),
                'items' => $this->buildOrderBasedSceneItems($invoice),
            ],
            InvoiceType::RENEW => [
                'kind' => 'renew',
                'headline' => '续费账单',
                'subheadline' => '用于延长现有服务周期，通常与已有实例关联。',
                'badge' => '续费',
                'highlight' => $billingCycle !== '' ? $billingCycle : (string) ($invoice->order?->order_no ?? ''),
                'items' => $this->buildOrderBasedSceneItems($invoice),
            ],
            InvoiceType::RECHARGE => [
                'kind' => 'recharge',
                'headline' => '充值账单',
                'subheadline' => '余额充值到账后生成，通常直接完成支付。',
                'badge' => '充值',
                'highlight' => $remark !== '' ? $remark : '资金到账',
                'items' => [[
                    'description' => '账户充值入账',
                    'amount' => $invoice->amount,
                ]],
            ],
            InvoiceType::DEDUCTION => [
                'kind' => 'deduction',
                'headline' => '扣款账单',
                'subheadline' => '管理员或系统发起的余额扣减记录。',
                'badge' => '扣款',
                'highlight' => $remark !== '' ? $remark : '余额扣减',
                'items' => [[
                    'description' => '账户扣款',
                    'amount' => $invoice->amount,
                ]],
            ],
            InvoiceType::REFUND => [
                'kind' => 'refund',
                'headline' => '退款红字账单',
                'subheadline' => '用于冲抵原账单的退款单据。',
                'badge' => '退款',
                'highlight' => (string) ($invoice->originInvoice?->invoice_no ?? $invoice->config_snapshot['origin_invoice_no'] ?? ''),
                'items' => [[
                    'description' => '退款冲抵',
                    'amount' => $invoice->amount,
                ]],
            ],
            InvoiceType::REFERRAL_CREDIT => [
                'kind' => 'referral_credit',
                'headline' => '推荐奖励账单',
                'subheadline' => '推荐返利结算到账后生成，金额通常直接入账到余额。',
                'badge' => '推荐奖励',
                'highlight' => $remark !== '' ? $remark : '推广返利入账',
                'items' => [[
                    'description' => '推荐奖励入账',
                    'amount' => $invoice->amount,
                ]],
            ],
            InvoiceType::MANUAL => [
                'kind' => 'manual',
                'headline' => '手工账单',
                'subheadline' => '后台人工创建或修正的账单。',
                'badge' => '手工',
                'highlight' => $remark !== '' ? $remark : '人工账单',
            ],
            default => [
                'kind' => 'default',
                'headline' => '账单详情',
                'subheadline' => '',
                'badge' => $this->resolveInvoiceTypeLabel((string) $invoice->type, $invoice),
                'highlight' => $remark !== '' ? $remark : '',
            ],
        };
    }

    private function resolveRefundScene(Invoice $invoice): ?array
    {
        $payment = null;
        if ($invoice->relationLoaded('payments')) {
            $payment = collect($invoice->payments)
                ->filter(fn (Payment $item) => $item->isThirdPartyGateway())
                ->first(fn (Payment $item) => (int) $item->status === PaymentStatus::REFUNDED
                    || is_array(data_get((array) ($item->callback_raw ?? []), 'refund')));
        }

        if (! $payment instanceof Payment && (int) $invoice->status !== InvoiceStatus::REFUNDED) {
            return null;
        }

        $refund = (array) data_get((array) ($payment?->callback_raw ?? []), 'refund', []);
        $refundAmount = (float) ($refund['refund_amount'] ?? $payment?->amount ?? $invoice->amount ?? 0);
        $originalAmount = (float) ($payment?->amount ?? $invoice->amount ?? 0);
        $refundMethodLabel = trim((string) ($refund['refund_method_label'] ?? ''));

        if ($refundMethodLabel === '') {
            $refundMethod = trim((string) ($refund['refund_method'] ?? ''));
            $refundMethodLabel = match ($refundMethod) {
                'balance' => '退回余额',
                'original' => '原路退款',
                default => '已退款',
            };
        }

        $refundReason = trim((string) ($refund['refund_reason'] ?? ''));
        $refundedAt = trim((string) ($refund['refunded_at'] ?? ($refund['gmt_refund_pay'] ?? '')));

        return [
            'kind' => 'refund',
            'headline' => '退款账单',
            'subheadline' => $refundedAt !== '' ? "退款时间：{$refundedAt}" : '该账单已完成退款。',
            'badge' => '退款',
            'highlight' => $refundMethodLabel !== '' ? $refundMethodLabel : '已退款',
            'remark' => $refundReason,
            'items' => [
                [
                    'description' => '原支付金额',
                    'amount' => $originalAmount,
                ],
                [
                    'description' => '退款金额',
                    'amount' => -1 * ($refundAmount > 0 ? $refundAmount : $originalAmount),
                ],
            ],
        ];
    }

    private function buildInvoiceSummary(Invoice $invoice, array $scene): array
    {
        $remark = trim((string) ($invoice->config_snapshot['remark'] ?? $invoice->coupon_snapshot['remark'] ?? ''));

        return [
            'headline' => (string) ($scene['headline'] ?? $this->resolveInvoiceTypeLabel((string) $invoice->type, $invoice)),
            'subheadline' => (string) ($scene['subheadline'] ?? ''),
            'badge' => (string) ($scene['badge'] ?? $this->resolveInvoiceTypeLabel((string) $invoice->type, $invoice)),
            'highlight' => (string) ($scene['highlight'] ?? $remark),
            'remark' => $remark,
        ];
    }

    private function buildOrderBasedSceneItems(Invoice $invoice): array
    {
        $items = [];
        $productName = trim((string) ($invoice->order?->display_product_name ?? ''));
        $billingCycle = trim((string) ($invoice->billing_cycle ?? $invoice->order?->billing_cycle ?? ''));
        $grossAmount = (float) ($invoice->amount ?? 0) + (float) ($invoice->discount ?? 0);

        if ($productName !== '') {
            $items[] = [
                'description' => $billingCycle !== '' ? "{$productName} / {$billingCycle}" : $productName,
                'amount' => $grossAmount,
            ];
        }

        if ((float) ($invoice->discount ?? 0) > 0) {
            $items[] = [
                'description' => '优惠抵扣',
                'amount' => -1 * (float) $invoice->discount,
            ];
        }

        return $items;
    }

    private function buildSceneInvoiceItems(Invoice $invoice, array $scene): array
    {
        if (($scene['kind'] ?? '') === 'refund' && ! empty($scene['items']) && is_array($scene['items'])) {
            return collect($scene['items'])->map(function ($item, $index) use ($invoice) {
                return [
                    'id' => (int) ($invoice->id * 100 + $index + 1),
                    'description' => (string) ($item['description'] ?? ''),
                    'amount' => number_format((float) ($item['amount'] ?? 0), 2, '.', ''),
                ];
            })->values()->all();
        }

        if (empty($scene['items']) || ! is_array($scene['items'])) {
            return [[
                'id' => (int) $invoice->id,
                'description' => $this->resolveInvoiceItemDescription($invoice),
                'amount' => number_format((float) $invoice->amount, 2, '.', ''),
            ]];
        }

        return collect($scene['items'])->map(function ($item, $index) use ($invoice) {
            return [
                'id' => (int) ($invoice->id * 100 + $index + 1),
                'description' => (string) ($item['description'] ?? ''),
                'amount' => number_format((float) ($item['amount'] ?? 0), 2, '.', ''),
            ];
        })->values()->all();
    }

    private function resolveInvoiceItemDescription(Invoice $invoice): string
    {
        $productName = trim((string) ($invoice->order?->display_product_name ?? ''));
        $billingCycle = trim((string) ($invoice->order?->billing_cycle ?? ''));
        $typeLabel = $this->resolveInvoiceTypeLabel((string) $invoice->type, $invoice);

        if ($productName === '') {
            return $typeLabel;
        }

        if ($billingCycle === '') {
            return $productName;
        }

        return "{$productName} / {$billingCycle}";
    }

    private function resolveInvoiceLogs(Invoice $invoice): array
    {
        if (! $invoice->order_id) {
            return [];
        }

        return OperationLog::query()
            ->where('module', 'order')
            ->where('subject_id', $invoice->order_id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (OperationLog $log) => [
                'id' => (int) $log->id,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
                'action' => $log->action,
                'detail' => $this->stringifyOperationDetail((array) ($log->detail ?? [])),
                'ip_address' => $log->ip_address,
            ])
            ->values()
            ->all();
    }

    private function stringifyOperationDetail(array $detail): string
    {
        if ($detail === []) {
            return '-';
        }

        $pairs = [];

        foreach ($detail as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $value = '';
            }

            $pairs[] = "{$key}: {$value}";
        }

        return implode(' | ', $pairs);
    }
}
