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
use App\Support\UpstreamReadCache;
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

    /**
     * 未点亮 NAT 能力时的能力缓存时长。
     *
     * 上游 host/header 与自定义模块页面会随机抖空，这一轮探不到不代表主机没有 NAT。
     * 若把 false 也缓存满 300 秒，客户在 5 分钟里刷多少次都看不到「端口转发」tab，
     * 探测重试全部作废。定 20 秒：压住瞬时并发重复探测，又让下一次刷新能立刻重试。
     */
    private const CAPABILITIES_CACHE_TTL_SECONDS_WITHOUT_NAT = 20;

    private const RAW_CONTENT_CACHE_TTL_SECONDS = 30;

    /**
     * 「这台主机具备 NAT 能力」的粘性记忆时长。
     *
     * 定 6 小时是为了压住上游间歇性空响应（实测抖动窗口以秒/分钟计），
     * 同时保证真把 NAT 模块下线的主机不会永久顶着一个空面板。
     */
    private const NAT_CAPABILITY_MEMORY_TTL_SECONDS = 21600;

    /**
     * 「已探过、这台主机确实没有 NAT」的负向标记时长。
     *
     * 只为避免每次进控制台都白探一次上游页面，因此必须短：实测服务 4002 的主机 858
     * 上，header 与模块页面是**反相关**的（header 有 NAT 时页面为空，header 没有时页面可用），
     * 单次探测失败很正常。标记要是活太久，一次探不到就会让 tab 消失整整那么久。
     * 定 60 秒：既挡住同一秒内的重复探测，又保证下一次刷新就能重试。
     */
    private const NAT_PROBE_MISS_TTL_SECONDS = 60;

    /**
     * 冷启动探页次数。
     *
     * 上游 header 与模块页面会同时抖没。实测最差的主机（服务 4002 / 主机 858）
     * 模块页面可用率只有 4/10，5 次全空的概率 7.8%，表现在客户侧就是「偶尔进控制台
     * 没有端口转发 tab」。提到 8 次后降到 1.7%，加上 8 × 120ms 的等待上限（约 1 秒），
     * 对能力接口的响应时间影响可以接受。
     */
    private const NAT_PROBE_ATTEMPTS = 8;

    /** 探页重试间隔（毫秒）。 */
    private const NAT_PROBE_RETRY_DELAY_MS = 120;

    /**
     * 探测 NAT 模块页面时，用来识别「假页面」的特征。
     *
     * 与 ServiceNatService::MODULE_PAGE_PLACEHOLDER_MARKERS 同一口径：上游对不存在的模块
     * 回的是魔方财务自带 404 模板（HTTP 200、1297 字节、非空），按「非空即有」判会给
     * 一台没有 NAT 的主机永久点亮一个空面板。
     */
    private const NAT_PROBE_PLACEHOLDER_MARKERS = [
        '抱歉找不到页面',
        '<title>404</title>',
        'maintain-box',
    ];

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

        // 单飞：缓存过期瞬间并发进来的请求只让一个去探上游，其余等它回填后直接读缓存。
        // TTL 由下面按 nat 能力探测结果分档决定，所以只借互斥、不借固定 TTL。
        return UpstreamReadCache::singleFlight($cacheKey, function () use ($service, $cacheKey) {
            $again = Cache::get($cacheKey);
            if (is_array($again)) {
                return $this->withPreloadPolicy($again);
            }

            return $this->computeCapabilitiesForService($service, $cacheKey);
        });
    }

    /**
     * capabilities 真正去上游探测并回填缓存的部分（调用方已保证互斥）。
     *
     * @return array<string, mixed>
     */
    private function computeCapabilitiesForService(Service $service, string $cacheKey): array
    {
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
            $capabilities = $this->applyNatCapabilityMemory($runtime, $supplier, $hostId, $jwt, $capabilities);

            // nat_supported 为 false 时不能跟着缓存 300 秒：上游抖动会让这一轮探不到，
            // 一旦被缓存，客户在接下来 5 分钟里刷新多少次都看不到端口转发 tab，
            // 重试逻辑等于白做。这种「疑似缺能力」的结果只留很短一段时间，让下一次刷新重试。
            $ttl = ($capabilities['nat_supported'] ?? false) === true
                ? self::CAPABILITIES_CACHE_TTL_SECONDS
                : self::CAPABILITIES_CACHE_TTL_SECONDS_WITHOUT_NAT;

            Cache::put($cacheKey, $capabilities, now()->addSeconds($ttl));

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
        // 单飞：缓存过期瞬间会有 N 个并发请求同时 miss，不加锁就是 N 次同步上游调用，
        // 上游单次动辄 5–25 秒，页面就卡成「刷新等二十秒」。
        $rawHtml = (string) UpstreamReadCache::remember(
            $rawCacheKey,
            self::RAW_CONTENT_CACHE_TTL_SECONDS,
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
     * 必须写成根相对；写成 HTML 里那种相对地址（asset?ticket=…&path=…）会被解析到
     * 反代端点所在目录下，反而更错。
     *
     * 路径前缀与 routes/v2-client.php 的 console-area/asset 路由一致。该路由未具名，
     * 这里手写前缀；改路由时必须同步，由 ClientServiceConsoleAreaPanelRewriteTest 锁定。
     */
    private function assetProxyUrl(int $serviceId, string $ticket, string $upstreamPath): string
    {
        return '/api/v2/client/services/'.$serviceId
            .'/console-area/asset?ticket='.rawurlencode($ticket)
            .'&path='.rawurlencode($upstreamPath);
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

            // NAT 转发一律交给内置「端口转发」tab。
            //
            // 原实现只在 nat_supported 已为 true 时才跳过 header 里的 NAT 项，逻辑上没错——
            // 那时它是「唯一入口」。但上游 host/header 的模块列表会抖动：同一台主机连打
            // 10 次，NAT 模块只在 2~8 次里出现。抖动到「列表没有 NAT」的那几次，
            // nat_supported 就是 false，于是 header 里的 NAT 被降级成一个通用区域 iframe，
            // 客户看到的正是「没被解析成系统端口转发，而是走了通用面板」。
            // 现在反过来收口：看到 NAT 就地认定具备 NAT 能力，内置 tab 会自己去探模块页面，
            // 不再依赖这份抖动列表。
            if ($isNatModule) {
                $capabilities['nat_supported'] = true;

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

    /**
     * 给「这台主机具备 NAT 能力」这件事一个粘性记忆。
     *
     * 上游 host/header 与 supported_modules 两个端点都会间歇性返回空或截断的结果：
     * 同一台主机连打 10 次，NAT 模块可能只在 2~8 次里出现（实测服务 3867 为 8/10、
     * 服务 3797 为 2/10）。只要有一次抖动到「列表里没有 NAT」，nat_supported 就是 false，
     * 前端 resolveAvailableTabs 立刻把「端口转发」tab 摘掉，客户看到的就是
     * 「端口转发面板不见了 / 走了通用面板」。
     *
     * 首次没有记忆可用时还要主动探一次模块页面：清缓存后的第一个请求正好落在抖动上，
     * 客户看到的就是「刚进控制台没有端口转发 tab」。探到页面就说明模块在，直接点亮并落记忆。
     *
     * 只做「有 → 有」的单向粘滞，不做「无 → 无」的固化，所以真把 NAT 模块下线的主机
     * 最多在 TTL 到期后自然收敛，不会永久卡住一个不存在的面板。
     *
     * @param  array<string, mixed>  $capabilities
     * @return array<string, mixed>
     */
    private function applyNatCapabilityMemory(
        object $runtime,
        Supplier $supplier,
        int $hostId,
        string $jwt,
        array $capabilities
    ): array {
        $supplierId = (int) ($supplier->id ?? 0);
        $current = ($capabilities['nat_supported'] ?? false) === true;

        // 上游 id 或主机 id 缺失时无从定位，按本次探测结果下发，不制造跨主机串味的记忆。
        if ($supplierId <= 0 || $hostId <= 0) {
            return $capabilities;
        }

        $key = $this->buildNatCapabilityMemoryKey($supplierId, $hostId);
        $probedKey = $this->buildNatProbedCacheKey($supplierId, $hostId);

        try {
            $remembered = $current ? true : Cache::get($key) === true;

            // 首次没有记忆可用时才探页；探不到也要记一笔短时负向标记，
            // 否则一台确实没有 NAT 的主机会被每次进控制台都白探一次上游页面。
            if (! $current && ! $remembered && ! Cache::get($probedKey)) {
                $current = $this->probeNatModulePage($runtime, $supplier, $hostId, $jwt);

                if (! $current) {
                    Cache::put($probedKey, true, now()->addSeconds(self::NAT_PROBE_MISS_TTL_SECONDS));
                }
            }

            if ($current) {
                Cache::put($key, true, now()->addSeconds(self::NAT_CAPABILITY_MEMORY_TTL_SECONDS));
                Cache::forget($probedKey);
            }
        } catch (\Throwable) {
            // 缓存读写失败不该让控制台能力接口跟着挂：没有记忆就是没有记忆，
            // 退回本次探测结果即可，行为等同修复前（但不会更差）。
            return $capabilities;
        }

        $capabilities['nat_supported'] = self::resolveNatSupportedFlag($current, $remembered);

        return $capabilities;
    }

    /**
     * 探一次自定义模块页面，判断这台主机到底有没有 NAT 模块。
     *
     * 实测（服务 3617 / 主机 366）header 里出现「NAT转发」只有 2/6，同一时刻模块页面
     * 可用的也只有 2/6；服务 4002 上两者甚至是反相关的。只探一次的话冷启动失败率约一半，
     * 客户刷新几次才看到 tab。这里按 NAT_PROBE_ATTEMPTS 重试，把整轮全失败压到个位数百分比。
     *
     * 仍不做长重试：能力接口是进控制台的必经路径，8 × 120ms 的等待上限约 1 秒；
     * 面板内部的取页重试另有 6 次预算（见 ServiceNatService::fetchCustomModulePage）。
     */
    private function probeNatModulePage(object $runtime, Supplier $supplier, int $hostId, string $jwt): bool
    {
        if (! is_callable([$runtime, 'fetchCustomModulePage'])) {
            return false;
        }

        for ($attempt = 1; $attempt <= self::NAT_PROBE_ATTEMPTS; $attempt++) {
            try {
                $html = (string) $runtime->fetchCustomModulePage($supplier, $hostId, 'nat_acl', $jwt);
            } catch (\Throwable) {
                $html = '';
            }

            if (self::isUsableNatModuleHtml($html)) {
                return true;
            }

            if ($attempt < self::NAT_PROBE_ATTEMPTS) {
                usleep(self::NAT_PROBE_RETRY_DELAY_MS * 1000);
            }
        }

        return false;
    }

    /**
     * 判断一段自定义模块响应能不能说明「NAT 模块存在」。
     *
     * 空串是上游抖动；魔方财务对不存在的模块回的是自带 404 模板页（HTTP 200、非空），
     * 必须和真页面区分开，否则会给一台没有 NAT 的主机永久点亮一个空面板。
     */
    private static function isUsableNatModuleHtml(string $html): bool
    {
        if (trim($html) === '') {
            return false;
        }

        foreach (self::NAT_PROBE_PLACEHOLDER_MARKERS as $marker) {
            if (str_contains($html, $marker)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 纯函数：本次探测结果与粘性记忆取并集。
     *
     * 抽成静态纯函数是为了能脱离缓存单测口径 —— 它是「NAT tab 会不会闪没」的唯一判据。
     */
    public static function resolveNatSupportedFlag(bool $current, bool $remembered): bool
    {
        return $current || $remembered;
    }

    private function buildNatCapabilityMemoryKey(int $supplierId, int $hostId): string
    {
        return 'service_console:nat_capability:'.$supplierId.':'.$hostId;
    }

    /**
     * 与正向记忆分开一把键：正向是「有」，负向是「探过了、确实没有」。
     * 混用同一个键会让负向标记在探到 NAT 后立刻变成正向记忆，语义就乱了。
     */
    private function buildNatProbedCacheKey(int $supplierId, int $hostId): string
    {
        return 'service_console:nat_probed_miss:'.$supplierId.':'.$hostId;
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
                .'<script>'.$this->sweetalertCompatShim().'</script>'."\n";
        }

        // 弹窗桥接必须无条件注入：上游面板（CDN 的「跳转到面板」）用 window.open 打第三方面板，
        // 而 iframe 内属于跨域上下文，浏览器会把它判为无用户手势的跨源弹窗直接拦掉，
        // 表现就是「点了没反应」。这里改为把地址交给父页面代开。
        $runtimeHead .= '<script>'.$this->popupBridgeScript().'</script>'."\n";

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

    /**
     * 弹窗桥接：把 iframe 内被拦截的 window.open 转交父页面执行。
     *
     * 背景：面板片段跑在 iframe 里，而 iframe 文档的源与控制台页面不同源。
     * 跨源 iframe 调 window.open 打开第三方地址时，Chrome / Edge 会按
     * 「无用户手势的跨源弹窗」处理，直接静默拦掉 —— 页面上表现为按钮点了没反应。
     * 这里在注入运行时里提前包装 window.open：
     *   - 同源父页面存在 → postMessage 把地址交给父页面开（父页面有真实用户手势，可正常弹出）；
     *   - 父页面未监听或超时未回执 → 回退到原生 window.open，保证不彻底失效。
     *
     * 只放行 http/https 绝对地址，data: / blob: / javascript: 一律拒绝。
     */
    private function popupBridgeScript(): string
    {
        return <<<'JS'
        (function () {
          if (window.__turaPopupBridge) { return; }
          window.__turaPopupBridge = true;

          var EVENT = 'tura:open-url';
          var nativeOpen = window.open.bind(window);
          var seq = 0;

          function isHttpUrl(value) {
            try {
              var protocol = new URL(String(value), window.location.href).protocol;
              return protocol === 'http:' || protocol === 'https:';
            } catch (e) {
              return false;
            }
          }

          window.open = function (url, target, features) {
            var href = String(url == null ? '' : url);
            // 不接管空地址（约等于新开空白页）与非 http(s) 协议
            if (!href || !isHttpUrl(href)) {
              return nativeOpen(url, target, features);
            }
            // 已经在顶层窗口里（如 _top / _parent）说明脚本本身就想替换当前页
            if (target === '_top' || target === '_parent' || target === 'top' || target === 'parent') {
              return nativeOpen(url, target, features);
            }

            try {
              if (window.parent && window.parent !== window) {
                seq += 1;
                var id = 'tura-open-' + seq;
                var acked = false;

                // 父页面若在 1.2s 内没回执（未监听该消息），退回原生打开
                var timer = window.setTimeout(function () {
                  if (!acked) { nativeOpen(href, '_blank', 'noopener,noreferrer'); }
                }, 1200);

                window.addEventListener('message', function handler(event) {
                  if (acked || !event.data || event.data.type !== EVENT + ':ack' || event.data.id !== id) {
                    return;
                  }
                  acked = true;
                  window.clearTimeout(timer);
                  window.removeEventListener('message', handler);
                });

                window.parent.postMessage({ type: EVENT, id: id, url: href }, '*');
                return null;
              }
            } catch (e) {
              // postMessage 失败（如被 CSP 限制）时走下面的原生兜底
            }

            return nativeOpen(url, target, features);
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
