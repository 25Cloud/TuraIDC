<?php

namespace App\Http\Requests\Client\V2\Auth;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class UpdateNotificationPreferencesRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'login_notify' => 'required|boolean',
            'login_location_alert' => 'required|boolean',
            'password_change_alert' => 'required|boolean',
            'phone_change_alert' => 'required|boolean',
            'email_change_alert' => 'required|boolean',
            'marketing_alert' => 'required|boolean',
        ];
    }
}
