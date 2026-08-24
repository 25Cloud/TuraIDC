<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Ticket;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Models\Setting;
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
            'block_non_whitelisted' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{allowed_ips: string, rate_limit: int, block_non_whitelisted: bool}
     */
    public function payload(): array
    {
        return [
            'allowed_ips' => trim((string) ($this->validated('allowed_ips') ?? Setting::getValue(
                'ticket_upstream',
                'allowed_ips',
                (string) config('ticket_upstream.upload_allowed_ips', '')
            ))),
            'rate_limit' => (int) ($this->validated('rate_limit') ?? Setting::getValue(
                'ticket_upstream',
                'rate_limit',
                (string) config('ticket_upstream.upload_rate_limit', 30)
            )),
            'block_non_whitelisted' => array_key_exists('block_non_whitelisted', $this->validated())
                ? (bool) $this->validated('block_non_whitelisted')
                : filter_var(
                    (string) Setting::getValue(
                        'ticket_upstream',
                        'block_non_whitelisted',
                        config('ticket_upstream.upload_block_non_whitelisted', false)
                    ),
                    FILTER_VALIDATE_BOOLEAN
                ),
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
