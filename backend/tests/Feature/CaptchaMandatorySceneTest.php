<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Auth\CaptchaPolicyService;
use App\Services\Auth\GeeTestService;
use App\Services\Integrations\Plugins\IntegrationDriverBindingResolver;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginDomain;
use Tests\TestCase;

/**
 * 「强制场景」不可被关闭。
 *
 * 保护对象是花钱与账号唯一信息的入口：两个发码端点是公开接口且直接产生对外成本
 * （短信按条计费、邮件受配额与投诉率约束），注册直接写入手机号/邮箱。
 * 这类开关一旦可关，管理员一次误操作或一条脏配置就会把它们完全敞开。
 *
 * 同时钉住「不会把管理员锁在外面」：登录类场景仍然可关。
 */
class CaptchaMandatorySceneTest extends TestCase
{
    public function test_mandatory_scenes_are_exactly_register_and_both_code_senders(): void
    {
        $this->assertSame(
            [
                CaptchaPolicyService::SCENE_CLIENT_REGISTER,
                CaptchaPolicyService::SCENE_EMAIL_CODE,
                CaptchaPolicyService::SCENE_PHONE_CODE,
            ],
            CaptchaPolicyService::MANDATORY_SCENES
        );

        foreach (CaptchaPolicyService::MANDATORY_SCENES as $scene) {
            $this->assertTrue(CaptchaPolicyService::isMandatory($scene), "{$scene} 应为强制场景");
        }

        // 登录类场景必须仍可关闭：它们才是「验证服务商故障 → 进不了后台」死锁的来源
        foreach ([CaptchaPolicyService::SCENE_CLIENT_LOGIN, CaptchaPolicyService::SCENE_ADMIN_LOGIN] as $scene) {
            $this->assertFalse(
                CaptchaPolicyService::isMandatory($scene),
                "{$scene} 必须保持可关闭，否则逃生通道失效"
            );
        }
    }

    public function test_mandatory_scenes_stay_enabled_even_when_config_turns_them_off(): void
    {
        // 把三个强制开关全部显式关掉，并给登录开关也关掉作对照
        $policy = $this->policyWithConfig([
            'scene_client_register' => false,
            'scene_email_code' => '0',
            'scene_phone_code' => 'off',
            'scene_client_login' => false,
        ]);

        foreach (CaptchaPolicyService::MANDATORY_SCENES as $scene) {
            $this->assertTrue(
                $policy->isSceneEnabled($scene),
                "{$scene} 被配置关掉后仍必须要求验证"
            );
        }

        // 对照：可关闭场景确实被关掉了，说明读取链路本身是通的，
        // 上面的 true 不是因为配置根本没生效
        $this->assertFalse($policy->isSceneEnabled(CaptchaPolicyService::SCENE_CLIENT_LOGIN));
    }

    public function test_scene_map_and_describe_report_mandatory_flag(): void
    {
        $policy = $this->policyWithConfig([
            'scene_phone_code' => false,
            'scene_client_login' => false,
        ]);

        $this->assertTrue($policy->sceneMap()[CaptchaPolicyService::SCENE_PHONE_CODE]);
        $this->assertFalse($policy->sceneMap()[CaptchaPolicyService::SCENE_CLIENT_LOGIN]);

        $described = collect($policy->describeScenes())->keyBy('scene');

        $this->assertTrue($described[CaptchaPolicyService::SCENE_PHONE_CODE]['mandatory']);
        $this->assertTrue($described[CaptchaPolicyService::SCENE_PHONE_CODE]['enabled']);
        $this->assertFalse($described[CaptchaPolicyService::SCENE_ADMIN_LOGIN]['mandatory']);
    }

    /**
     * 未启用任何验证码插件时，强制场景也不得要求验证。
     *
     * 否则前端会收到 captcha_required 却没有组件可渲染——注册与发码会彻底不可用，
     * 这是比「少验一次」严重得多的故障。
     */
    public function test_mandatory_scenes_do_not_require_captcha_without_an_enabled_plugin(): void
    {
        $policy = new CaptchaPolicyService(
            new class extends GeeTestService
            {
                public function __construct() {}

                public function isEnabled(): bool
                {
                    return false;
                }
            },
            app(IntegrationDriverBindingResolver::class),
            app(PluginConfigRepository::class),
        );

        foreach (CaptchaPolicyService::MANDATORY_SCENES as $scene) {
            $this->assertFalse(
                $policy->requiresCaptcha($scene),
                "无可用插件时 {$scene} 不应要求验证，否则入口直接不可用"
            );
        }
    }

    /**
     * 场景开关声明里，三个强制场景必须带 disabled，避免管理界面上给出改不动的开关。
     */
    public function test_scene_switch_schema_locks_mandatory_switches(): void
    {
        $schema = require base_path('plugins/captcha/scene-switches.php');

        foreach (CaptchaPolicyService::MANDATORY_SCENES as $scene) {
            $key = CaptchaPolicyService::configKey($scene);

            $this->assertArrayHasKey($key, $schema, "场景开关声明缺少 {$key}");
            $this->assertTrue(
                $schema[$key]['disabled'] ?? false,
                "{$key} 是强制场景，界面开关必须标记 disabled"
            );
            $this->assertTrue($schema[$key]['value'] ?? false, "{$key} 的默认值必须为开启");
        }

        // 可关闭场景不得被误标为锁定
        foreach ([CaptchaPolicyService::SCENE_CLIENT_LOGIN, CaptchaPolicyService::SCENE_ADMIN_LOGIN] as $scene) {
            $key = CaptchaPolicyService::configKey($scene);
            $this->assertFalse($schema[$key]['disabled'] ?? false, "{$key} 应保持可关闭");
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function policyWithConfig(array $config): CaptchaPolicyService
    {
        $captchaService = new class extends GeeTestService
        {
            public function __construct() {}

            public function isEnabled(): bool
            {
                return true;
            }
        };

        $bindingResolver = new class extends IntegrationDriverBindingResolver
        {
            public function captchaDriverKey(): string
            {
                return 'geetest';
            }
        };

        $configRepository = new class($config) extends PluginConfigRepository
        {
            /** @param array<string, mixed> $stub */
            public function __construct(private readonly array $stub) {}

            public function resolvedConfigByDomainAndSlug(string $domain, string $slug): array
            {
                return $domain === PluginDomain::CAPTCHA ? $this->stub : [];
            }
        };

        return new CaptchaPolicyService($captchaService, $bindingResolver, $configRepository);
    }
}
