<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\ZjmfBridge\Http\Middleware;

use TuraIDC\Plugins\Addons\ZjmfBridge\Services\ZjmfResponseFactory;
use TuraIDC\Plugins\Addons\ZjmfBridge\Services\ZjmfSignatureService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyZjmfSignature
{
    public function __construct(
        private readonly ZjmfSignatureService $signatures,
        private readonly ZjmfResponseFactory $responses,
    ) {}

    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $result = $this->signatures->verify($request, $scope);

        if (! $result['ok']) {
            return $this->responses->error(
                (int) ($result['status'] ?? 401),
                (string) ($result['message'] ?? '签名校验失败'),
                ['trace_id' => $request->attributes->get('trace_id')],
                (int) ($result['http_status'] ?? 401)
            );
        }

        $request->attributes->set('zjmf_app_id', $result['app_id'] ?? '');
        $request->attributes->set('zjmf_scope', $scope);

        return $next($request);
    }
}
