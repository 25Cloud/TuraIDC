<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Supplier;
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
     * 真实上游（智简魔方 3.x）host/header 的 module_client_area。
     * 三个 key 全部同时出现在 supported_modules 里，两处数据源高度重叠。
     *
     * @return array<int, array{key: string, name: string}>
     */
    private function realHeaderClientAreas(): array
    {
        return [
            ['name' => '安全组', 'key' => 'security_groups'],
            ['name' => '设置', 'key' => 'setting'],
            ['name' => 'NAT转发', 'key' => 'nat_acl'],
        ];
    }

    /**
     * 回归：module_client_area 补刀时必须与 deriveModuleCapabilities 同一口径分流。
     *
     * 线上现象：NAT 产品明明已有内置「端口转发」tab，侧栏却还多出一个功能完全相同的
     * 「NAT转发」——因为补刀只跳过「已在 areas 里的 key」，而被 derive 分流掉的
     * security_groups / nat_acl 并不在 areas 里，于是 header 把它们又加了回来。
     * 「安全组」同样重复（前端 BUILTIN_TABS_ORDER 固定下发内置 security tab）。
     */
    #[Test]
    public function header_client_areas_do_not_duplicate_the_builtin_tabs(): void
    {
        $capabilities = $this->merge(
            [
                'areas' => [['key' => 'setting', 'name' => '设置']],
                'nat_supported' => true,
                'monitor_supported' => true,
            ],
            ['module_client_area' => $this->realHeaderClientAreas()]
        );

        $this->assertSame(
            [['key' => 'setting', 'name' => '设置']],
            $capabilities['areas'],
            '内置 tab 已承载的 NAT/安全组不能被 header 重复下发，否则同一功能出现两个入口'
        );
    }

    /**
     * 反向边界：nat_supported 为 false 时 header 里的 NAT 区域是唯一入口，必须保留。
     * 无条件按关键字跳过会让 NAT 功能整个消失（supported_modules 没有 NAT 的上游）。
     */
    #[Test]
    public function header_nat_area_survives_when_no_builtin_nat_tab_exists(): void
    {
        $capabilities = $this->merge(
            ['areas' => [], 'nat_supported' => false, 'monitor_supported' => false],
            ['module_client_area' => [['name' => 'NAT转发', 'key' => 'nat_acl']]]
        );

        $this->assertSame(
            [['key' => 'nat_acl', 'name' => 'NAT转发']],
            $capabilities['areas'],
            '内置「端口转发」tab 不存在时，header 里的 NAT 区域不能被分流掉'
        );
    }

    /** header 独有的自定义区域仍必须补进来——这正是补这一刀的初衷（天理云 CDN）。 */
    #[Test]
    public function header_only_custom_areas_are_still_merged(): void
    {
        $capabilities = $this->merge(
            ['areas' => [], 'nat_supported' => false, 'monitor_supported' => false],
            ['module_client_area' => [['name' => '管理面板', 'key' => 'panel_manage']]]
        );

        $this->assertSame(
            [['key' => 'panel_manage', 'name' => '管理面板']],
            $capabilities['areas'],
            '只在上游 header 里声明的自定义区域仍要补进来'
        );
    }

    /**
     * 预热开关（system.console_area_preload_all_enabled）的取值口径。
     *
     * 默认开启是产品决定：进入控制台就把所有自定义区域 iframe 拉起来，切换零等待；
     * 想要「只预热第一个」的管理员再显式关掉。空值同样按开启处理 —— 设置项被清空或
     * settings 表读不到时，不该让全站面板静默退回单区域预热。
     */
    #[Test]
    public function area_preload_switch_defaults_to_on(): void
    {
        foreach ([null, '', '1', 1, 'true', 'TRUE', ' on ', 'yes', true] as $value) {
            $this->assertTrue(
                $this->interpretPreload($value),
                '该取值应解释为开启预热：'.var_export($value, true)
            );
        }

        foreach (['0', 0, 'false', 'off', 'no', false] as $value) {
            $this->assertFalse(
                $this->interpretPreload($value),
                '该取值应解释为关闭预热：'.var_export($value, true)
            );
        }
    }

    private function interpretPreload(mixed $value): bool
    {
        $service = (new ReflectionClass(ServiceConsoleAreaService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ServiceConsoleAreaService::class, 'interpretPreloadSetting');
        $method->setAccessible(true);

        return (bool) $method->invoke($service, $value);
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @param  array<string, mixed>  $header
     * @return array<string, mixed>
     */
    private function merge(array $capabilities, array $header): array
    {
        $service = (new ReflectionClass(ServiceConsoleAreaService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ServiceConsoleAreaService::class, 'mergeHeaderClientAreas');
        $method->setAccessible(true);

        $runtime = new class($header)
        {
            public function __construct(private readonly array $header) {}

            public function getHostHeaderPayload($supplier, int $hostId, string $jwt): array
            {
                return $this->header;
            }
        };

        return (array) $method->invoke($service, $capabilities, $runtime, new Supplier, 68873, 'JWT');
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
