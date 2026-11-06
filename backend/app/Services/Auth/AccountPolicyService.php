<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\System\SettingService;

/**
 * 账户策略：实名、手机号、邮箱三项是否强制。
 *
 * 这里是**唯一判据**。此前这三类要求散落在中间件、提现、充值、开放接口密钥等处，
 * 有的写死、有的各自读一份配置，运营想统一收紧只能改代码。现在：
 *
 * - 配置落在通用设置分组 `account`（复用 settings 表与分组缓存，不自建配置表）；
 * - 所有入口只调 {@see assertSatisfied()}，不再各写一遍 `hasCompletedVerification()`；
 * - 只拦「会产生资金或凭据的写操作」，不拦登录与只读接口 —— 老用户被收紧后第一次
 *   进控制台仍然看到完整页面，只是点下单/提现时被要求补齐；
 * - 老用户第一次触达就能预知要补什么：`auth/info` 随用户信息下发 {@see payload()}
 *   的缺失项与提示文案，不需要先撞一次 403 才知道。
 *
 * 配置读取走 Setting::getValue（分组缓存 600 秒），而保存设置的
 * SettingService::saveGroupSettings() → Setting::setValues() 会清掉该分组缓存，
 * 因此管理端一保存就立刻生效，不需要重启或手动清缓存。
 */
final class AccountPolicyService
{
    /** 设置分组名：与 SettingService::getAccountPolicyConfig() 同一处定义 */
    public const GROUP = SettingService::ACCOUNT_POLICY_GROUP;

    public const ITEM_VERIFICATION = 'verification';

    public const ITEM_PHONE = 'phone';

    public const ITEM_EMAIL = 'email';

    /** 缺失项 → 用户看得懂的补齐动作 */
    private const ITEM_ACTIONS = [
        self::ITEM_VERIFICATION => '实名认证',
        self::ITEM_PHONE => '绑定手机号',
        self::ITEM_EMAIL => '绑定邮箱',
    ];

    /**
     * 40301 是多个业务线共用的「需要先补齐账户信息/凭证」码，前端要能认出
     * 「这一条是账户策略、并且缺哪几项」，所以错误响应体里带 scope 标记。
     */
    public const ERROR_SCOPE = 'account_policy';

    /** 入口名称：拼「下单前请先完成…」用，场景不在表里也能兜住 */
    private const SCENE_LABELS = [
        'checkout' => '下单',
        'payment' => '支付',
        'recharge' => '充值',
        'withdrawal' => '提现',
        'console' => '继续操作',
    ];

    public function __construct(
        private readonly SettingService $settings,
    ) {}

    /**
     * 三个开关的当前值。
     *
     * @return array{require_verification: bool, require_phone: bool, require_email: bool}
     */
    public function requirements(): array
    {
        return $this->settings->getAccountPolicyConfig();
    }

    /**
     * 当前用户还缺哪几项（顺序固定：实名 → 手机 → 邮箱）。
     *
     * 只返回「要求成立且用户没满足」的项；三个开关全关且没有额外强制项时永远是空数组。
     *
     * `$forcedItems` 供「本条业务线另有硬要求」的入口使用（当前只有充值：管理端
     * `finance.recharge_requires_verification` 打开时，本次充值额外强制实名），
     * 它与账户策略是「OR」关系——任一要求未满足即拦，但**判定只在这里做一次**。
     *
     * @param  array<int, string>  $forcedItems
     * @return array<int, string>
     */
    public function missingItems(?User $user, array $forcedItems = []): array
    {
        if (! $user instanceof User) {
            return [];
        }

        $requirements = $this->requirements();
        $required = [
            self::ITEM_VERIFICATION => $requirements['require_verification'],
            self::ITEM_PHONE => $requirements['require_phone'],
            self::ITEM_EMAIL => $requirements['require_email'],
        ];

        foreach ($forcedItems as $item) {
            if (array_key_exists($item, $required)) {
                $required[$item] = true;
            }
        }

        $missing = [];

        if ($required[self::ITEM_VERIFICATION] && ! $user->hasCompletedVerification()) {
            $missing[] = self::ITEM_VERIFICATION;
        }

        if ($required[self::ITEM_PHONE] && trim((string) ($user->phone ?? '')) === '') {
            $missing[] = self::ITEM_PHONE;
        }

        if ($required[self::ITEM_EMAIL] && trim((string) ($user->email ?? '')) === '') {
            $missing[] = self::ITEM_EMAIL;
        }

        return $missing;
    }

    /**
     * 下发给客户端的策略载荷。
     *
     * 老用户被策略收紧后，控制台第一次请求 `auth/info` 就能拿到缺失项与可直接展示的
     * 提示，在页面上做引导，而不是等用户点下单撞出一个 403。
     *
     * @return array{require_verification: bool, require_phone: bool, require_email: bool, missing_items: array<int, string>, message: string}
     */
    public function payload(?User $user): array
    {
        $requirements = $this->requirements();
        $missing = $this->missingItems($user);

        return [
            'require_verification' => $requirements['require_verification'],
            'require_phone' => $requirements['require_phone'],
            'require_email' => $requirements['require_email'],
            'missing_items' => $missing,
            'message' => $missing === [] ? '' : $this->message('console', $missing),
        ];
    }

    /**
     * 关键写操作的统一断言：不满足就抛 40301，提示里点名缺哪几项。
     *
     * 策略全关（且没有额外强制项）时 missingItems() 恒为空，这里直接放行，等于没有这层校验。
     *
     * @param  array<int, string>  $forcedItems  本条业务线额外强制要求的项，见 missingItems()
     */
    public function assertSatisfied(?User $user, string $scene, array $forcedItems = []): void
    {
        $missing = $this->missingItems($user, $forcedItems);

        if ($missing === []) {
            return;
        }

        // 带上 context：前端据此在拦截弹窗里给出「去实名 / 去绑定」直达按钮，
        // 而不是只有一句文案（会话期间策略被改、info 还没刷新时也能正确引导）。
        throw new BusinessException($this->message($scene, $missing), 40301, 403, [
            'scope' => self::ERROR_SCOPE,
            'scene' => $scene,
            'missing_items' => array_values($missing),
        ]);
    }

    /**
     * 提示文案：入口 + 待补齐项，例如「下单前请先完成实名认证、绑定手机号」。
     *
     * @param  array<int, string>  $missing
     */
    public function message(string $scene, array $missing): string
    {
        $actions = array_values(array_filter(array_map(
            fn (string $item): string => self::ITEM_ACTIONS[$item] ?? '',
            $missing
        )));

        if ($actions === []) {
            return '';
        }

        $sceneLabel = self::SCENE_LABELS[$scene] ?? self::SCENE_LABELS['console'];

        return $sceneLabel.'前请先完成'.implode('、', $actions);
    }
}
