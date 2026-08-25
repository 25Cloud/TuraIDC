<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\UserService\DeleteUserServiceRequest;
use App\Http\Requests\Admin\V2\UserService\ListUserServicesRequest;
use App\Http\Requests\Admin\V2\UserService\ManualProvisionUserServiceRequest;
use App\Http\Requests\Admin\V2\UserService\RefreshUserServiceStatusesRequest;
use App\Http\Requests\Admin\V2\UserService\ShowUserServiceConnectionRequest;
use App\Http\Requests\Admin\V2\UserService\ShowUserServiceRemoteStatusRequest;
use App\Http\Requests\Admin\V2\UserService\ShowUserServiceRequest;
use App\Http\Requests\Admin\V2\UserService\StoreUserServiceRequest;
use App\Http\Requests\Admin\V2\UserService\UpdateUserServiceMetaRequest;
use App\Http\Resources\Admin\V2\AdminUserServiceListItemResource;
use App\Http\Resources\Admin\V2\AdminUserServiceRefreshResultResource;
use App\Http\Resources\Service\V2\ServiceConnectionResource;
use App\Http\Resources\Service\V2\ServiceDetailResource;
use App\Http\Resources\Service\V2\ServiceRuntimeResource;
use App\Models\User;
use App\Services\User\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserServiceController extends Controller
{
    public function __construct(
        private readonly UserService $users,
    ) {}

    public function index(ListUserServicesRequest $request, User $user): JsonResponse
    {
        $result = $this->users->services($user, $request->filters(), $request->perPage());

        return $this->success([
            'list' => collect((array) ($result['list'] ?? []))
                ->map(fn (mixed $item): array => AdminUserServiceListItemResource::make($item)->resolve())
                ->values()
                ->all(),
            'total' => (int) ($result['total'] ?? 0),
            'page' => (int) ($result['page'] ?? $request->integer('page', 1)),
            'page_size' => (int) ($result['page_size'] ?? $request->perPage()),
        ]);
    }

    public function store(StoreUserServiceRequest $request, User $user): JsonResponse
    {
        $detail = $this->users->createManualService($user, $request->payload(), $this->adminContext($request));

        return $this->success([
            'service' => ServiceDetailResource::make($detail)->resolve(),
        ], '服务创建成功');
    }

    public function show(ShowUserServiceRequest $request, User $user, int $service): JsonResponse
    {
        $detail = $this->users->serviceDetail(
            $user,
            $service,
            $request->boolean('refresh'),
            true
        );

        return $this->success([
            'service' => (new ServiceDetailResource($detail))->resolve(),
        ]);
    }

    public function connection(ShowUserServiceConnectionRequest $request, User $user, int $service): JsonResponse
    {
        $detail = $this->users->serviceBaseDetail($user, $service, true);

        return $this->success([
            'connection' => (new ServiceConnectionResource((array) ($detail['connection'] ?? [])))->resolve(),
        ]);
    }

    public function remoteStatus(ShowUserServiceRemoteStatusRequest $request, User $user, int $service): JsonResponse
    {
        $detail = $this->users->serviceRemoteStatusPatch($user, $service, true, true);

        return $this->success([
            'service' => ServiceRuntimeResource::make($detail)->resolve(),
        ]);
    }

    public function refreshStatuses(RefreshUserServiceStatusesRequest $request, User $user): JsonResponse
    {
        $payload = $request->validated();
        $result = $this->users->refreshServiceStatuses($user, (array) ($payload['service_ids'] ?? []));

        return $this->success([
            'result' => AdminUserServiceRefreshResultResource::make($result)->resolve(),
        ], '当前页实例状态已刷新');
    }

    public function updateMeta(UpdateUserServiceMetaRequest $request, User $user, int $service): JsonResponse
    {
        $detail = $this->users->updateServiceMeta($user, $service, $request->payload(), $this->adminContext($request));

        return $this->success([
            'service' => ServiceDetailResource::make($detail)->resolve(),
        ], '服务信息已更新');
    }

    public function manualProvision(ManualProvisionUserServiceRequest $request, User $user, int $service): JsonResponse
    {
        $detail = $this->users->manualProvisionService($user, $service, $request->payload(), $this->adminContext($request));

        return $this->success([
            'service' => ServiceDetailResource::make($detail)->resolve(),
        ], '已重新提交上游开通');
    }

    public function destroy(DeleteUserServiceRequest $request, User $user, int $service): JsonResponse
    {
        $this->users->deleteService($user, $service, $this->adminContext($request));

        return $this->success(null, '服务记录已删除');
    }

    /**
     * @return array<string, mixed>
     */
    private function adminContext(Request $request): array
    {
        return [
            'operator_id' => (int) ($request->user()?->id ?? 0),
            'operator_name' => (string) ($request->user()?->username ?? $request->user()?->name ?? $request->user()?->email ?? 'admin'),
            'actor_type' => 'admin',
            'actor_user_id' => (int) ($request->user()?->id ?? 0),
            'actor_name' => (string) ($request->user()?->username ?? $request->user()?->name ?? $request->user()?->email ?? 'admin'),
            'trace_id' => (string) ($request->header('X-Request-Id', '')),
            'ip_address' => (string) $request->ip(),
        ];
    }
}
