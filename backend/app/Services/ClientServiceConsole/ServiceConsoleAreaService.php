<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole;

use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 自定义控制台区域子服务
 *
 * 兼容智简魔方财务等上游「自定义 HTML / 自定义 tab」的对接方式：
 *   - 上游 /host/header 下发 module_button / module_client_area / module_chart；
 *   - module_client_area（以及 select=client_area 的模块）即产品自定义 tab，
 *     通过 GET /provision/custom/content?id={hostId}&key={moduleKey} 拉取其 HTML；
 *   - 页面内提交动作指向 MODULE_CUSTOM_API（通常为 /provision/custom/{hostId}）。
 *
 * 本服务把这些能力归一为：
 *   - capabilitiesForUser：下发可用自定义区域（areas）、NAT 能力、监控能力；
 *   - areaTicketForUser：签发短时效 iframe 访问票据（iframe 无法携带 Authorization 头）；
 *   - areaContentForTicket：抓取并改写上游 HTML（动作地址重写为本地代理）；
 *   - submitAreaActionForTicket：将页面内动作回发上游并原样返回结果。
 */
class ServiceConsoleAreaService
{
    private const AREA_TICKET_CACHE_PREFIX = 'service_console:area_ticket:';

    private const AREA_TICKET_TTL_SECONDS = 600;

    private const CAPABILITIES_CACHE_TTL_SECONDS = 300;

    private const RAW_CONTENT_CACHE_TTL_SECONDS = 30;

    private const NAT_MODULE_KEYWORDS = [
        'nat_acl', 'natacl', 'nat转发', 'nat 转发', '端口转发', '端口映射', '端口转发规则',
    ];

    public function __construct(
        private readonly ServiceDetailService $detailService,
    ) {}

    // ── 能力下发 ───────────────────────────────────────────────────────────

