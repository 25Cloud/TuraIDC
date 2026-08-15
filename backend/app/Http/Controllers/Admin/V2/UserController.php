<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\User\DeleteUserRequest;
use App\Http\Requests\Admin\V2\User\ListOsOptionsRequest;
use App\Http\Requests\Admin\V2\User\ListUserBalanceLogsRequest;
use App\Http\Requests\Admin\V2\User\ListUserInvoicesRequest;
use App\Http\Requests\Admin\V2\User\ListUserNotificationLogsRequest;
use App\Http\Requests\Admin\V2\User\ListUserOperationLogsRequest;
use App\Http\Requests\Admin\V2\User\ListUsersRequest;
use App\Http\Requests\Admin\V2\User\ListUserTicketsRequest;
use App\Http\Requests\Admin\V2\User\LoginAsUserRequest;
use App\Http\Requests\Admin\V2\User\RechargeUserRequest;
use App\Http\Requests\Admin\V2\User\RefundUserInvoiceActionRequest;
use App\Http\Requests\Admin\V2\User\RefundUserServiceActionRequest;
use App\Http\Requests\Admin\V2\User\ServicePasswordResetActionRequest;
use App\Http\Requests\Admin\V2\User\ServicePowerActionRequest;
use App\Http\Requests\Admin\V2\User\ShowUserInvoiceRequest;
use App\Http\Requests\Admin\V2\User\ShowUserRequest;
use App\Http\Requests\Admin\V2\User\StoreUserRequest;
use App\Http\Requests\Admin\V2\User\UpdateUserRequest;
use App\Http\Requests\Admin\V2\User\UpdateUserStatusRequest;
use App\Http\Resources\Admin\V2\AdminActionResultResource;
use App\Http\Resources\Admin\V2\AdminInvoiceSummaryResource;
use App\Http\Resources\Admin\V2\AdminLoginAsResource;
use App\Http\Resources\Admin\V2\AdminOsOptionsResource;
use App\Http\Resources\Admin\V2\AdminUserDetailResource;
use App\Http\Resources\Admin\V2\AdminUserInvoiceDetailResource;
use App\Http\Resources\Admin\V2\AdminUserListItemResource;
use App\Http\Resources\Admin\V2\AdminUserNotificationLogResource;
use App\Http\Resources\Admin\V2\AdminUserOperationLogResource;
use App\Http\Resources\Admin\V2\AdminUserTicketResource;
use App\Http\Resources\Finance\FinanceLedgerResource;
use App\Models\User;
use App\Services\Admin\V2\AdminUserActionV2Service;
use App\Services\Auth\AuthService;
use App\Services\Finance\PaymentService;
use App\Services\User\UserService;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function __construct(
        private readonly AdminUserActionV2Service $actions,
        private readonly UserService $users,
        private readonly AuthService $auth,
        private readonly PaymentService $payments,
    ) {}

    public function index(ListUsersRequest $request): JsonResponse
    {
        $paginator = $this->users->list($request->filters(), $request->perPage());

        return $this->paginate($paginator, AdminUserListItemResource::class);
    }

    public function show(ShowUserRequest $request, User $user): JsonResponse
    {
        return $this->success(
            AdminUserDetailResource::make($this->users->detail($user))->resolve(),
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->users->create($request->payload());

        return $this->success(AdminUserListItemResource::make($user)->resolve(), '创建成功');
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user = $this->users->update($user, $request->payload());

        return $this->success(AdminUserListItemResource::make($user)->resolve(), '更新成功');
    }

    public function destroy(DeleteUserRequest $request, User $user): JsonResponse
    {
        // 资产保护：无在用服务、未付账单且余额为 0 才允许删除。
        $this->users->deleteUser($user, [
            'operator_id' => (int) ($request->user()?->id ?? 0),
            'operator_name' => (string) ($request->user()?->username ?? $request->user()?->name ?? $request->user()?->email ?? 'admin'),
            'trace_id' => (string) $request->header('X-Request-Id', ''),
            'ip_address' => (string) $request->ip(),
        ]);

        return $this->success(null, '删除成功');
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        $result = $this->actions->updateStatus($user, $request->enabled());

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function recharge(RechargeUserRequest $request, User $user): JsonResponse
    {
        $payload = $request->payload();
        $amount = (float) $payload['amount'];

        $documents = $this->payments->adjustBalance(
            $user,
            $amount,
            $payload['remark'] ?? '管理员手动调整',
            [
                'operator_type' => 'admin',
                'operator_id' => (int) ($request->user()?->id ?? 0),
                'operator_name' => (string) ($request->user()?->username ?? $request->user()?->name ?? $request->user()?->email ?? 'admin'),
                'trace_id' => (string) $request->header('X-Request-Id', ''),
            ],
        );

        $label = $amount > 0 ? '增加' : '扣减';

        return $this->success(AdminActionResultResource::make([
            'id' => (int) $user->id,
            'status' => 'completed',
            'message' => "余额{$label}成功",
            'detail' => [
                'type' => 'balance_adjustment',
                'user' => [
                    'id' => (int) $user->id,
                ],
                'adjustment' => [
                    'amount' => number_format($amount, 2, '.', ''),
                    'direction' => $amount > 0 ? 'increase' : 'decrease',
                ],
                'documents' => [
                    'invoice_id' => (int) $documents['invoice']->id,
                    'account_transaction_id' => (int) $documents['transaction']->id,
                    'recharge_record_id' => $documents['recharge_record']?->id,
                ],
            ],
        ])->resolve(), "余额{$label}成功");
    }

    public function loginAs(LoginAsUserRequest $request, User $user): JsonResponse
    {
        $payload = $this->auth->issueAdminLoginAsCode($user, [
            'admin_id' => (int) ($request->user()?->id ?? 0),
            'ip_address' => (string) $request->ip(),
            'user_agent' => (string) ($request->userAgent() ?? ''),
        ]);

        return $this->success(AdminLoginAsResource::make($payload)->resolve());
    }

    public function osOptions(ListOsOptionsRequest $request): JsonResponse
    {
        return $this->success(AdminOsOptionsResource::make($this->osOptionPayload())->resolve());
    }

    public function servicePower(ServicePowerActionRequest $request, User $user, int $service): JsonResponse
    {
        $result = $this->actions->servicePower($user, $service, $request->action(), $request);

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function resetServicePassword(ServicePasswordResetActionRequest $request, User $user, int $service): JsonResponse
    {
        $result = $this->actions->resetServicePassword($user, $service, $request->payload(), $request);

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function refundInvoice(RefundUserInvoiceActionRequest $request, User $user, int $invoice): JsonResponse
    {
        $result = $this->actions->refundInvoice($user, $invoice, $request->payload(), $request);

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function refundService(RefundUserServiceActionRequest $request, User $user, int $service): JsonResponse
    {
        $result = $this->actions->refundService($user, $service, $request->payload(), $request);

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function invoices(ListUserInvoicesRequest $request, User $user): JsonResponse
    {
        return $this->paginate(
            $this->users->invoices($user, $request->filters(), $request->perPage()),
            AdminInvoiceSummaryResource::class,
        );
    }

    public function invoiceDetail(ShowUserInvoiceRequest $request, User $user, int $invoice): JsonResponse
    {
        return $this->success(AdminUserInvoiceDetailResource::make($this->users->invoiceDetail($user, $invoice))->resolve());
    }

    public function balanceLogs(ListUserBalanceLogsRequest $request, User $user): JsonResponse
    {
        $result = $this->users->balanceLogs($user, $request->filters(), $request->perPage());

        return $this->paginate($result['paginator'], $result['resource_class'] ?? FinanceLedgerResource::class, [
            'summary' => $result['summary'] ?? [],
        ]);
    }

    public function tickets(ListUserTicketsRequest $request, User $user): JsonResponse
    {
        $result = $this->users->tickets($user, $request->filters(), $request->perPage());

        return $this->paginate($result['paginator'], AdminUserTicketResource::class, [
            'summary' => $result['summary'] ?? [],
        ]);
    }

    public function operationLogs(ListUserOperationLogsRequest $request, User $user): JsonResponse
    {
        return $this->paginate(
            $this->users->operationLogs((int) $user->id, $request->filters(), $request->perPage()),
            AdminUserOperationLogResource::class,
        );
    }

    public function smsLogs(ListUserNotificationLogsRequest $request, User $user): JsonResponse
    {
        return $this->paginate(
            $this->users->smsLogs($user, $request->perPage()),
            AdminUserNotificationLogResource::class,
        );
    }

    public function emailLogs(ListUserNotificationLogsRequest $request, User $user): JsonResponse
    {
        return $this->paginate(
            $this->users->emailLogs($user, $request->perPage()),
            AdminUserNotificationLogResource::class,
        );
    }

    /**
     * @return array{groups: list<array{label: string, children: list<array{label: string, value: string}>}>}
     */
    private function osOptionPayload(): array
    {
        return [
            'groups' => [
                [
                    'label' => 'CentOS',
                    'children' => [
                        ['label' => 'CentOS 7', 'value' => 'CentOS 7'],
                        ['label' => 'CentOS 8', 'value' => 'CentOS 8'],
                        ['label' => 'CentOS 9', 'value' => 'CentOS 9'],
                    ],
                ],
                [
                    'label' => 'Ubuntu',
                    'children' => [
                        ['label' => 'Ubuntu 20.04', 'value' => 'Ubuntu 20.04'],
                        ['label' => 'Ubuntu 22.04', 'value' => 'Ubuntu 22.04'],
                        ['label' => 'Ubuntu 24.04', 'value' => 'Ubuntu 24.04'],
                    ],
                ],
                [
                    'label' => 'Debian',
                    'children' => [
                        ['label' => 'Debian 11', 'value' => 'Debian 11'],
                        ['label' => 'Debian 12', 'value' => 'Debian 12'],
                    ],
                ],
                [
                    'label' => 'Rocky Linux',
                    'children' => [
                        ['label' => 'Rocky Linux 8', 'value' => 'Rocky Linux 8'],
                        ['label' => 'Rocky Linux 9', 'value' => 'Rocky Linux 9'],
                    ],
                ],
                [
                    'label' => 'AlmaLinux',
                    'children' => [
                        ['label' => 'AlmaLinux 8', 'value' => 'AlmaLinux 8'],
                        ['label' => 'AlmaLinux 9', 'value' => 'AlmaLinux 9'],
                    ],
                ],
                [
                    'label' => 'Windows Server',
                    'children' => [
                        ['label' => 'Windows Server 2019', 'value' => 'Windows Server 2019'],
                        ['label' => 'Windows Server 2022', 'value' => 'Windows Server 2022'],
                        ['label' => 'Windows Server 2025', 'value' => 'Windows Server 2025'],
                    ],
                ],
            ],
        ];
    }
}
