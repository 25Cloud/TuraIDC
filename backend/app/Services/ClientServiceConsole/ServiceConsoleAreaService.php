<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole;

use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Upstream\Contracts\ProvidesLocalConsoleActions;
use App\Support\SensitiveDataSanitizer;
use DOMDocument;
use DOMElement;
use DOMNode;
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
 *   - areaTicketForUser：签发短时效 iframe 访问票据（由 HttpOnly Cookie 或 Bearer 头承载）；
 *   - areaContentForTicket：抓取并改写上游 HTML（动作地址重写为本地代理）；
 *   - submitAreaActionForTicket：将页面内动作回发上游并原样返回结果。
 */
class ServiceConsoleAreaService
{
    private const AREA_TICKET_CACHE_PREFIX = 'service_console:area_ticket:';

    private const AREA_TICKET_TTL_SECONDS = 180;

    private const CAPABILITIES_CACHE_TTL_SECONDS = 300;

    private const RAW_CONTENT_CACHE_TTL_SECONDS = 30;

    private const NAT_MODULE_KEYWORDS = [
        'nat_acl', 'natacl', 'nat转发', 'nat 转发', '端口转发', '端口映射', '端口转发规则',
    ];

    /** 与 ServiceSecurityGroupService 的内置 tab 关键字保持一致，避免两者对同一模块判定不同 */
    private const SECURITY_GROUP_MODULE_KEYWORDS = [
        '安全组', '安全', 'securitygroup', 'security_group', 'security-group', 'secgroup', 'firewall', 'acl',
    ];

    /**
     * 面板运行时（jQuery / Bootstrap / SweetAlert2）静态资源的基路径，
     * 其下的 4 个文件必须真实存在于 backend/public/media/console-panel/。
     *
     * 为什么不能用 /vendor/console-panel：站点是单域名多路径前缀架构，根路径交给官网
     * （frontend-user-v3-www/dist），/vendor/... 会被官网的 SPA 兜底吞掉、返回一段 HTML。
     * 浏览器拿到的不是 jQuery，`$` 未定义 → 上游面板里 `$('#xxx').click(...)` 全部静默失效，
     * 表现就是「面板能显示但按钮点了没反应」。
     *
     * /media/ 则落在 API 站点 nginx 的 root（backend/public）下，由该站点直接提供静态文件，
     * 归属明确、可控，不经过官网的 SPA 兜底。
     *
     * 公开为常量：测试据此校验磁盘上确实有这些资产，避免基路径与资产实际位置再次脱节。
     */
    public const RUNTIME_ASSET_BASE = '/media/console-panel';

    /** 上游片段里写死的旧前缀，统一改写成 RUNTIME_ASSET_BASE。 */
    private const LEGACY_RUNTIME_ASSET_BASE = '/vendor/console-panel';

    public function __construct(
        private readonly ServiceDetailService $detailService,
        private readonly ServiceConsoleAssetService $assetService,
    ) {}

    // ── 能力下发 ───────────────────────────────────────────────────────────

