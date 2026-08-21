<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Log;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class CleanupLogActionRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:sms,email,api,admin_login,business_audit,schedule_run,ticket_upstream,task,runtime,system,all_db,all_file,all'],
            'keep_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'confirm_text' => ['required', 'string', 'in:立即清理'],
            'per_page' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->safe()->only([
            'type',
            'keep_days',
            'confirm_text',
        ]);
    }
}
