<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Ticket;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Closure;

final class SaveTicketUpstreamUploadGuardRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'allowed_ips' => [
                'nullable',
                'string',
                'max:2000',
                fn (string $attribute, mixed $value, Closure $fail) => $this->validateAllowedIps($value, $fail),
            ],
            'rate_limit' => ['required', 'integer', 'min:0', 'max:10000'],
        ];
    }

    /**
     * @return array{allowed_ips: string, rate_limit: int}
     */
    public function payload(): array
    {
        return [
            'allowed_ips' => trim((string) ($this->validated('allowed_ips') ?? '')),
            'rate_limit' => (int) $this->validated('rate_limit'),
        ];
    }

    private function validateAllowedIps(mixed $value, Closure $fail): void
    {
        $items = preg_split('/[\s,]+/', trim((string) ($value ?? ''))) ?: [];
        foreach ($items as $item) {
            $entry = trim($item);
            if ($entry === '') {
                continue;
            }
            if (str_contains($entry, '/')) {
                [$subnet, $bits] = array_pad(explode('/', $entry, 2), 2, '');
                if (! filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                    || ! ctype_digit($bits)
                    || (int) $bits < 0
                    || (int) $bits > 32
                ) {
                    $fail('白名单 IP 格式不正确（支持 IPv4、IPv4/CIDR，用逗号或换行分隔）');

                    return;
                }
            } elseif (filter_var($entry, FILTER_VALIDATE_IP) === false) {
                $fail('白名单 IP 格式不正确（支持 IPv4、IPv4/CIDR，用逗号或换行分隔）');

                return;
            }
        }
    }
}
