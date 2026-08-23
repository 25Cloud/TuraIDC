<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\AgentDiscount\AgentGroupRequest;
use App\Http\Resources\Admin\V2\AdminAgentGroupListItemResource;
use App\Models\AgentGroup;
use App\Services\Finance\AdminAgentDiscountService;

class AgentGroupController extends Controller
{
    public function __construct(private readonly AdminAgentDiscountService $service) {}

    public function index()
    {
        return $this->success(['list' => AdminAgentGroupListItemResource::collection($this->service->listAgentGroups())->resolve()]);
    }

    public function store(AgentGroupRequest $request)
    {
        return $this->success(AdminAgentGroupListItemResource::make($this->service->saveAgentGroup(null, $request->payload()))->resolve(), '代理组创建成功');
    }

    public function update(AgentGroupRequest $request, AgentGroup $agentGroup)
    {
        return $this->success(AdminAgentGroupListItemResource::make($this->service->saveAgentGroup($agentGroup, $request->payload()))->resolve(), '代理组更新成功');
    }

    public function destroy(AgentGroup $agentGroup)
    {
        $this->service->deleteAgentGroup($agentGroup);

        return $this->success(null, '代理组删除成功');
    }
}
