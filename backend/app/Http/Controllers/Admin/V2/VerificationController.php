<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Verification\ListVerificationHistoryRequest;
use App\Http\Requests\Admin\V2\Verification\ListVerificationsRequest;
use App\Http\Requests\Admin\V2\Verification\ShowVerificationRequest;
use App\Http\Requests\Admin\V2\Verification\UnbindVerificationRequest;
use App\Http\Resources\Admin\V2\AdminVerificationDetailResource;
use App\Http\Resources\Admin\V2\AdminVerificationHistoryItemResource;
use App\Http\Resources\Admin\V2\AdminVerificationListItemResource;
use App\Http\Resources\Admin\V2\AdminVerificationSummaryResource;
use App\Http\Resources\Admin\V2\AdminVerificationUnbindResource;
use App\Models\User;
use App\Services\Auth\AdminVerificationQueryService;
use App\Services\Auth\VerificationService;
use App\Services\System\OperationLogService;
use Illuminate\Http\JsonResponse;

class VerificationController extends Controller
{
    public function __construct(
        private readonly VerificationService $verificationService,
        private readonly OperationLogService $operationLogService,
        private readonly AdminVerificationQueryService $verificationQueryService,
    ) {}

    public function index(ListVerificationsRequest $request): JsonResponse
    {
        return $this->paginate(
            $this->verificationQueryService->paginate($request->filters(), $request->pageSize()),
            AdminVerificationListItemResource::class
        );
    }

    public function summary(): JsonResponse
    {
        return $this->success((new AdminVerificationSummaryResource(
            $this->verificationQueryService->summary()
        ))->resolve());
    }

    public function show(ShowVerificationRequest $request, User $user): JsonResponse
    {
        return $this->success((new AdminVerificationDetailResource(
            $this->verificationQueryService->detail($user)
        ))->resolve());
    }

    public function history(ListVerificationHistoryRequest $request, User $user): JsonResponse
    {
        $history = $this->verificationQueryService->history($user);
        $items = collect(is_array($history['list'] ?? null) ? $history['list'] : []);
        $page = $request->page();
        $pageSize = $request->pageSize();
        $list = $items
            ->slice(($page - 1) * $pageSize, $pageSize)
            ->map(fn (array $item): array => (new AdminVerificationHistoryItemResource($item))->resolve())
            ->values()
            ->all();

        return $this->success([
            'user_name' => (string) ($history['user_name'] ?? ''),
            'list' => $list,
            'total' => $items->count(),
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }

    public function unbind(User $user, UnbindVerificationRequest $request): JsonResponse
    {
        $payload = $request->validated();

        $adminUser = $request->user();
        $adminUserId = $adminUser?->id;
        $adminName = $adminUser?->name ?? $adminUser?->username;

        $result = $this->verificationService->unbind(
            $user,
            $adminUserId,
            $adminName,
            (string) $payload['reject_reason']
        );

        $this->operationLogService->write(
            userId: $adminUserId,
            userType: 'admin',
            action: 'verification.unbind',
            module: 'verification',
            targetId: $user->id,
            detail: [
                'user_id' => $user->id,
                'user_name' => $user->display_name,
                'real_name' => $result['real_name'],
                'unbound_at' => $result['unbound_at'],
                'operator' => $result['operator'],
                'reject_reason' => $result['reject_reason'],
            ],
            ipAddress: $request->ip(),
        );

        return $this->success(
            (new AdminVerificationUnbindResource($result))->resolve(),
            '驳回成功'
        );
    }
}
