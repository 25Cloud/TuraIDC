<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Staff\CreateStaffRequest;
use App\Http\Requests\Admin\V2\Staff\DeleteStaffRequest;
use App\Http\Requests\Admin\V2\Staff\ListStaffRequest;
use App\Http\Requests\Admin\V2\Staff\ListStaffRolesRequest;
use App\Http\Requests\Admin\V2\Staff\ResetStaffPasswordRequest;
use App\Http\Requests\Admin\V2\Staff\ShowStaffRequest;
use App\Http\Requests\Admin\V2\Staff\UpdateStaffRequest;
use App\Http\Requests\Admin\V2\Staff\UpdateStaffStatusRequest;
use App\Http\Resources\Admin\V2\AdminActionResultResource;
use App\Http\Resources\Admin\V2\AdminStaffDetailResource;
use App\Http\Resources\Admin\V2\AdminStaffListItemResource;
use App\Http\Resources\Admin\V2\AdminStaffRoleOptionResource;
use App\Models\AdminUser;
use App\Services\Admin\Rbac\AdminStaffService;
use App\Services\Admin\V2\AdminRbacActionV2Service;

class StaffController extends Controller
{
    public function __construct(
        private readonly AdminRbacActionV2Service $actions,
        private readonly AdminStaffService $staffService,
    ) {}

    public function index(ListStaffRequest $request)
    {
        return $this->paginate(
            $this->staffService->list($request->filters(), $request->perPage()),
            AdminStaffListItemResource::class,
        );
    }

    public function roles(ListStaffRolesRequest $request)
    {
        return $this->success([
            'list' => AdminStaffRoleOptionResource::collection($this->staffService->roleOptions())->resolve(),
        ]);
    }

    public function show(ShowStaffRequest $request, AdminUser $staff)
    {
        $staff->load('role');

        return $this->success(AdminStaffDetailResource::make($staff)->resolve());
    }

    public function store(CreateStaffRequest $request)
    {
        $staff = $this->staffService->create(
            data: $request->payload(),
            operator: $request->user(),
            ipAddress: $request->ip(),
        );

        return $this->success(AdminStaffDetailResource::make($staff)->resolve(), '员工创建成功');
    }

    public function update(UpdateStaffRequest $request, AdminUser $staff)
    {
        $staff = $this->staffService->update(
            staff: $staff,
            data: $request->payload(),
            operator: $request->user(),
            ipAddress: $request->ip(),
        );

        return $this->success(AdminStaffDetailResource::make($staff)->resolve(), '员工更新成功');
    }

    public function updateStatus(UpdateStaffStatusRequest $request, AdminUser $staff)
    {
        $result = $this->actions->updateStaffStatus($staff, $request->enabled(), $request->user(), $request->ip());

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function resetPassword(ResetStaffPasswordRequest $request, AdminUser $staff)
    {
        $result = $this->actions->resetStaffPassword($staff, $request->password(), $request->user(), $request->ip());

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function destroy(DeleteStaffRequest $request, AdminUser $staff)
    {
        $this->staffService->deleteDisabled(
            staff: $staff,
            operator: $request->user(),
            ipAddress: $request->ip(),
        );

        return $this->success(null, '员工已删除');
    }
}
