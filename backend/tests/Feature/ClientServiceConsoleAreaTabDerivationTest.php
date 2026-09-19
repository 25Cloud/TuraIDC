<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ClientServiceConsole\ServiceConsoleAreaService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * 自定义控制台区域「模块 → tab」推导回归。
 *
 * 背景：上游 /host/header 的 module_button 是控制按钮（开机/重启/退出救援系统…），
 * module_client_area 才是产品自定义 tab。zjmf 驱动归一后两类都会带上
 * type=custom 与 select，此前的判定 `select === 'client_area' || type === 'custom'`
 * 会把按钮也当成 tab（线上表现：侧栏多出「退出救援系统」），而 module_client_area 里的
 * 安全组模块又和内置「安全组」tab 撞车（同一模块两处入口）。
 *
 * 这里用真实上游载荷的归一结果锁定判定口径：
 *   - module_button 永不进 tab；
 *   - 安全组/NAT 模块由内置 tab 承载，不产生自定义区域；
 *   - 其余 module_client_area（如 setting）仍要正常成为 tab，且 NAT/监控能力位不丢。
 *
 * 继承裸 PHPUnit TestCase：不启动 Laravel、不连数据库，可在任何环境安全执行。
 */
class ClientServiceConsoleAreaTabDerivationTest extends TestCase
{
    /**
     * 真实上游（智简魔方财务 3.x）实例 3458 → host_id 68873 的归一模块列表。
     *
     * @return array<int, array<string, mixed>>
     */
    private function realZjmfModules(): array
    {
        return [
            ['type' => 'default', 'function' => 'on', 'name' => '开机', 'select' => 'control'],
            ['type' => 'default', 'function' => 'off', 'name' => '关机', 'select' => 'control'],
            ['type' => 'default', 'function' => 'reinstall', 'name' => '重装系统', 'select' => 'control'],
            ['type' => 'default', 'function' => 'crack_pass', 'name' => '重置密码', 'select' => 'control'],
            ['type' => 'default', 'function' => 'rescue_system', 'name' => '救援系统', 'select' => 'control'],
            ['type' => 'custom', 'function' => 'exitRescue', 'name' => '退出救援系统', 'select' => 'control'],
            ['type' => 'default', 'function' => 'vnc', 'name' => 'VNC', 'select' => 'console'],
            ['type' => 'custom', 'function' => 'security_groups', 'name' => '安全组', 'select' => 'client_area'],
            ['type' => 'custom', 'function' => 'setting', 'name' => '设置', 'select' => 'client_area'],
            ['type' => 'custom', 'function' => 'nat_acl', 'name' => 'NAT转发', 'select' => 'client_area'],
            [
                'type' => 'default',
                'function' => 'charts',
                'name' => '图表',
                'select' => [
                    ['value' => 'cpu', 'label' => 'CPU'],
                    ['value' => 'memory', 'label' => '内存'],
                ],
            ],
        ];
    }

    #[Test]
    public function module_button_entries_never_become_tabs(): void
    {
        $capabilities = $this->derive($this->realZjmfModules());

        $this->assertSame(
            [['key' => 'setting', 'name' => '设置']],
            $capabilities['areas'],
            '只有 module_client_area（select=client_area）能成为自定义 tab，module_button 的按钮（含 type=custom 的退出救援系统）不能'
        );
    }

    #[Test]
    public function builtin_backed_modules_keep_their_capability_flags(): void
    {
        $capabilities = $this->derive($this->realZjmfModules());

        $this->assertTrue($capabilities['nat_supported'], 'NAT 模块应转成内置「端口转发」tab 的能力位');
        $this->assertTrue($capabilities['monitor_supported'], 'module_chart 应转成内置「监控信息」tab 的能力位');
    }

    #[Test]
    public function security_group_module_does_not_duplicate_builtin_tab(): void
    {
        $capabilities = $this->derive([
            ['type' => 'custom', 'function' => 'security_group', 'name' => '安全组', 'select' => 'client_area'],
            ['type' => 'custom', 'function' => 'security_groups', 'name' => 'Security Groups', 'select' => 'client_area'],
            ['type' => 'custom', 'function' => 'firewall_acl', 'name' => '防火墙', 'select' => 'client_area'],
        ]);

        $this->assertSame(
            [],
            $capabilities['areas'],
            '安全组模块由内置「安全组」tab 承载（ServiceSecurityGroupService 解析同一模块），不能再渲染成自定义 tab'
        );
    }

    #[Test]
    public function other_client_area_modules_still_become_tabs(): void
    {
        $capabilities = $this->derive([
            ['type' => 'custom', 'function' => 'snapshot', 'name' => '快照', 'select' => 'client_area'],
            ['type' => 'custom', 'function' => 'snapshot', 'name' => '快照副本', 'select' => 'client_area'],
            ['type' => 'custom', 'function' => 'traffic_package', 'name' => '', 'select' => 'client_area'],
        ]);

        $this->assertSame([
            ['key' => 'snapshot', 'name' => '快照'],
            ['key' => 'traffic_package', 'name' => 'traffic_package'],
        ], $capabilities['areas'], '非内置能力仍应成为 tab，且同名模块只保留一个，缺名时回退 key');
    }

    /**
     * @param  array<int, array<string, mixed>>  $modules
     * @return array{areas: array<int, array{key: string, name: string}>, nat_supported: bool, monitor_supported: bool}
     */
    private function derive(array $modules): array
    {
        $service = (new ReflectionClass(ServiceConsoleAreaService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ServiceConsoleAreaService::class, 'deriveModuleCapabilities');
        $method->setAccessible(true);

        return $method->invoke($service, $modules, 68873);
    }
}