    /**
     * 返回当前服务支持的控制台能力：
     *   supported / error / areas / nat_supported / monitor_supported / fetchable /
     *   snapshot_supported / iso_supported。
     * 非可控上游或上游拉取失败时不抛错，由调用方按默认 tab 渲染。
     *
     * `snapshot_supported` / `iso_supported` 与自定义 HTML 模块无关，只反映驱动有没有
     * `getSnapshots` / `getIsoImages` 这两个具名能力；前端据此决定是否渲染对应面板。
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
            return $this->withPreloadPolicy($this->capabilityPayload(false, ''));
        }

        $cacheKey = $this->buildCapabilitiesCacheKey($service);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $this->withPreloadPolicy($cached);
        }

        // 驱动级能力标志：无论后面模块解析成不成，都要按真实探测结果回填
        $snapshotSupported = false;
        $isoSupported = false;

        try {
            [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);

            // 快照 / ISO 是驱动级具名能力，和「自定义 HTML 模块」完全无关：
            // 必须在解析模块之前就定下来。上游不提供自定义模块（supported_modules 直接报错）
            // 时，这两个入口照样要能出现，所以标志位不能只在成功分支里算。
            $snapshotSupported = is_callable([$runtime, 'getSnapshots']);
            $isoSupported = is_callable([$runtime, 'getIsoImages']);

            $modules = $this->detailService->fetchSupportedModules($supplier, $hostId, $jwt);

            $capabilities = $this->deriveModuleCapabilities($modules, $hostId);

            // 部分上游（如天理云 CDN）只在 host/header 的 module_client_area 里声明自定义区域，
            // supported_modules 端点并不包含它。不补这一刀，商家给的页面就永远不会被渲染出来。
            //
            // 这里是补充数据源，失败不该让已成功取到的 supported_modules 能力作废：
            // 不隔离的话异常会落进外层 catch，把 capabilities 整体标记为不支持并缓存，
            // 一个补充源的抖动会让整个面板消失几分钟。
            try {
                $capabilities = $this->mergeHeaderClientAreas($capabilities, $runtime, $supplier, $hostId, $jwt);
            } catch (\Throwable $exception) {
                Log::warning('[服务控制台] 补充 host 自定义区域失败，沿用已取到的能力', [
                    'service_id' => (int) $service->id,
                    'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
                ]);
            }

            $capabilities['supported'] = true;
            $capabilities['fetchable'] = is_callable([$runtime, 'fetchCustomModulePage']);
            $capabilities['snapshot_supported'] = $snapshotSupported;
            $capabilities['iso_supported'] = $isoSupported;

            Cache::put($cacheKey, $capabilities, now()->addSeconds(self::CAPABILITIES_CACHE_TTL_SECONDS));

            return $this->withPreloadPolicy($capabilities);
        } catch (\Throwable $exception) {
            Log::warning('[服务控制台] 读取自定义区域能力失败', [
                'service_id' => (int) $service->id,
                'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
            ]);

            $payload = $this->capabilityPayload(false, '读取上游功能面板失败，请稍后重试');
            // 模块读不到不代表实例没有快照/ISO 能力，标志位按已探明的结果回填
            $payload['snapshot_supported'] = $snapshotSupported;
            $payload['iso_supported'] = $isoSupported;
            Cache::put($cacheKey, $payload, now()->addSeconds(self::CAPABILITIES_CACHE_TTL_SECONDS));

            return $this->withPreloadPolicy($payload);
        }
    }

    /**
     * 附上「面板区域是否全量预热」这一策略位。
     *
     * 这个字段刻意不写进 capabilities 缓存：那份缓存 300 秒，而 settings 分组缓存会在
     * 管理员保存时主动失效。挂在返回路径上，管理员改完开关立即生效，不必等能力缓存过期。
     *
     * @param  array<string, mixed>  $capabilities
     * @return array<string, mixed>
     */
    private function withPreloadPolicy(array $capabilities): array
    {
        $capabilities['area_preload_all'] = $this->areaPreloadAllEnabled();

        return $capabilities;
    }

    /**
     * 后台「集成插件 → 上游面板预热」开关（system.console_area_preload_all_enabled）。
     *
     * 缺省为开启：默认拿「切换零等待」，要退回「只预热第一个区域」的管理员再显式关掉。
     * 读不到设置时同样按开启处理，避免 settings 表异常导致全站面板突然变慢。
     */
    private function areaPreloadAllEnabled(): bool
    {
        try {
            $value = Setting::getValue('system', 'console_area_preload_all_enabled', '1');
        } catch (\Throwable) {
            // capabilitiesForUser 的契约是「上游挂了也降级返回、不抛错」，设置读取同理：
            // settings 表故障不该把整个控制台能力接口变成 500。降级为默认开启。
            return true;
        }

        return $this->interpretPreloadSetting($value);
    }

