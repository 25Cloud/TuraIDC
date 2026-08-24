<?php

declare(strict_types=1);

namespace App\Http\Controllers\Open\V2;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpenFinanceController extends Controller
{
    public function balance(Request $request): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');

        return $this->success([
            'balance' => (string) $user->balance,
        ]);
    }
}
