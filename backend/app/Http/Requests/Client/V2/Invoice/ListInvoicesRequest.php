<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Invoice;

use App\Constants\InvoiceType;
use App\Http\Requests\Concerns\HasDateRangeFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ListInvoicesRequest extends FormRequest
{
    use HasDateRangeFilter;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'integer'],
            'type' => ['sometimes', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                $types = array_values(array_filter(
                    array_map('trim', explode(',', (string) $value)),
                    static fn (string $type): bool => $type !== ''
                ));

                if ($types === []) {
                    $fail('账单类型不能为空。');

                    return;
                }

                foreach ($types as $type) {
                    if (! in_array($type, $this->allowedTypes(), true)) {
                        $fail('账单类型不正确。');

                        return;
                    }
                }
            }],
            'keyword' => ['sometimes', 'string', 'max:80'],
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'date_format:Y-m-d'],
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
            // 与管理端同步补齐：当前控制台筛选项是硬编码窄集合、不含 refund 因此不可达，
            // 但用户端账单列表本身会出现退款账单，先把校验口径对齐，避免以后放开筛选时复现同一缺陷。
            InvoiceType::REFUND,
            InvoiceType::REFERRAL_CREDIT,
            InvoiceType::MANUAL,
        ];
    }
}
