<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\ZjmfBridge\Http\Controllers;

use App\Http\Controllers\Controller;
use TuraIDC\Plugins\Addons\ZjmfBridge\Models\AgentApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AgentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $existing = AgentApplication::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'approved'])
            ->first();

        if ($existing) {
            if ($existing->status === 'pending') {
                $data = ['status' => 'approved'];
                if (empty($existing->api_key)) {
                    $data['api_key'] = $this->generateApiKey();
                }
                $existing->update($data);
                return $this->success(null, '代理申请已通过');
            }
            return $this->error(40900, '您已是代理，无需重复申请');
        }

        AgentApplication::query()->create([
            'user_id' => $user->id,
            'status' => 'approved',
            'api_key' => $this->generateApiKey(),
        ]);

        return $this->success(null, '代理申请已通过');
    }

    public function info(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $application = AgentApplication::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'approved'])
            ->first();

        $isAgent = $application !== null;
        $account = $user->email ?: ($user->phone ?: '');

        $consoleUrl = rtrim((string) config('app.client_console_url', ''), '/');
        $basePath = trim((string) config('zjmf_bridge.base_path', 'zjmf/v1'), '/');

        return $this->success([
            'is_agent' => $isAgent,
            'api_url' => $isAgent ? "{$consoleUrl}/{$basePath}" : '',
            'app_id' => $isAgent ? $account : '',
            'secret' => $isAgent ? (string) ($application->api_key ?? '') : '',
        ]);
    }

    private function generateApiKey(): string
    {
        return bin2hex(random_bytes(32));
    }
}
