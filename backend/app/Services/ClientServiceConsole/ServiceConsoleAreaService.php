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

    /** 与 ServiceSecurityGroupService 的内置 tab 关键字保持一致，避免两者对同一模块判定不同 */
    private const SECURITY_GROUP_MODULE_KEYWORDS = [
        '安全组', '安全', 'securitygroup', 'security_group', 'security-group', 'secgroup', 'firewall', 'acl',
    ];

    public function __construct(
        private readonly ServiceDetailService $detailService,
        private readonly ServiceConsoleAssetService $assetService,
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

        if (! $this->detailService->canManageService($service)) {
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
            ! $this->detailService->canManageService($service),
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

    /**
     * 取面板引用的上游静态资源（经本系统反代 + 缓存）。
     *
     * 面板片段里的 CSS/JS 原本写的是上游绝对地址，改写后走这里，终端用户只看到
     * 本系统域名；目标主机由服务绑定的供应商推导，不接受调用方指定，避免被当成开放反代。
     *
     * @return array{body: string, content_type: string}
     */
    public function areaAssetForTicket(string $ticket, int $serviceId, string $path): array
    {
        $service = $this->resolveTicketService($ticket, $serviceId);
        [$supplier] = $this->detailService->resolveManagedSupplierAndHost($service);

        return $this->assetService->fetch(
            $supplier,
            $this->detailService->resolveSupplierRootUrl($supplier),
            $path
        );
    }

    /**
     * 把下游提交的面板动作原样转发给供应商（中间层行为，回复下游）。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null 供应商不可用时返回 null，由调用方回退
     */
    public function proxyModuleAction(Service $service, array $data): ?array
    {
        try {
            if (! $this->detailService->canManageService($service)) {
                return null;
            }

            [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
            $rootUrl = rtrim($this->detailService->resolveSupplierRootUrl($supplier), '/');
            $endpoint = $rootUrl.'/provision/custom/'.$hostId;

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
            Log::warning('[服务控制台] 面板动作透传供应商失败', [
                'service_id' => (int) $service->id,
                'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
            ]);

            return null;
        }
    }

    // ── 中间层透传 ─────────────────────────────────────────────────────────

    /**
     * 把本系统供应商的 host/header 载荷透传给自己的下游（TuraIDC 作为中间层）。
     *
     * 行为对齐魔方财务中间层：中间层不自己实现面板，而是把最上游的
     * module_client_area / module_client_main_area / module_button 原样下发，
     * 下游再按 key 逐层回调取内容。供应商不可用或未接入时返回 null，
     * 由调用方决定回退。
     *
     * @return array<string, mixed>|null
     */
    public function passthroughModulePayload(Service $service): ?array
    {
        try {
            if (! $this->detailService->canManageService($service)) {
                return null;
            }

            [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);

            return is_callable([$runtime, 'getHostHeaderPayload'])
                ? $runtime->getHostHeaderPayload($supplier, $hostId, $jwt)
                : null;
        } catch (\Throwable $exception) {
            Log::info('[服务控制台] 供应商面板载荷不可用', [
                'service_id' => (int) $service->id,
                'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
            ]);

            return null;
        }
    }

    /**
     * 取供应商面板 HTML，并允许把下游的动作地址透传给上游用于渲染
     * （魔方财务中间层会把下游的 api_url 继续下传）。
     */
    public function proxyModulePage(Service $service, string $moduleKey, string $apiUrl = ''): ?string
    {
        try {
            if (! $this->detailService->canManageService($service)) {
                return null;
            }

            [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);

            if (! is_callable([$runtime, 'fetchCustomModulePage'])) {
                return null;
            }

            // 上游会把我们的转售 API 凭据渲染进片段，这里原样透传给下游同样会泄露，
            // 必须先剥离；运行时注入不在这里做（下游有自己的父页面运行时）。
            return $this->stripEmbeddedCredentials(
                (string) $runtime->fetchCustomModulePage($supplier, $hostId, $moduleKey, $jwt, $apiUrl)
            );
        } catch (\Throwable $exception) {
            Log::info('[服务控制台] 供应商面板内容不可用', [
                'service_id' => (int) $service->id,
                'module_key' => $moduleKey,
                'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
            ]);

            return null;
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

            // 只有 module_client_area（归一后 select=client_area）才是产品自定义 tab。
            // module_button 是控制按钮（开机/重装/退出救援系统等），归一后 type 可能是
            // custom，但那只是「按钮走了自定义模块」，不能因此把它当成一个 tab。
            if ($selectKey !== 'client_area') {
                continue;
            }

            // 安全组模块由内置「安全组」tab 承载（ServiceSecurityGroupService 解析同一模块），
            // 重复渲染会出现两个安全组入口。NAT 已在上方先分流，这里的 acl 关键字不会误伤 nat_acl。
            if ($this->matchesSecurityGroupModule($function, $name)) {
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

    private function matchesSecurityGroupModule(string $function, string $name): bool
    {
        $text = $this->normalizeKeywordText(implode(' ', array_filter([$function, $name])));

        if ($text === '') {
            return false;
        }

        foreach (self::SECURITY_GROUP_MODULE_KEYWORDS as $keyword) {
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
     *
     * 改写完成后还有两步，顺序固定：
     *   1. 剥离上游渲染进片段的转售 API 凭据（片段会原样下发给终端用户）；
     *   2. 注入本地运行时并包成完整文档（片段是「半页」HTML，假定父页面提供全局依赖）。
     */
    private function rewriteModulePage(string $html, int $hostId, Supplier $supplier, string $ticket): string
    {
        $relativeActionUrl = 'actions?ticket='.rawurlencode($ticket);
        $rootUrl = rtrim($this->detailService->resolveSupplierRootUrl($supplier), '/');

        // 已知的完整动作地址。上游端点有两种协议：
        // 客户区 /provision/custom/{hostId} 与 API /zjmf_api/provision/custom/{hostId}
        // （魔方财务 customFunc 路由），两者都要改写为本地代理地址。
        foreach ([$rootUrl.'/provision/custom/'.$hostId, $rootUrl.'/zjmf_api/provision/custom/'.$hostId] as $endpoint) {
            $html = (string) preg_replace(
                '~https?://'.preg_quote($endpoint, '~').'~iu',
                $relativeActionUrl,
                $html
            );
        }

        // 兜底：任意协议头/域名的 /provision/custom/{hostId} 绝对地址（含 /zjmf_api 前缀）
        $hostIdQuoted = preg_quote((string) $hostId, '~');
        $html = (string) preg_replace(
            '~https?://[^\s"\'\<\>\\\\]*?/(?:zjmf_api/)?provision/custom/'.$hostIdQuoted.'~iu',
            $relativeActionUrl,
            $html
        );

        // 兜底：未渲染的模板变量
        $html = str_replace('{$MODULE_CUSTOM_API}', $relativeActionUrl, $html);

        // 片段只带了 selectFilter.js，配套的 selectFilter.css 原本由魔方客户区父页面提供；
        // iframe 里没有父页面 → 下拉框（实例「设置」页的 ISO/启动项等）样式丢失，这里按同主题目录补上。
        // 必须在资源地址改写之前解析（那时上游绝对地址还在）。
        $selectFilterStylesheet = $this->resolveSelectFilterStylesheet($html, $supplier, $ticket);

        // 面板自带的 CSS/JS 写的是上游绝对地址，直接下发等于把上游暴露给终端用户，
        // 统一改写成经本系统反代（asset?ticket=…&path=…）。
        $html = $this->proxyUpstreamAssetUrls($html, $supplier, $ticket);

        // 上游把我们的转售 API 凭据（now_jwt）渲染进了片段自身 ajax() 的请求头，
        // 下发给终端用户即等于交出上游账号；动作地址已改写为本地代理，浏览器侧不需要该头。
        $html = $this->stripEmbeddedCredentials($html);

        return $this->wrapInRuntimeDocument($html, $selectFilterStylesheet);
    }

    /**
     * 解析面板所需的 selectFilter 样式表（同样走本系统反代）。
     *
     * 上游片段里只有 `<script src="…/js/selectFilter.js">`，它配套的
     * `…/css/selectFilter.css` 由魔方客户区的父页面加载；iframe 场景没有父页面，
     * 缺了这份 CSS 就会退回原生 select 的样子。这里从脚本地址推导同主题的 CSS 地址。
     */
    private function resolveSelectFilterStylesheet(string $html, Supplier $supplier, string $ticket): string
    {
        // 面板自己已经引了就不用重复注入
        if (stripos($html, 'selectFilter.css') !== false) {
            return '';
        }

        $rootUrl = rtrim($this->detailService->resolveSupplierRootUrl($supplier), '/');
        $host = (string) parse_url($rootUrl, PHP_URL_HOST);

        if ($host === '') {
            return '';
        }

        $port = parse_url($rootUrl, PHP_URL_PORT);
        $authority = preg_quote($host, '~').(is_int($port) ? ':'.$port : '');

        if (preg_match('~(?:https?:)?//'.$authority.'(?<path>/[^"\'\s<>()\\\\]*/js/selectFilter\.js)~i', $html, $matches) !== 1) {
            return '';
        }

        $cssPath = (string) preg_replace('~/js/selectFilter\.js$~i', '/css/selectFilter.css', $matches['path']);

        if ($cssPath === '' || $cssPath === $matches['path']) {
            return '';
        }

        return '<link rel="stylesheet" href="asset?ticket='.rawurlencode($ticket).'&path='.rawurlencode($cssPath).'">';
    }

    /**
     * 把面板片段里指向上游的静态资源地址改写成本系统的反代地址。
     *
     * 只改「供应商根域名」这一台主机（含协议相对的 //host/… 写法），路径原样编码进 path 参数，
     * 上游主机不会出现在下发给终端用户的 HTML 里。面板动作地址在上一步已改成相对地址，不受影响。
     */
    private function proxyUpstreamAssetUrls(string $html, Supplier $supplier, string $ticket): string
    {
        $rootUrl = rtrim($this->detailService->resolveSupplierRootUrl($supplier), '/');
        $host = (string) parse_url($rootUrl, PHP_URL_HOST);

        if ($host === '') {
            return $html;
        }

        $port = parse_url($rootUrl, PHP_URL_PORT);
        $authority = preg_quote($host, '~').(is_int($port) ? ':'.$port : '');
        $prefix = 'asset?ticket='.rawurlencode($ticket).'&path=';

        $rewritten = preg_replace_callback(
            '~(?:https?:)?//'.$authority.'(?<path>/[^"\'\s<>()\\\\]*)~i',
            static fn (array $matches): string => $prefix.rawurlencode($matches['path']),
            $html
        );

        return (string) $rewritten;
    }

    /**
     * 删除上游渲染进 HTML 的 Authorization 请求头语句（整行，含行尾分号）。
     * 上游每个面板 2 处（ajax 的 GET / POST 分支各一），形如：
     *   xhr.setRequestHeader("Authorization", "JWT eyJ0eXAi…");
     */
    private function stripEmbeddedCredentials(string $html): string
    {
        $patterns = [
            // 正常渲染：整条 setRequestHeader("Authorization", …) 语句，连同所在行的缩进与换行
            '~^[ \t]*xhr\.setRequestHeader\(\s*["\']Authorization["\'][^\n]*?\)\s*;?[ \t]*\r?\n?~im',
            // 兜底：跨行的写法（参数换行书写）
            '~xhr\.setRequestHeader\(\s*["\']Authorization["\'][\s\S]{0,200}?\)\s*;~i',
            // 兜底：未渲染完的模板变量形态 / 裸露的 JWT 字面量（防上游换写法漏出去）
            '~["\']JWT\s+eyJ[^"\'\s]*["\']~i',
        ];

        foreach ($patterns as $pattern) {
            $html = (string) preg_replace($pattern, '', $html);
        }

        return $html;
    }

    /**
     * 把上游片段变成「带本地运行时」的完整文档。
     *
     * 两种形态都要覆盖：
     *   1. 「半页」片段（多数模块）：没有 html/head/body，假定渲染在魔方财务客户区页面里，
     *      由父页面提供 jQuery、Bootstrap 4（modal）与 SweetAlert2；
     *   2. 上游直接给完整文档（如 CDN 的「管理面板」）：它甚至把自带的 Bootstrap `<link>`
     *      注释掉了，同样指望父页面提供样式 —— 不补运行时就是**一条样式都没有**。
     *
     * 运行时脚本务必排在片段内联脚本之前，Bootstrap CSS 务必排在片段自带 CSS 之前。
     *
     * @param  string  $headAssets  额外注入 <head> 的资源标签（如补上的 selectFilter.css），空串不注入
     */
    private function wrapInRuntimeDocument(string $html, string $headAssets = ''): string
    {
        $runtimeBase = '/vendor/console-panel';

        $runtimeHead = '<link rel="stylesheet" href="'.$runtimeBase.'/bootstrap.min.css">'."\n";

        // 完整文档若自带 jQuery，就不再注入我们这份，避免二次加载把前一份的插件注册冲掉
        $skipScripts = preg_match('~<!doctype\s+html|<html[\s>]~i', $html) === 1 && stripos($html, 'jquery') !== false;

        if (! $skipScripts) {
            $runtimeHead = $runtimeHead
                .'<script src="'.$runtimeBase.'/jquery.min.js"></script>'."\n"
                .'<script src="'.$runtimeBase.'/bootstrap.bundle.min.js"></script>'."\n"
                .'<script src="'.$runtimeBase.'/sweetalert2.all.min.js"></script>'."\n"
                .'<script>'.$this->sweetalertCompatShim().'</script>'."\n";
        }

        $runtimeHead .= $headAssets !== '' ? $headAssets."\n" : '';

        // 已是完整文档：不能套壳（会破坏结构），改为把运行时插进它自己的 <head>
        if (preg_match('~<!doctype\s+html|<html[\s>]~i', $html) === 1) {
            return $this->injectRuntimeHead($html, $runtimeHead);
        }

        return '<!DOCTYPE html>'."\n"
            .'<html lang="zh-CN">'."\n"
            .'<head>'."\n"
            .'<meta charset="utf-8">'."\n"
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'."\n"
            .$runtimeHead
            .'</head>'."\n"
            .'<body>'."\n"
            .$html."\n"
            .'</body>'."\n"
            .'</html>';
    }

    /**
     * 把运行时插到上游自带文档的 <head> 开头。
     *
     * 优先插在 charset 之后：既不把 charset 挤到文档前 1KB 之外，又保证 Bootstrap CSS
     * 仍排在片段自带 CSS 之前、脚本仍先于片段内联脚本执行。
     */
    private function injectRuntimeHead(string $html, string $runtimeHead): string
    {
        foreach (['~<meta[^>]+charset[^>]*>~i', '~<head[^>]*>~i', '~<html[^>]*>~i'] as $pattern) {
            if (preg_match($pattern, $html, $matches, PREG_OFFSET_CAPTURE) === 1) {
                $insertAt = (int) $matches[0][1] + strlen((string) $matches[0][0]);

                return substr($html, 0, $insertAt)."\n".$runtimeHead.substr($html, $insertAt);
            }
        }

        return $runtimeHead.$html;
    }

    /**
     * SweetAlert2 v11 已不收旧版的 type: 参数（只 console.warn，图标静默丢失），
     * 而上游面板大量使用 type: 'question' / 'success' / 'error'，这里映射成 icon。
     * 三参写法 Swal.fire(title, msg, type) 在 v11 里第三参已是 icon，无需处理。
     */
    private function sweetalertCompatShim(): string
    {
        return <<<'JS'
        // 面板使用 SweetAlert2 v8 时代的 type: 参数，v11 只认 icon，这里做一次映射
        (function () {
          if (!window.Swal || typeof window.Swal.fire !== 'function') { return; }
          var raw = window.Swal.fire.bind(window.Swal);
          var icons = ['success', 'error', 'warning', 'info', 'question'];
          window.Swal.fire = function () {
            var args = Array.prototype.slice.call(arguments);
            if (args.length === 1 && args[0] && typeof args[0] === 'object' && args[0].type) {
              if (!args[0].icon && icons.indexOf(args[0].type) !== -1) {
                args[0].icon = args[0].type;
              }
              delete args[0].type;
            }
            return raw.apply(null, args);
          };
        })();
        JS;
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
