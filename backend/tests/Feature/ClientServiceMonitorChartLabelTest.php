<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ClientServiceConsole\ClientServiceConsoleService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * 监控图表「指标名」回归。
 *
 * 背景：内置「监控信息」tab 的四个图表原本标签退化成 cpu / cpu / 内存 / cpu。
 * 根因有两层：
 *   1) 上游 /host/header 的 module_chart 条目用的是 title 字段
 *      （CPU使用量 / 硬盘IO / 内存用量 / 网卡），
 *      ZjmfConsoleService::monitorChartOptions() 却只读 label / name，
 *      取空后走 monitorTypeLabel() 兜底，而该映射又只认 bw / disk_io / memory，
 *      于是 disk / flow 都落到默认值；
 *   2) 应用层 normalizeMonitorDisplayLabel() 是给「读取/写入」这类曲线名用的归一器，
 *      被顺手用在指标名上，把上游标题又塌缩了一次（CPU使用量→cpu、网卡→宽带）。
 *
 * 这里用真实上游载荷锁定「指标名必须等于上游 title」这个口径。
 *
 * 继承裸 PHPUnit TestCase：不启动 Laravel、不连数据库，可在任何环境安全执行。
 */
class ClientServiceMonitorChartLabelTest extends TestCase
{
    /**
     * 真实上游 module_chart 载荷（智简魔方财务 v8.0.33，实例 3458）。
     *
     * @return array<int, array<string, string>>
     */
    private function realModuleChart(): array
    {
        return [
            ['title' => 'CPU使用量', 'type' => 'cpu'],
            ['title' => '硬盘IO', 'type' => 'disk'],
            ['title' => '内存用量', 'type' => 'memory'],
            ['title' => '网卡', 'type' => 'flow'],
        ];
    }

    #[Test]
    public function upstream_chart_titles_survive_the_app_layer(): void
    {
        $options = $this->normalizeChartOptionList($this->pluginChartOptions());

        $this->assertSame([
            ['value' => 'cpu', 'label' => 'CPU使用量'],
            ['value' => 'disk', 'label' => '硬盘IO'],
            ['value' => 'memory', 'label' => '内存用量'],
            ['value' => 'flow', 'label' => '网卡'],
        ], $options, '指标名必须透传上游 module_chart.title，不能被曲线名归一器塌缩');
    }

    #[Test]
    public function chart_card_label_matches_the_selected_metric(): void
    {
        $options = $this->normalizeChartOptionList($this->pluginChartOptions());

        foreach (['cpu' => 'CPU使用量', 'disk' => '硬盘IO', 'memory' => '内存用量', 'flow' => '网卡'] as $type => $title) {
            $this->assertSame(
                $title,
                $this->resolveChartOptionLabel($type, $options),
                "图表卡片标题（type={$type}）应显示上游指标名"
            );
        }
    }

    #[Test]
    public function plugin_reads_title_and_maps_the_real_upstream_types(): void
    {
        $this->assertSame([
            ['value' => 'cpu', 'label' => 'CPU使用量'],
            ['value' => 'disk', 'label' => '硬盘IO'],
            ['value' => 'memory', 'label' => '内存用量'],
            ['value' => 'flow', 'label' => '网卡'],
        ], $this->pluginChartOptions(), '插件层应读取上游 title，而不是回退成类型名');

        // 上游没给 title 时的兜底：cpu / disk / memory / flow 都要有像样的中文名
        $this->assertSame('CPU', $this->pluginTypeLabel('cpu'));
        $this->assertSame('磁盘 I/O', $this->pluginTypeLabel('disk'));
        $this->assertSame('内存', $this->pluginTypeLabel('memory'));
        $this->assertSame('带宽', $this->pluginTypeLabel('flow'));
    }

    #[Test]
    public function app_layer_fallback_only_when_upstream_omits_the_title(): void
    {
        // 上游只给 type、没给 title：用类型兜底名，而不是空串
        $options = $this->normalizeChartOptionList([
            ['value' => 'disk'],
            ['value' => 'flow'],
        ]);

        $this->assertSame([
            ['value' => 'disk', 'label' => '磁盘 I/O'],
            ['value' => 'flow', 'label' => '带宽'],
        ], $options);

        $this->assertSame('内存', $this->resolveChartOptionLabel('memory', [], ''));
    }

    /** 插件层：真实 module_chart 载荷 → 图表选项 */
    private function pluginChartOptions(): array
    {
        $class = 'TuraIDC\\Plugins\\Servers\\ZjmfFinance\\Lib\\ZjmfConsoleService';
        $this->ensurePluginLoaded($class);

        $service = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($class, 'monitorChartOptions');
        $method->setAccessible(true);

        return $method->invoke($service, ['module_chart' => $this->realModuleChart()]);
    }

    private function pluginTypeLabel(string $type): string
    {
        $class = 'TuraIDC\\Plugins\\Servers\\ZjmfFinance\\Lib\\ZjmfConsoleService';
        $this->ensurePluginLoaded($class);

        $service = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($class, 'monitorTypeLabel');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $type);
    }

    /** 插件类不在 composer autoload 里（运行时由 PluginFileLoader 装载），测试里直接引入。 */
    private function ensurePluginLoaded(string $class): void
    {
        if (! class_exists($class)) {
            require_once dirname(__DIR__, 2).'/plugins/servers/zjmf_finance/lib/ZjmfConsoleService.php';
        }

        $this->assertTrue(class_exists($class), '插件类应可加载');
    }

    /**
     * 应用层私有实现：选项列表归一（不走 Laravel 容器，故用无构造实例）。
     *
     * @param  array<int, mixed>  $options
     * @return array<int, array{value: string, label: string}>
     */
    private function normalizeChartOptionList(array $options): array
    {
        $service = (new ReflectionClass(ClientServiceConsoleService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ClientServiceConsoleService::class, 'normalizeChartOptionList');
        $method->setAccessible(true);

        return $method->invoke($service, $options);
    }

    /**
     * @param  array<int, array{value: string, label: string}>  $options
     */
    private function resolveChartOptionLabel(string $type, array $options): string
    {
        $service = (new ReflectionClass(ClientServiceConsoleService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ClientServiceConsoleService::class, 'resolveChartOptionLabel');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $type, $options);
    }
}
