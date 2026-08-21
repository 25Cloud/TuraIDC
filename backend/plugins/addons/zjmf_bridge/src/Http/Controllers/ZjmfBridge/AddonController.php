<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\ZjmfBridge\Http\Controllers\ZjmfBridge;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Services\Integrations\Plugins\PluginDomain;
use App\Services\Integrations\Plugins\PluginRuntimeRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use TuraIDC\Plugins\Addons\ZjmfBridge\Services\ZjmfErrorMapper;
use TuraIDC\Plugins\Addons\ZjmfBridge\Services\ZjmfResponseFactory;

class AddonController extends Controller
{
    private const JSON_ENCODING_OPTIONS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function __construct(
        private readonly PluginRuntimeRegistry $plugins,
        private readonly ZjmfResponseFactory $responses,
        private readonly ZjmfErrorMapper $errors,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        try {
            $result = $this->plugins->execute(
                PluginDomain::ADDONS,
                'zjmf_bridge',
                'zjmf.dispatch',
                $this->payload($request),
                $this->context($request),
            );

            return $this->pluginResponse($result);
        } catch (BusinessException $exception) {
            return $this->responses->error(
                $this->errors->fromTuraIDCCode($exception->getErrorCode()),
                $exception->getMessage()
            );
        } catch (\Throwable) {
            return $this->responses->error(500, 'ZJMF Bridge 处理失败');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $route = $request->route();

        return [
            'method' => strtoupper($request->getMethod()),
            'path' => $request->getPathInfo(),
            'route_name' => $route instanceof LaravelRoute ? (string) $route->getName() : '',
            'route_uri' => $route instanceof LaravelRoute ? (string) $route->uri() : '',
            'route_parameters' => $this->routeParameters($route),
            'query' => $request->query->all(),
            'body' => $request->request->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Request $request): array
    {
        return [
            'trace_id' => (string) $request->attributes->get('trace_id', ''),
            'actor_type' => (string) $request->attributes->get('zjmf_actor_type', 'guest'),
            'actor_id' => (int) $request->attributes->get('zjmf_user_id', 0),
            'zjmf_user_id' => (int) $request->attributes->get('zjmf_user_id', 0),
            'zjmf_app_id' => (string) $request->attributes->get('zjmf_app_id', ''),
            'zjmf_scope' => (string) $request->attributes->get('zjmf_scope', ''),
            'ip_address' => (string) $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function routeParameters(mixed $route): array
    {
        if (! $route instanceof LaravelRoute) {
            return [];
        }

        $parameters = [];
        foreach ($route->parametersWithoutNulls() as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $parameters[$key] = $value;
            }
        }

        return $parameters;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function pluginResponse(array $result): JsonResponse
    {
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $body = is_array($data['body'] ?? null) ? $data['body'] : null;

        if ($body !== null) {
            return response()->json(
                $body,
                $this->httpStatus((int) ($data['http_status'] ?? 200)),
                [],
                self::JSON_ENCODING_OPTIONS
            );
        }

        if (! (bool) ($result['success'] ?? false)) {
            return $this->responses->error(422, (string) ($result['message'] ?? 'ZJMF Bridge Addon 执行失败'));
        }

        return $this->responses->success($data, (string) ($result['message'] ?? 'success'));
    }

    private function httpStatus(int $status): int
    {
        return $status >= 100 && $status <= 599 ? $status : 200;
    }
}
