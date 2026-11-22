<?php

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Closure;

class ListUserServicesRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'keyword' => ['nullable', 'string', 'max:100'],
            // status 既接受单值也接受数组：管理端用户详情的状态筛选默认多选
            // （开通中/运行中/已暂停/异常），单值保留给既有调用方。
            // 两种形态都必须校验值域：只写 nullable 会让 status=abc 通过校验，
            // 服务层再 (int) 强转成 0，静默返回「开通中」列表而不是报错。
            'status' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_array($value) || $value === null || $value === '') {
                    return;
                }
                if (filter_var($value, FILTER_VALIDATE_INT) === false || ! in_array((int) $value, [0, 1, 2, 3, 4], true)) {
                    $fail('状态筛选值不合法。');
                }
            }],
            'status.*' => ['integer', 'in:0,1,2,3,4'],
        ], $this->legacyPaginationRules());
    }

    public function filters(): array
    {
        return $this->safe()->only(['keyword', 'status']);
    }
}
