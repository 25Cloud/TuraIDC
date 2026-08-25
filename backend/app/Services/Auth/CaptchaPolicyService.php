<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Services\Integrations\Plugins\IntegrationDriverBindingResolver;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginDomain;

/**
 * 人机验证的「场景开关」。
 *
 * 背景：在此之前，「哪个接口要不要人机验证」是散落在各控制器里的硬编码——客户端登录是
 * 风控触发式、发送验证码是必验、而注册与管理端登录则完全没有验证。本类把这件事收敛成
 * 一组可在插件配置界面里开关的场景。
 *
 * 五个场景与「不重复验证」原则：
 * 只在**入口**验一次，不在终态接口重复验。重置密码、验证码登录这类操作必须先获取
 * 邮箱/短信验证码，而发码入口已经过了人机验证，因此它们不再单独设开关——
 * 邮箱/短信验证码本身就是持有性证明，终态接口再验一次只是重复摩擦。
 *
 * 其中三个场景不可关闭（见 MANDATORY_SCENES）：注册与两个发码入口。发码直接花钱、
 * 注册直接写入账号唯一信息，把它们做成可关的开关等于给「关掉之后被刷」留了口子。
 * 登录类场景仍然可关，逃生通道也只针对它们。
 *
 * 开关存放在**当前生效的验证码插件**的配置里（各插件 config.php 声明 switch 字段），
 * 所以在插件管理界面即可调整。这里集中做读取与默认值兜底，避免四个插件各写一份同样的逻辑。
 *
 * 关于「管理员会不会把自己锁在外面」：不会。requiresCaptcha() 首先检查插件是否已启用
 * 且配置完整，未配置任何插件时一律返回 false。不会出现「要求验证却没有组件可渲染」的
 * 死锁——前端在 enabled=false 时收到 captcha_required 会直接抛错，那是必须避免的状态。
 */
final class CaptchaPolicyService
{
    /**
     * 行为验证未通过的业务错误码。
     *
     * 曾与 ReferralService 的退款拦截码 42210 冲突：两处语义完全不同，却落在同一个码上。
     * 当时没炸，只因为前端判断的是响应里的 captcha_required 布尔字段而不是码值；
     * 一旦有人改成按码判断，退款被拦截时就会弹出验证码框。
     *
     * 让码的是验证码这一侧：42210-42212 是 ReferralService 连续且具名的退款拦截码段，
     * 其中 42211/42212 的错误码还写进了给管理员看的文案（「错误码 42211。」），不宜变动。
     */
    public const VERIFY_FAILED_ERROR_CODE = 42220;

    public const SCENE_CLIENT_LOGIN = 'client_login';

    public const SCENE_CLIENT_REGISTER = 'client_register';

    public const SCENE_ADMIN_LOGIN = 'admin_login';

    public const SCENE_EMAIL_CODE = 'email_code';

    public const SCENE_PHONE_CODE = 'phone_code';

    /**
     * 场景 → 插件配置里的开关键名。
     *
     * 各 captcha 插件的 config.php 都需声明这几个 switch 字段（纯声明，不含逻辑）。
     *
     * @var array<string, string>
     */
    private const SCENE_CONFIG_KEYS = [
        self::SCENE_CLIENT_LOGIN => 'scene_client_login',
        self::SCENE_CLIENT_REGISTER => 'scene_client_register',
        self::SCENE_ADMIN_LOGIN => 'scene_admin_login',
        self::SCENE_EMAIL_CODE => 'scene_email_code',
        self::SCENE_PHONE_CODE => 'scene_phone_code',
    ];

    /**
     * 场景中文名，供界面与文档使用。
     *
     * @var array<string, string>
     */
    private const SCENE_LABELS = [
        self::SCENE_CLIENT_LOGIN => '用户登录',
        self::SCENE_CLIENT_REGISTER => '用户注册',
        self::SCENE_ADMIN_LOGIN => '管理员登录',
        self::SCENE_EMAIL_CODE => '发送邮箱验证码',
        self::SCENE_PHONE_CODE => '发送手机验证码',
    ];

    /**
     * 默认全部开启。
     *
     * 只有在管理员显式关掉某个开关时才放行——安全默认值优先。
     * 注意这个默认值只在「插件已启用且配置完整」时才有意义（见 requiresCaptcha）。
     */
    private const DEFAULT_ENABLED = true;

    /**
     * 不可关闭的场景：开关值一律按开启处理。
     *
     * 判定依据是「这个入口被刷会不会直接产生对外成本或污染账号唯一信息」：
     *
     * - email_code / phone_code：直接触发对外发信。短信按条计费，邮箱有发送配额与
     *   投诉率约束（被刷会导致域名信誉下降甚至被服务商停用）。这两个端点是**公开**的
     *   （routes/v2-client.php 的 /auth/phone-code、/auth/email-code 不在 auth 中间件内），
     *   仅有 throttle:3,1 的按 IP 限流兜底——攻击者换 IP 即可绕过，人机验证是唯一有效防线。
     *   一旦关掉这两个开关，就等于把「花钱的接口」完全敞开。
     * - client_register：注册即写入手机号/邮箱这类账号唯一信息，是刷号的主入口；
     *   且攻击者每次使用新账号，按失败次数计数的风控在此结构性失效
     *   （注册没有"失败"可计），没有人机验证就没有任何门槛。
     *
     * 为什么这样做不会把管理员锁在外面：登录类场景（client_login / admin_login）**仍可关闭**，
     * 它们才是「验证服务商故障 → 进不了后台」死锁的来源，逃生通道（captcha:scene、
     * --disable-plugin）针对的也是它们。注册与发码被强制验证不影响管理员登录；
     * 真要整体停掉，仍可用 --disable-plugin 停用插件（那是一次显式的全站决定）。
     *
     * @var list<string>
     */
    public const MANDATORY_SCENES = [
        self::SCENE_CLIENT_REGISTER,
        self::SCENE_EMAIL_CODE,
        self::SCENE_PHONE_CODE,
    ];

