<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Redis;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class FlushRedisCacheRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            // 清理缓存是整库动作，没有参数，也不接受分页之类的旧参数。
            'database' => ['prohibited'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }
}
