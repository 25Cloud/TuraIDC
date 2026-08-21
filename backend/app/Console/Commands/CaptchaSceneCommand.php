<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IntegrationPlugin;
use App\Services\Auth\CaptchaPolicyService;
use App\Services\Integrations\Plugins\IntegrationDriverBindingResolver;
use App\Services\Integrations\Plugins\IntegrationPluginService;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginDomain;
use Illuminate\Console\Command;

/**
 * 人机验证场景开关的命令行入口，兼作「被验证码挡在后台之外」时的逃生通道。
 *
 * 为什么需要它：管理员登录场景一旦开启，人机验证就成了进入后台的前置条件。
 * 若验证服务商侧出现故障或误判（例如 Cloudflare 把正常访问判为可疑并持续拒绝），
 * 管理员就进不了后台——而关闭开关、停用插件这些补救操作本身又要求先进后台，形成死锁。
 * 这个命令让运维可以直接在服务器上解锁，不依赖后台界面。
 *
 * 用法：
 *   php artisan captcha:scene --list                 查看当前驱动与各场景开关
 *   php artisan captcha:scene admin_login off        关闭管理员登录的人机验证
 *   php artisan captcha:scene admin_login on         重新开启
 *   php artisan captcha:scene --disable-plugin       应急：停用当前验证码插件（全站不再要求验证）
 */
class CaptchaSceneCommand extends Command
{
    protected $signature = 'captcha:scene
        {scene? : 场景标识（client_login / client_register / admin_login / email_code / phone_code）}
        {state? : on 或 off}
        {--list : 只查看当前状态}
        {--disable-plugin : 应急停用当前启用的验证码插件，使全站不再要求人机验证}';

    protected $description = '查看或修改人机验证的场景开关（也是被验证码锁在后台之外时的逃生通道）';

    public function handle(
        CaptchaPolicyService $policy,
        IntegrationDriverBindingResolver $bindingResolver,
        PluginConfigRepository $configRepository,
        IntegrationPluginService $pluginService,
    ): int {
        $driver = $bindingResolver->captchaDriverKey();

        if ((bool) $this->option('disable-plugin')) {
            return $this->disableActivePlugin($driver, $pluginService);
        }

        $scene = (string) ($this->argument('scene') ?? '');
        $state = (string) ($this->argument('state') ?? '');

        if ((bool) $this->option('list') || $scene === '') {
            return $this->showStatus($policy, $driver);
        }

        if (! in_array($scene, CaptchaPolicyService::scenes(), true)) {
            $this->error('未知场景：'.$scene);
            $this->line('可用场景：'.implode(', ', CaptchaPolicyService::scenes()));

            return self::FAILURE;
        }

        if (! in_array($state, ['on', 'off'], true)) {
            $this->error('状态只能是 on 或 off');

            return self::FAILURE;
        }

        if ($driver === '') {
            $this->warn('当前没有启用任何验证码插件，开关无处存放；此状态下全站本就不要求人机验证。');

            return self::FAILURE;
        }

        $plugin = IntegrationPlugin::query()
            ->where('domain', PluginDomain::CAPTCHA)
            ->where('slug', $driver)
            ->first();

        if (! $plugin instanceof IntegrationPlugin) {
            $this->error("未找到已安装的插件记录：{$driver}");

            return self::FAILURE;
        }

        // updateConfig 是全量覆盖语义，必须带上现有配置再改目标键，否则会清空密钥
        $config = $configRepository->resolvedConfigByDomainAndSlug(PluginDomain::CAPTCHA, $driver);
        $config[CaptchaPolicyService::configKey($scene)] = $state === 'on';

        $pluginService->updateConfig($plugin, $config);

        $this->info(sprintf('已将「%s」的人机验证设为 %s（插件：%s）', $scene, $state === 'on' ? '开启' : '关闭', $driver));
        $this->newLine();

        return $this->showStatus(app(CaptchaPolicyService::class), $driver);
    }

    private function showStatus(CaptchaPolicyService $policy, string $driver): int
    {
        $this->info('当前验证码驱动：'.($driver === '' ? '（未启用任何插件，全站不要求人机验证）' : $driver));
        $this->newLine();

        $rows = [];
        foreach ($policy->describeScenes() as $scene) {
            $rows[] = [
                $scene['scene'],
                $scene['label'],
                $scene['enabled'] ? '开启' : '关闭',
                $policy->requiresCaptcha($scene['scene']) ? '是' : '否',
            ];
        }

        $this->table(['场景标识', '名称', '开关', '本次是否要求验证'], $rows);

        return self::SUCCESS;
    }

    private function disableActivePlugin(string $driver, IntegrationPluginService $pluginService): int
    {
        if ($driver === '') {
            $this->warn('当前没有启用任何验证码插件，无需停用。');

            return self::SUCCESS;
        }

        $plugin = IntegrationPlugin::query()
            ->where('domain', PluginDomain::CAPTCHA)
            ->where('slug', $driver)
            ->first();

        if (! $plugin instanceof IntegrationPlugin) {
            $this->error("未找到已安装的插件记录：{$driver}");

            return self::FAILURE;
        }

        $pluginService->disable($plugin);

        $this->info("已停用验证码插件「{$driver}」，全站暂时不再要求人机验证。");
        $this->warn('这是应急措施：登录入口此时仅剩失败次数限流保护，请尽快修复后重新启用。');

        return self::SUCCESS;
    }
}
