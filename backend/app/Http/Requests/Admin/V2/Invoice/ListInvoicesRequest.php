<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Invoice;

use App\Constants\InvoiceType;
use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Http\Requests\Concerns\HasDateRangeFilter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ListInvoicesRequest extends AdminFormRequest
{
    use HasDateRangeFilter;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 筛选字段改 nullable：前端清空筛选项会显式传 null，sometimes 遇 null 仍执行 string/integer 规则直接 422
            'keyword' => ['sometimes', 'nullable', 'string', 'max:80'],
            'invoice_no' => ['sometimes', 'nullable', 'string', 'max:80'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'nullable', 'integer'],
            'type' => ['sometimes', 'nullable', 'string', Rule::in($this->allowedTypes())],
            'product_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_range' => ['prohibited'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator): mixed => $this->validateDateRange($validator));
    }

    /**
     * @return list<string>
     */
    private function allowedTypes(): array
    {
        return [
            InvoiceType::NEW_PURCHASE,
            'normal',
            InvoiceType::RENEW,
            InvoiceType::RECHARGE,
            InvoiceType::UPGRADE,
            InvoiceType::DEDUCTION,
            // 退款红字账单：UserService 早已产出该类型，且管理端筛选项由
            // shared/statusConfig.js 的 INVOICE_TYPE_MAP 生成（本次补了 refund 映射），
            // 校验白名单漏配会让「退款」筛选直接 422，列表不可用。
            InvoiceType::REFUND,
            InvoiceType::REFERRAL_CREDIT,
            InvoiceType::MANUAL,
        ];
    }
}