    /** @var array<string, mixed>|null 当次请求内的插件配置缓存 */
    private ?array $driverConfigCache = null;

    public function __construct(
        private readonly GeeTestService $captchaService,
        private readonly IntegrationDriverBindingResolver $bindingResolver,
        private readonly PluginConfigRepository $configRepository,
    ) {}

    /**
     * 本次请求是否需要人机验证。
     */
    public function requiresCaptcha(string $scene): bool
    {
        // 未启用插件时一律不要求：否则前端会拿到 captcha_required 却无组件可渲染。
        if (! $this->captchaService->isEnabled()) {
            return false;
        }

        return $this->isSceneEnabled($scene);
    }

    /**
     * 某场景的开关是否打开（不考虑插件是否启用）。
     */
    public function isSceneEnabled(string $scene): bool
    {
        $configKey = self::SCENE_CONFIG_KEYS[trim($scene)] ?? null;
        if ($configKey === null) {
            return false;
        }

        // 强制场景不读配置：脏数据、误操作或直接改库都不能把它关掉
        if (self::isMandatory($scene)) {
            return true;
        }

        $config = $this->driverConfig();
        if (! array_key_exists($configKey, $config)) {
            return self::DEFAULT_ENABLED;
        }

        // 无法解析的值按默认（开启）处理，与「键不存在」同口径
        return $this->truthy($config[$configKey]) ?? self::DEFAULT_ENABLED;
    }

    /**
     * 全部场景及其当前开关状态。
     *
     * mandatory 为 true 表示该场景不可关闭（见 MANDATORY_SCENES），
     * 供命令行与管理界面把开关显示为锁定态，而不是让人误以为改得动。
     *
     * @return array<int, array{scene: string, label: string, enabled: bool, mandatory: bool}>
     */
    public function describeScenes(): array
    {
        $scenes = [];

        foreach (self::SCENE_CONFIG_KEYS as $scene => $ignoredConfigKey) {
            $scenes[] = [
                'scene' => $scene,
                'label' => self::SCENE_LABELS[$scene] ?? $scene,
                'enabled' => $this->isSceneEnabled($scene),
                'mandatory' => self::isMandatory($scene),
            ];
        }

        return $scenes;
    }

    /**
     * 该场景是否属于不可关闭的强制场景。
     */
    public static function isMandatory(string $scene): bool
    {
        return in_array(trim($scene), self::MANDATORY_SCENES, true);
    }

    /**
     * 场景 → 是否开启 的扁平映射，供接口下发前端。
     *
     * @return array<string, bool>
     */
    public function sceneMap(): array
    {
        $map = [];

        foreach (array_keys(self::SCENE_CONFIG_KEYS) as $scene) {
            $map[$scene] = $this->isSceneEnabled($scene);
        }

        return $map;
    }

    /**
     * 某场景对应的插件配置键名。供插件 config.php 与测试引用，避免键名硬编码在多处。
     */
    public static function configKey(string $scene): string
    {
        return self::SCENE_CONFIG_KEYS[trim($scene)] ?? '';
    }

    /**
     * 全部场景标识。
     *
     * @return array<int, string>
     */
    public static function scenes(): array
    {
        return array_keys(self::SCENE_CONFIG_KEYS);
    }

    /**
     * 当前生效驱动的插件配置。
     *
     * captcha 域内插件的 slug 与 provider_key 一致（见各 config.php 的 info.slug / info.key），
     * 因此驱动键可直接当作 slug 使用。
     *
     * @return array<string, mixed>
     */
    private function driverConfig(): array
    {
        if ($this->driverConfigCache !== null) {
            return $this->driverConfigCache;
        }

        $driver = $this->bindingResolver->captchaDriverKey();
        if ($driver === '') {
            return $this->driverConfigCache = [];
        }

        return $this->driverConfigCache = $this->configRepository->resolvedConfigByDomainAndSlug(
            PluginDomain::CAPTCHA,
            $driver
        );
    }

    /**
     * 开关值可能以 "1" / "0" / true / false 等形态存库，统一归一。
     *
     * 返回 null 表示「无法解析」，由调用方回退到安全默认值，而不是当作关闭。
     * 插件配置接口只校验 config 是数组、PluginConfigRepository::save() 原样存值，
     * 因此 []、"enabled"、null、"" 这类异常值是可能落库的；若把它们判为 false，
     * 一个手误或脏数据就会静默关闭人机验证——安全开关必须 fail-closed。
     */
    private function truthy(mixed $value): ?bool
    {
        if ($value === null || $value === '' || is_array($value) || is_object($value)) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
