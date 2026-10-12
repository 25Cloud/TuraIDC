<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 运行环境要求的唯一真源。
 *
 * 此前 PHP 版本与扩展清单散落在安装向导代码和多份部署文档里且互不一致
 * （代码只检 6 个扩展，文档另有 zip / gd / curl / bcmath / opcache），
 * 禁用函数更是全仓零检测：宝塔默认禁用 putenv / proc_open / symlink，
 * 属于最高频的部署故障，却只能等 composer 报错后由用户自己发现。
 *
 * 这里集中定义三张清单，安装向导、app:doctor 命令与后台环境自检共用同一份。
 */
final class EnvironmentRequirements
{
    /**
     * 最低 PHP 版本。与 composer.json 的 php 约束保持一致。
     */
    public const MIN_PHP_VERSION = '8.3.0';

    /**
     * 必需 PHP 扩展 → 说明。
     *
     * 只收录运行期真实依赖的扩展：缺任一项都会让对应子系统不可用，
     * 而 zip / gd / opcache 等仅在特定功能用到，列为可选而非阻断安装。
     *
     * @var array<string, string>
     */
    public const REQUIRED_EXTENSIONS = [
        'pdo_mysql' => '数据库连接',
        'redis' => '缓存与队列',
        'openssl' => '加密与签名校验',
        'mbstring' => '多字节字符串处理',
        'fileinfo' => '文件类型识别',
        'json' => 'JSON 编解码',
    ];

    /**
     * 建议安装但不阻断安装的扩展。
     *
     * @var array<string, string>
     */
    public const OPTIONAL_EXTENSIONS = [
        'zip' => '压缩包导出',
        'gd' => '图片处理',
        'curl' => '外部 HTTP 请求',
        'bcmath' => '高精度金额计算',
        'opcache' => '性能优化',
        'intl' => '国际化',
    ];

    /**
     * 必须可用的函数 —— 出现在 disable_functions 里就会让依赖它的子系统失效。
     *
     * 宝塔面板的「禁用函数」默认包含 putenv / proc_open / symlink，
     * Laravel 的 .env 读取与进程调用都依赖它们。
     *
     * @var list<string>
     */
    public const REQUIRED_FUNCTIONS = [
        'putenv',
        'proc_open',
        'symlink',
    ];

    /**
     * 检查结果。
     *
     * @return list<array{name: string, required: bool, passed: bool, message: string}>
     */
    public static function inspect(): array
    {
        $items = [
            [
                'name' => 'PHP 版本 >= '.self::MIN_PHP_VERSION,
                'required' => true,
                'passed' => version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>='),
                'message' => '当前 '.PHP_VERSION,
            ],
        ];

        foreach (self::REQUIRED_EXTENSIONS as $extension => $purpose) {
            $items[] = [
                // 同一扩展可能在多个用途下列出，去重后只保留一条。
                'name' => 'PHP 扩展 '.$extension,
                'required' => true,
                'passed' => extension_loaded($extension),
                'message' => $purpose,
            ];
        }

        foreach (self::OPTIONAL_EXTENSIONS as $extension => $purpose) {
            $items[] = [
                'name' => 'PHP 扩展 '.$extension.'（建议）',
                'required' => false,
                'passed' => extension_loaded($extension),
                'message' => $purpose,
            ];
        }

        foreach (self::inspectDisabledFunctions() as $item) {
            $items[] = $item;
        }

        foreach ($items as &$item) {
            $item['message'] = trim((string) $item['message']);
            $item['name'] = (string) $item['name'];
            $item['required'] = (bool) $item['required'];
            $item['passed'] = (bool) $item['passed'];
        }
        unset($item);

        return $items;
    }

    /**
     * 检出被 disable_functions 禁掉的必需函数。
     *
     * @return list<array{name: string, required: bool, passed: bool, message: string}>
     */
    public static function inspectDisabledFunctions(): array
    {
        $disabled = self::disabledFunctions();
        $items = [];

        foreach (self::REQUIRED_FUNCTIONS as $function) {
            $blocked = in_array($function, $disabled, true);
            $items[] = [
                'name' => '可调用函数 '.$function,
                'required' => true,
                // 被禁用时 function_exists() 仍返回 true（PHP 8 才对部分函数改行为），
                // 因此以 disable_functions 列表为准，这是唯一可靠信号。
                'passed' => ! $blocked,
                'message' => $blocked
                    ? '已被 disable_functions 禁用，请在 PHP 配置中解除后重启 PHP'
                    : '',
            ];
        }

        return $items;
    }

    /**
     * 当前进程生效的禁用函数清单。
     *
     * @return list<string>
     */
    public static function disabledFunctions(): array
    {
        $raw = (string) ini_get('disable_functions');

        if (trim($raw) === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(static fn (string $name): string => strtolower(trim($name)))
            ->filter(static fn (string $name): bool => $name !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 必选项是否全部通过。
     */
    public static function passed(): bool
    {
        foreach (self::inspect() as $item) {
            if ($item['required'] && ! $item['passed']) {
                return false;
            }
        }

        return true;
    }
}