    /**
     * 返回当前服务支持的控制台能力：
     *   supported / error / areas / nat_supported / monitor_supported / fetchable。
     * 非可控上游或上游拉取失败时不抛错，由调用方按默认 tab 渲染。
     *
     * @return array<string, mixed>
     */
    public function capabilitiesForUser(User $user, int $serviceId): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,console_template',
            'product.supplier',
        ]);

        if (! $this->detailService->transformService->canManageService($service)) {
            return $this->capabilityPayload(false, '');
        }

        $cacheKey = $this->buildCapabilitiesCacheKey($service);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
            $modules = $this->detailService->fetchSupportedModules($supplier, $hostId, $jwt);

            $capabilities = $this->deriveModuleCapabilities($modules, $hostId);
            $capabilities['supported'] = true;
            $capabilities['fetchable'] = is_callable([$runtime, 'fetchCustomModulePage']);

            Cache::put($cacheKey, $capabilities, now()->addSeconds(self::CAPABILITIES_CACHE_TTL_SECONDS));

            return $capabilities;
        } catch (\Throwable $exception) {
            Log::warning('[服务控制台] 读取自定义区域能力失败', [
                'service_id' => (int) $service->id,
                'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
            ]);

            $payload = $this->capabilityPayload(false, '读取上游功能面板失败，请稍后重试');
            Cache::put($cacheKey, $payload, now()->addSeconds(self::CAPABILITIES_CACHE_TTL_SECONDS));

            return $payload;
        }
    }

    // ── iframe 访问票据 ───────────────────────────────────────────────────

    /**
     * iframe 直接加载 / 回发无法携带 Authorization 请求头，签发短时效票据供其使用。
     *
     * @return array<string, mixed>
     */
    public function areaTicketForUser(User $user, int $serviceId): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,console_template',
            'product.supplier',
        ]);

        throw_if(
            ! $this->detailService->transformService->canManageService($service),
            new BusinessException('当前服务暂不支持自定义功能面板', 42200)
        );

        $ticket = Str::random(48);
        Cache::put(
            self::AREA_TICKET_CACHE_PREFIX.$ticket,
            ['user_id' => (int) $user->id, 'service_id' => (int) $service->id],
            now()->addSeconds(self::AREA_TICKET_TTL_SECONDS)
        );

        return [
            'ticket' => $ticket,
            'expires_in' => self::AREA_TICKET_TTL_SECONDS,
        ];
    }

    /**
     * 拉取自定义区域 HTML（上游渲染后返回），并将动作地址改写为本地代理地址。
     *
     * @return array{html: string}
     */
    public function areaContentForTicket(string $ticket, int $serviceId, string $moduleKey): array
    {
        $service = $this->resolveTicketService($ticket, $serviceId);
        $moduleKey = trim($moduleKey);

        throw_if($moduleKey === '', new BusinessException('自定义功能标识不能为空', 42200));

        [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);

        throw_if(
            ! is_callable([$runtime, 'fetchCustomModulePage']),
            new BusinessException('当前上游不支持自定义功能页面', 42200)
        );

        $rawCacheKey = $this->buildRawContentCacheKey($service, $moduleKey);
        $rawHtml = Cache::remember(
            $rawCacheKey,
            now()->addSeconds(self::RAW_CONTENT_CACHE_TTL_SECONDS),
            function () use ($runtime, $supplier, $hostId, $jwt, $moduleKey): string {
                return (string) $runtime->fetchCustomModulePage($supplier, $hostId, $moduleKey, $jwt);
            }
        );

        $html = $this->rewriteModulePage($rawHtml, $hostId, $supplier, $ticket);

        return ['html' => $html];
    }

    /**
     * 将自定义区域页面内提交的动作原样回发上游，并原样返回其结果
     * （上游模块 JS 通常按 {status, msg, data} 约定读取）。
     *
     * @return array<string, mixed>
     */
    public function submitAreaActionForTicket(string $ticket, int $serviceId, array $data): array
    {
        $service = $this->resolveTicketService($ticket, $serviceId);

        [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
        $rootUrl = $this->detailService->resolveSupplierRootUrl($supplier);
        $endpoint = rtrim($rootUrl, '/').'/provision/custom/'.$hostId;

        try {
            return is_callable([$runtime, 'submitCustomModuleAction'])
                ? $runtime->submitCustomModuleAction($supplier, $endpoint, $data, $jwt)
                : $runtime->post(
                    $supplier,
                    $endpoint,
                    $data,
                    $jwt,
                    ['content-type: application/x-www-form-urlencoded']
                );
        } catch (\Throwable $exception) {
            Log::warning('[服务控制台] 自定义区域动作回发失败', [
                'service_id' => (int) $service->id,
                'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
            ]);

            return [
                'status' => 422,
                'msg' => '操作失败，请稍后重试',
                'data' => [],
            ];
        }
    }

    // ── 内部实现 ──────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function capabilityPayload(bool $supported, string $error): array
    {
        return [
            'supported' => $supported,
            'error' => $error,
            'areas' => [],
            'nat_supported' => false,
            'monitor_supported' => false,
            'fetchable' => false,
        ];
    }

    /**
     * 从上游模块列表中推导可用能力。
     *
     * @param  array<int, mixed>  $modules
     * @return array{areas: array<int, array{key: string, name: string}>, nat_supported: bool, monitor_supported: bool}
     */
    private function deriveModuleCapabilities(array $modules, int $hostId): array
    {
        $areas = [];
        $areaKeys = [];
        $natSupported = false;
        $monitorSupported = false;

        foreach ($modules as $module) {
            if (! is_array($module)) {
                continue;
            }

            $type = trim((string) ($module['type'] ?? ''));
            $function = trim((string) ($module['function'] ?? $module['func'] ?? ''));
            $name = trim((string) ($module['name'] ?? ''));
            $select = $module['select'] ?? '';
            $selectKey = is_array($select) ? '' : trim((string) $select);

            if ($function === '') {
                continue;
            }

            // NAT 转发模块由内置「端口转发」tab 承载，不重复渲染为自定义区域。
            if ($this->matchesNatModule($function, $name, $type)) {
                $natSupported = true;
                continue;
            }

            if ($function === 'charts') {
                $monitorSupported = true;
                continue;
            }

            $isClientArea = $selectKey === 'client_area' || $type === 'custom';
            if (! $isClientArea) {
                continue;
            }

            if (isset($areaKeys[$function])) {
                continue;
            }

            $areaKeys[$function] = true;
            $areas[] = [
                'key' => $function,
                'name' => $name !== '' ? $name : $function,
            ];
        }

        return [
            'areas' => $areas,
            'nat_supported' => $natSupported,
            'monitor_supported' => $monitorSupported,
        ];
    }

    private function matchesNatModule(string $function, string $name, string $type): bool
    {
        $text = $this->normalizeKeywordText(implode(' ', array_filter([$function, $name])));

        if ($text === '') {
            return false;
        }

        if ($type !== '' && strtolower($type) !== 'custom') {
            return false;
        }

        foreach (self::NAT_MODULE_KEYWORDS as $keyword) {
            if (str_contains($text, $this->normalizeKeywordText($keyword))) {
                return true;
            }
        }

        return false;
    }

    private function normalizeKeywordText(string $value): string
    {
        return preg_replace('/\s+/u', '', mb_strtolower(trim($value), 'UTF-8')) ?? '';
    }

    /**
     * 校验票据并取出所属服务（同时校验服务归属与票据作用域一致）。
     */
    private function resolveTicketService(string $ticket, int $serviceId): Service
    {
        $ticket = trim($ticket);

        throw_if($ticket === '', new BusinessException('访问凭证缺失，请刷新页面重试', 40301, 403));

        $context = Cache::get(self::AREA_TICKET_CACHE_PREFIX.$ticket);
        throw_if(
            ! is_array($context) || (int) ($context['service_id'] ?? 0) !== $serviceId,
            new BusinessException('访问凭证无效或已过期，请刷新页面重试', 40301, 403)
        );

        $service = Service::query()
            ->with([
                'product:id,product_type,service_type_code,product_group_id,console_template',
                'product.supplier',
            ])
            ->where('user_id', (int) ($context['user_id'] ?? 0))
            ->find($serviceId);

        throw_if(! $service, new BusinessException('服务不存在', 40400, 404));

        return $service;
    }

    /**
     * 将上游 HTML 中的自定义动作地址改写为本地代理地址。
     * 上游页面通过 {$MODULE_CUSTOM_API} 注入动作地址，渲染后通常为
     * https://上游域名/provision/custom/{hostId}；统一改写成相对路径
     * 「actions?ticket=…」（相对内容页所在目录），浏览器在 iframe 内可同源回发。
     */
    private function rewriteModulePage(string $html, int $hostId, Supplier $supplier, string $ticket): string
    {
        $relativeActionUrl = 'actions?ticket='.rawurlencode($ticket);
        $rootUrl = $this->detailService->resolveSupplierRootUrl($supplier);

        // 已知的完整动作地址（本供应商 rootUrl + /provision/custom/{hostId}）
        $exactEndpoint = preg_quote(rtrim($rootUrl, '/').'/provision/custom/'.$hostId, '~');
        $html = (string) preg_replace('~https?://'.$exactEndpoint.'~iu', $relativeActionUrl, $html);

        // 兜底：任意协议头/域名的 /provision/custom/{hostId} 绝对地址
        $hostIdQuoted = preg_quote((string) $hostId, '~');
        $html = (string) preg_replace(
            '~https?://[^\s"\'\<\>\\\\]*?/provision/custom/'.$hostIdQuoted.'~iu',
            $relativeActionUrl,
            $html
        );

        // 兜底：未渲染的模板变量
        return str_replace('{$MODULE_CUSTOM_API}', $relativeActionUrl, $html);
    }

    private function buildCapabilitiesCacheKey(Service $service): string
    {
        return 'service_console:capabilities:'.$service->id.':'.$service->user_id;
    }

    private function buildRawContentCacheKey(Service $service, string $moduleKey): string
    {
        return 'service_console:area_content:'.$service->id.':'.$moduleKey;
    }
}