    /**
     * 设置表里的取值一律是字符串，统一在这里解释成布尔。
     * 抽成纯函数是为了能脱离数据库单测取值口径。
     */
    private function interpretPreloadSetting(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        // 空值等同于「未配置」：按开启处理。设置项被清空或 settings 表缺失时不该让
        // 全站面板静默退回单区域预热。口径与管理端 integration-plugins 的开关一致。
        if ($normalized === '') {
            return true;
        }

        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
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
            if ($runtime instanceof ProvidesLocalConsoleActions) {
                return $runtime->submitServiceConsoleAction($service, $supplier, $hostId, $data);
            }

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
            $path,
            fn (string $upstreamPath): string => $this->assetProxyUrl($serviceId, $ticket, $upstreamPath)
        );
    }

    /**
     * 把上游站点内的绝对路径包装成本站的资源反代地址。
     *
     * 供 CSS 内部的相对引用改写使用：CSS 里的 url() 是相对 **CSS 自身 URL** 解析的，
     * 必须写成根相对；写成 HTML 里那种相对地址（asset?path=…）会被解析到
     * 反代端点所在目录下，反而更错。
     *
     * 路径前缀与 routes/v2-client.php 的 console-area/asset 路由一致。该路由未具名，
     * 这里手写前缀；改路由时必须同步，由 ClientServiceConsoleAreaPanelRewriteTest 锁定。
     */
    private function assetProxyUrl(int $serviceId, string $ticket, string $upstreamPath): string
    {
        return '/api/v2/client/services/'.$serviceId
            .'/console-area/asset?path='.rawurlencode($upstreamPath);
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

            if ($runtime instanceof ProvidesLocalConsoleActions) {
                return $runtime->submitServiceConsoleAction($service, $supplier, $hostId, $data);
            }

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
            'snapshot_supported' => false,
            'iso_supported' => false,
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

    /**
     * 把 host/header 的 module_client_area 补进能力列表。
     *
     * 为什么要补：部分上游（天理云 CDN 实测）只在 host/header 的 module_client_area
     * 里声明自定义区域，fetchSupportedModules() 走的另一个端点并不包含它。
     * 不补这一刀，商家提供的页面就永远不会被渲染出来。
     *
     * 但补进来的是同一份 module_client_area，与模块列表高度重叠，绝不能把内置 tab
     * 已经承载的功能再下发一次 —— 前端会把 areas 里的每一项都渲染成 tab（useConsoleCore
     * 的 resolveConsoleTabs），于是同一个功能出现两个入口。实测 NAT 产品就是这样多出
     * 一个与「端口转发」功能完全相同的「NAT转发」。
     *
     * @param  array<string, mixed>  $capabilities
     * @return array<string, mixed>
     */
    private function mergeHeaderClientAreas(
        array $capabilities,
        object $runtime,
        Supplier $supplier,
        int $hostId,
        string $jwt
    ): array {
        if (! is_callable([$runtime, 'getHostHeaderPayload'])) {
            return $capabilities;
        }

        $header = $runtime->getHostHeaderPayload($supplier, $hostId, $jwt);
        $areas = is_array($header['module_client_area'] ?? null) ? $header['module_client_area'] : [];

        if ($areas === []) {
            return $capabilities;
        }

        $existing = [];
        foreach ((array) ($capabilities['areas'] ?? []) as $area) {
            if (is_array($area) && trim((string) ($area['key'] ?? '')) !== '') {
                $existing[trim((string) $area['key'])] = true;
            }
        }

        foreach ($areas as $area) {
            $key = is_array($area) ? trim((string) ($area['key'] ?? '')) : trim((string) $area);
            $name = is_array($area) ? trim((string) ($area['name'] ?? '')) : '';

            if ($key === '' || $key === 'overview' || isset($existing[$key])) {
                continue;
            }

            // header 数据没有 type 字段，按空传即可让关键字生效（matchesNatModule 允许空 type）
            $isNatModule = $this->matchesNatModule($key, $name, '');

            // NAT 转发：内置「端口转发」tab 已经承载（nat_supported）时，header 这一项
            // 就是第二个入口。
            //
            // 这里必须以 nat_supported 为前提，不能只看关键字：nat_supported 为 false 说明
            // supported_modules 里根本没有 NAT 模块，header 这一项就是唯一入口，跳掉会让
            // NAT 功能整个消失。
            if ($isNatModule && ($capabilities['nat_supported'] ?? false) === true) {
                continue;
            }

            // 安全组：由内置「安全组」tab 承载（BUILTIN_TABS_ORDER 固定下发），口径与
            // deriveModuleCapabilities 一致 —— 那里是无条件跳过的，这里跟着无条件跳过。
            //
            // 必须排除 NAT 模块：安全组关键字表里有 'acl'，会连 nat_acl 一起吃掉。
            // deriveModuleCapabilities 里 NAT 分流排在安全组分流之前正是为了避开这一点，
            // 这里保持同样的顺序语义，否则 nat_supported 为 false 时 NAT 区域会被误杀。
            if (! $isNatModule && $this->matchesSecurityGroupModule($key, $name)) {
                continue;
            }

            $existing[$key] = true;
            $capabilities['areas'][] = [
                'key' => $key,
                'name' => $name !== '' ? $name : $key,
            ];
        }

        return $capabilities;
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
     * 「actions」（相对内容页所在目录），浏览器在 iframe 内可同源回发；
     * 票据由路径限定的 HttpOnly cookie 或 Authorization Bearer 头提供。
     *
     * 改写完成后还有两步，顺序固定：
     *   1. 剥离上游渲染进片段的转售 API 凭据（片段会原样下发给终端用户）；
     *   2. 注入本地运行时并包成完整文档（片段是「半页」HTML，假定父页面提供全局依赖）。
     */
    private function rewriteModulePage(string $html, int $hostId, Supplier $supplier, string $ticket): string
    {
        $relativeActionUrl = 'actions';
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
        // 统一改写成经本系统反代（asset?path=…），访问凭证不进入 URL。
        $html = $this->proxyUpstreamAssetUrls($html, $supplier, $ticket);

        // 上游把我们的转售 API 凭据（now_jwt）渲染进了片段自身 ajax() 的请求头，
        // 下发给终端用户即等于交出上游账号；动作地址已改写为本地代理，浏览器侧不需要该头。
        $html = $this->stripEmbeddedCredentials($html);

        // 上游 HTML 只允许作为被动界面内容下发：脚本由本地运行时提供，事件属性、危险
        // URL 和可执行容器全部通过 DOM 白名单清除，避免同源 iframe 把上游内容升级成本站脚本。
        $html = $this->sanitizeModulePage($html);

        return $this->wrapInRuntimeDocument($html, $selectFilterStylesheet);
    }

    /**
     * 用 DOM 白名单清理上游面板 HTML。
     *
     * 不能用正则剥离 script：畸形标签可以在清理后重新组合成可执行节点。这里先由
     * libxml 解析，再删除 script/style/iframe 等可执行容器、on* 事件属性和危险 URL；
     * 解析失败时转义整段输入，绝不回退原文。
     */
    private function sanitizeModulePage(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        $dom->preserveWhiteSpace = true;

        $isFullDocument = preg_match('~<!doctype\s+html|<html[\s>]~i', $html) === 1;
        $source = $isFullDocument
            ? $html
            : '<!DOCTYPE html><html><head></head><body>'.$html.'</body></html>';

        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$source, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false || ! $dom->documentElement instanceof DOMElement) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $root = $dom->documentElement;
        foreach (iterator_to_array($root->attributes ?? []) as $attribute) {
            $this->sanitizeModuleAttribute(
                $root,
                strtolower($root->tagName),
                (string) $attribute->nodeName,
                (string) $attribute->nodeValue
            );
        }
        $this->sanitizeModuleNode($root);

        $output = (string) $dom->saveHTML($dom->documentElement);
        if ($output === '') {
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return "<!DOCTYPE html>\n".$output;
    }

    /** @var list<string> */
    private const MODULE_DROP_TAGS = [
        'base', 'embed', 'iframe', 'meta', 'object', 'script', 'svg',
    ];

    /** @var list<string> */
    private const MODULE_ALLOWED_TAGS = [
        'a', 'abbr', 'article', 'aside', 'b', 'blockquote', 'body', 'br', 'button',
        'caption', 'code', 'col', 'colgroup', 'dd', 'del', 'details', 'div', 'dl',
        'dt', 'em', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2',
        'h3', 'h4', 'h5', 'h6', 'head', 'header', 'hr', 'html', 'img', 'input', 'label',
        'legend', 'li', 'link', 'main', 'ol', 'option', 'output', 'p', 'picture', 'pre', 'select',
        'small', 'source', 'span', 'strong', 'style', 'sub', 'summary', 'sup', 'table', 'tbody',
        'td', 'textarea', 'tfoot', 'th', 'thead', 'title', 'tr', 'ul',
    ];

    /** @var list<string> */
    private const MODULE_ALLOWED_ATTRS = [
        'accept', 'alt', 'aria-label', 'aria-labelledby', 'autocomplete', 'checked', 'class',
        'cols', 'colspan', 'contenteditable', 'data-*', 'decoding', 'disabled', 'for',
        'action', 'cite', 'formaction', 'height', 'href', 'id', 'lang', 'max', 'maxlength',
        'method', 'min', 'multiple', 'name', 'placeholder', 'poster', 'readonly', 'rel',
        'required', 'role', 'rows', 'selected', 'size', 'src', 'tabindex', 'target', 'title',
        'type', 'value', 'width',
    ];

    /** @var list<string> */
    private const MODULE_URI_ATTRS = [
        'action', 'cite', 'formaction', 'href', 'poster', 'src', 'xlink:href',
    ];

    private function sanitizeModuleNode(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                if ($child->nodeType !== XML_TEXT_NODE) {
                    $child->parentNode?->removeChild($child);
                }

                continue;
            }

            $tagName = strtolower($child->tagName);
            if (in_array($tagName, self::MODULE_DROP_TAGS, true)) {
                $child->parentNode?->removeChild($child);

                continue;
            }

            if ($tagName === 'style') {
                $this->sanitizeModuleStyle($child);

                continue;
            }

            if (! in_array($tagName, self::MODULE_ALLOWED_TAGS, true)) {
                // 先清理未知容器里的后代，再把容器本身解包；否则
                // `<unknown><script>...</script></unknown>` 会在解包后留下脚本节点。
                $this->sanitizeModuleNode($child);
                $this->unwrapModuleElement($child);

                continue;
            }

            foreach (iterator_to_array($child->attributes ?? []) as $attribute) {
                $this->sanitizeModuleAttribute(
                    $child,
                    $tagName,
                    (string) $attribute->nodeName,
                    (string) $attribute->nodeValue
                );
            }

            $this->sanitizeModuleNode($child);
        }
    }

    private function sanitizeModuleAttribute(DOMElement $element, string $tagName, string $rawName, string $value): void
    {
        $name = strtolower($rawName);

        // 事件和 style 属性不能进入同源 iframe；后者也会被 style-src-attr 明确禁止。
        if (str_starts_with($name, 'on') || $name === 'style' || $name === 'srcset' || $name === 'http-equiv') {
            $element->removeAttribute($rawName);

            return;
        }

        $allowed = in_array($name, self::MODULE_ALLOWED_ATTRS, true)
            || (str_starts_with($name, 'data-') && strlen($name) > 5)
            || (str_starts_with($name, 'aria-') && strlen($name) > 5);
        if (! $allowed) {
            $element->removeAttribute($rawName);

            return;
        }

        if (in_array($name, self::MODULE_URI_ATTRS, true) && ! $this->isSafeModuleUrl($value, $tagName, $name)) {
            $element->removeAttribute($rawName);

            return;
        }

        if ($name === 'rel') {
            $tokens = preg_split('/\s+/', strtolower(trim($value))) ?: [];
            $tokens = array_values(array_filter($tokens, static fn (string $token): bool => in_array($token, [
                'alternate', 'external', 'help', 'license', 'next', 'nofollow', 'noopener',
                'noreferrer', 'prev', 'search', 'stylesheet', 'tag', 'ugc',
            ], true)));
            if ($tokens === []) {
                $element->removeAttribute($rawName);
            } else {
                $element->setAttribute($rawName, implode(' ', $tokens));
            }
        }
    }

    /**
     * 保留必要的面板 CSS，但删除会引入外部资源或执行浏览器脚本的声明。
     * 内容响应会把清理后的 style 块加入 CSP hash，仍不放开任意 inline style。
     */
    private function sanitizeModuleStyle(DOMElement $element): void
    {
        $css = (string) $element->textContent;
        $css = (string) preg_replace('~@import\s+[^;]+;?~i', '', $css);
        $css = (string) preg_replace('~url\(\s*["\']?\s*(?:https?:|//|data:|file:|javascript:)[^)]*\)~i', 'none', $css);
        $css = (string) preg_replace('~(?:expression\s*\([^)]*\)|-moz-binding\s*:[^;}]*)~i', '', $css);
        $css = (string) preg_replace('~\bbehavior\s*:[^;}]*(?:;|})~i', '', $css);

        if (trim($css) === '') {
            $element->parentNode?->removeChild($element);

            return;
        }

        while ($element->firstChild !== null) {
            $element->removeChild($element->firstChild);
        }
        $element->appendChild($element->ownerDocument->createTextNode($css));
    }

    private function isSafeModuleUrl(string $value, string $tagName, string $attribute): bool
    {
        $url = preg_replace('/[\x00-\x20\x7F\s]+/u', '', trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '';
        if ($url === '' || str_starts_with($url, '//') || preg_match('/^(?:javascript|vbscript|data|file):/i', $url) === 1) {
            return false;
        }

        $isResource = in_array($attribute, ['src', 'poster', 'xlink:href'], true)
            || ($tagName === 'link' && $attribute === 'href');
        if ($isResource) {
            return ! preg_match('/^[a-z][a-z0-9+.-]*:/i', $url);
        }

        if ($attribute === 'action' || $attribute === 'formaction') {
            return ! preg_match('/^[a-z][a-z0-9+.-]*:/i', $url);
        }

        return true;
    }

    private function unwrapModuleElement(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            $parent->insertBefore($child, $element);
        }
        $parent->removeChild($element);
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

        return '<link rel="stylesheet" href="asset?path='.rawurlencode($cssPath).'">';
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
        $prefix = 'asset?path=';

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
     * 上游脚本已在前置净化阶段删除，运行时脚本只从本站静态资源加载；Bootstrap CSS
     * 务必排在片段自带 CSS 之前。
     *
     * @param  string  $headAssets  额外注入 <head> 的资源标签（如补上的 selectFilter.css），空串不注入
     */
    private function wrapInRuntimeDocument(string $html, string $headAssets = ''): string
    {
        // 运行时资源前缀的取值理由见 RUNTIME_ASSET_BASE。
        $runtimeBase = self::RUNTIME_ASSET_BASE;
        $legacyRuntimeBase = self::LEGACY_RUNTIME_ASSET_BASE;

        $runtimeHead = '<link rel="stylesheet" href="'.$runtimeBase.'/bootstrap.min.css">'."\n";

        // 上游片段里写死的 /vendor/... 一律改写成可用路径，否则它引入的 jQuery
        // 会因 404/串站 HTML 而让后续所有 $(...) 绑定直接抛错。
        //
        // 改写必须排在下面的 jQuery 检测之前：检测口径是「文档里有没有
        // runtimeBase 这条可用路径下的 jQuery」，而坏路径只有改写后才变成可用路径。
        // 顺序颠倒的话，一份只引用 /vendor/... 的完整文档会被判为「没带 jQuery」，
        // 于是我们再注入一份 —— 同一页面加载两次 jQuery，后一份会把前一份注册的
        // 插件冲掉，症状同样是按钮静默失效。
        if ($legacyRuntimeBase !== $runtimeBase) {
            $html = str_replace($legacyRuntimeBase, $runtimeBase, $html);
        }

        // 完整文档若自带 jQuery，就不再注入我们这份，避免二次加载把前一份的插件注册冲掉。
        // 检测排在 legacy 路径改写之后：改写前写死 /vendor/... 的引用是坏路径，改写后已是
        // 可用的 /media/...；外链 CDN jQuery 同样算「自带」——只认自家路径会把外部 jQuery
        // 的文档误判为「没带」，造成同页双份 jQuery。
        $isFullDocument = preg_match('~<!doctype\s+html|<html[\s>]~i', $html) === 1;
        $hasUsableJquery = $isFullDocument
            && preg_match('~<script[^>]+src\s*=\s*["\'][^"\']*jquery[^"\']*["\']~i', $html) === 1;
        $skipScripts = $hasUsableJquery;

        if (! $skipScripts) {
            $runtimeHead = $runtimeHead
                .'<script src="'.$runtimeBase.'/jquery.min.js"></script>'."\n"
                .'<script src="'.$runtimeBase.'/bootstrap.bundle.min.js"></script>'."\n"
                .'<script src="'.$runtimeBase.'/sweetalert2.all.min.js"></script>'."\n"
                .'<script src="'.$runtimeBase.'/panel-runtime.js"></script>'."\n";
        }

        // 兼容垫片与弹窗桥接均放在同源静态脚本中，面板 CSP 无需 unsafe-inline。
        if ($skipScripts) {
            $runtimeHead .= '<script src="'.$runtimeBase.'/panel-runtime.js"></script>'."\n";
        }

        $runtimeHead .= $headAssets !== '' ? $headAssets."\n" : '';

        // 已是完整文档：不能套壳（会破坏结构），改为把运行时插进它自己的 <head>
        if ($isFullDocument) {
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
     * 仍排在片段自带 CSS 之前。
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

    private function buildCapabilitiesCacheKey(Service $service): string
    {
        return 'service_console:capabilities:'.$service->id.':'.$service->user_id;
    }

    private function buildRawContentCacheKey(Service $service, string $moduleKey): string
    {
        return 'service_console:area_content:'.$service->id.':'.$moduleKey;
    }
}
