<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\MemberLevel\CreateMemberLevelRequest;
use App\Http\Requests\Admin\V2\MemberLevel\DeleteMemberLevelRequest;
use App\Http\Requests\Admin\V2\MemberLevel\ListMemberLevelsRequest;
use App\Http\Requests\Admin\V2\MemberLevel\UpdateMemberLevelRequest;
use App\Http\Resources\Admin\V2\AdminMemberLevelDetailResource;
use App\Http\Resources\Admin\V2\AdminMemberLevelListItemResource;
use App\Models\MemberLevel;
use App\Services\Referral\MemberLevelService;

class MemberLevelController extends Controller
{
    public function __construct(private readonly MemberLevelService $memberLevels) {}

    public function index(ListMemberLevelsRequest $request)
    {
        return $this->success([
            'list' => AdminMemberLevelListItemResource::collection($this->memberLevels->list())->resolve(),
        ]);
    }

    public function store(CreateMemberLevelRequest $request)
    {
        $level = $this->memberLevels->create($request->payload());

        return $this->success(AdminMemberLevelDetailResource::make($level)->resolve(), '等级创建成功');
    }

    public function update(UpdateMemberLevelRequest $request, MemberLevel $memberLevel)
    {
        $level = $this->memberLevels->update($memberLevel, $request->payload());

        return $this->success(AdminMemberLevelDetailResource::make($level)->resolve(), '等级更新成功');
    }

    public function destroy(DeleteMemberLevelRequest $request, MemberLevel $memberLevel)
    {
        $this->memberLevels->delete($memberLevel);

        return $this->success(null, '等级删除成功');
    }
}
