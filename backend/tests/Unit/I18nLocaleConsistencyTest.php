<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 国际化护栏：三语言词条一致性回归。
 *
 * 覆盖 docs/references/engineering/i18n.md 的约定：
 *  1. zh_CN / zh_TW / en_US 三份词条的 key 集合必须完全一致（漏补语言会在这里暴露）；
 *  2. zh_TW 不得残留简体（繁体由 backend/scripts/i18n/build-zh-tw.mjs 从 zh_CN 生成）；
 *  3. 各端 supportedLocales 必须与实际词条文件对应，且覆盖三语言；
 *  4. 组件库语言映射必须覆盖全部 locale（漏一项会让分页/日期文案不跟随切换）。
 *
 * 纯文件扫描，不连数据库，可在 CI 直接跑。
 */
class I18nLocaleConsistencyTest extends TestCase
{
    private const LOCALES = ['zh_CN', 'zh_TW', 'zh_HK', 'en_US'];

    /** 前端应用 => 其 locales 目录相对仓库根的位置 */
    private const APPS = [
        'frontend-admin-v3',
        'frontend-user-v4-console',
        'frontend-user-v3-www',
    ];

    /**
     * 简体独有字：这些字在繁体中一定写成别的形。
     * 出现即说明 zh_TW 没重新生成（详见 i18n.md 第五节）。
     *
     * @var list<string>
     */
    private const SIMPLIFIED_ONLY = [
        '们', '条', '决', '这', '国', '说', '问', '题', '实', '现', '数', '网', '络',
        '务', '缓', '认', '户', '软', '导', '邮', '箱', '注', '册', '机', '构',
        '设', '备', '资', '产', '货', '币', '亿', '仅', '优', '价', '质', '额',
        '择', '择', '拟', '让', '训', '议', '记', '讲', '许', '论', '设', '访',
        '证', '评', '识', '诉', '译', '试', '话', '该', '详', '语', '误', '请',
        '诸', '诺', '读', '课', '谁', '调', '谈', '谢', '谱', '贝', '负', '财',
        '责', '贤', '败', '账', '货', '购', '贴', '贵', '贸', '费', '贺', '贼',
        '资', '赛', '赞', '赠', '赢', '赵', '赶', '趋', '跃', '践', '车', '轨',
        '转', '轮', '软', '轰', '轴', '轻', '载', '轿', '较', '辈', '辉', '输',
        '边', '辽', '达', '迁', '过', '迈', '运', '还', '远', '违', '连', '迟',
        '选', '递', '遗', '邮', '郑', '释', '鉴', '针', '钉', '钓', '钟', '钢',
        '钥', '钦', '钩', '钱', '钳', '钻', '铁', '铃', '铅', '银', '铺', '链',
        '销', '锁', '锅', '锋', '错', '锡', '锦', '键', '镇', '镜', '长', '门',
        '闪', '闭', '问', '开', '间', '闻', '阅', '队', '阳', '阴', '阵', '阶',
        '际', '陆', '陈', '险', '随', '隐', '难', '雾', '静', '韩', '页', '顶',
        '项', '顺', '须', '顽', '顾', '顿', '颁', '预', '领', '颇', '颈', '频',
        '题', '颜', '额', '颠', '风', '飞', '饥', '饭', '饮', '饰', '饱', '饲',
        '饶', '饺', '饼', '饿', '馆', '馈', '馋', '馒', '马', '驰', '驱', '驳',
        '驻', '驾', '骂', '验', '骑', '骗', '骚', '骤', '鱼', '鲁', '鲜', '鲸',
        '鸟', '鸡', '鸣', '鸦', '鸭', '鹅', '鸽', '鸿', '鹏', '鹤', '鹰', '麦',
        '黄', '齐', '齿', '龄', '龙', '龟', '毕', '气', '汉', '汤', '沟', '没',
        '沦', '沪', '泪', '泻', '泽', '洁', '浅', '测', '济', '浏', '浓', '涂',
        '涛', '润', '涨', '渐', '渗', '温', '湾', '湿', '溃', '滚', '满', '滤',
        '滨', '灭', '灯', '灵', '灾', '点', '炼', '烂', '烛', '烟', '烦', '烧',
        '热', '爱', '牵', '状', '犹', '独', '狮', '狱', '猎', '猪', '猫', '献',
        '环', '现', '电', '画', '疗', '疯', '痒', '监', '盖', '盗', '盘', '睁',
        '矫', '矿', '码', '砖', '础', '确', '碍', '礼', '祸', '禄', '禅', '离',
        '种', '积', '称', '税', '稳', '穷', '窃', '竞', '笔', '笼', '筑', '筛',
        '签', '简', '篮', '类', '粤', '粪', '粮', '紧', '红', '约', '级', '纪',
        '纯', '纱', '纲', '纳', '纵', '纷', '纸', '纹', '线', '练', '组', '细',
        '织', '终', '经', '绑', '绒', '结', '绕', '绘', '给', '络', '绝', '绞',
        '统', '绢', '绣', '继', '绩', '绪', '续', '绳', '维', '绵', '综', '绿',
        '缓', '编', '缘', '缝', '缩', '缴', '网', '罗', '罚', '罢', '翘', '耻',
        '职', '联', '聪', '肃', '肠', '肤', '肾', '胆', '胜', '胶', '脉', '脑',
        '脚', '脱', '脸', '腾', '舰', '艰', '艳', '艺', '节', '苏', '苹', '茎',
        '荐', '荡', '荣', '药', '莲', '获', '莹', '萝', '营', '萧', '蓝', '虏',
        '虑', '虚', '虫', '虽', '虾', '蚀', '蚁', '蚂', '蚕', '蛮', '蜡', '蝇',
        '补', '衬', '袜', '装', '裤', '见', '观', '规', '视', '览', '觉', '触',
        '誉', '计', '订', '认', '讨', '让', '训', '议', '讯', '记', '讲', '许',
        '论', '讽', '设', '访', '诀', '证', '评', '识', '诉', '词', '译', '试',
        '诗', '诚', '话', '诞', '询', '该', '详', '诫', '语', '误', '说', '诵',
        '请', '诸', '诺', '读', '课', '谁', '调', '谅', '谈', '谋', '谎', '谐',
        '谓', '谜', '谢', '谣', '谦', '谨', '谱', '贝', '贞', '负', '贡', '财',
        '责', '贤', '败', '账', '货', '质', '贩', '贪', '贫', '购', '贴', '贵',
        '贷', '贸', '费', '贺', '贼', '贾', '贿', '赁', '资', '赋', '赌', '赎',
        '赏', '赐', '赔', '赖', '赚', '赛', '赞', '赠', '赢', '赵', '赶', '趋',
        '跃', '践', '车', '轨', '转', '轮', '软', '轰', '轴', '轻', '载', '轿',
        '较', '辈', '辉', '辐', '输', '辖', '辗', '辙', '辞', '辩', '边', '辽',
        '达', '迁', '过', '迈', '运', '还', '远', '违', '连', '迟', '适', '选',
        '逊', '递', '遗', '邓', '邮', '郑', '释', '鉴', '针', '钉', '钓', '钟',
        '钠', '钢', '钥', '钦', '钩', '钱', '钳', '钻', '铁', '铃', '铅', '银',
        '铺', '链', '销', '锁', '锂', '锅', '锋', '错', '锡', '锣', '锦', '键',
        '锯', '镇', '镜', '长', '门', '闪', '闭', '开', '间', '闻', '阅', '队',
        '阳', '阴', '阵', '阶', '际', '陆', '陈', '险', '随', '隐', '隶', '难',
        '雏', '雾', '霉', '静', '韩', '韦', '页', '顶', '顷', '项', '顺', '须',
        '顽', '顾', '顿', '颁', '预', '领', '颇', '颈', '频', '题', '颜', '额',
        '颠', '颤', '风', '飞', '饥', '饭', '饮', '饰', '饱', '饲', '饶', '饺',
        '饼', '饿', '馆', '馈', '馋', '馒', '马', '驰', '驱', '驳', '驻', '驾',
        '骂', '验', '骑', '骗', '骚', '骤', '鱼', '鲁', '鲜', '鲸', '鸟', '鸡',
        '鸣', '鸦', '鸭', '鹅', '鸽', '鸿', '鹏', '鹤', '鹰', '麦', '黄', '齐',
        '齿', '龄', '龙', '龟', '毕', '气', '汉', '汤', '沟', '没', '沦', '沪',
        '泪', '泻', '泽', '洁', '浅', '测', '济', '浏', '浓', '涂', '涛', '润',
        '涨', '渐', '渗', '温', '湾', '湿', '溃', '滚', '满', '滤', '滨', '灭',
        '灯', '灵', '灾', '点', '炼', '烂', '烛', '烟', '烦', '烧', '热', '爱',
        '牵', '状', '犹', '独', '狮', '狱', '猎', '猪', '猫', '献', '环', '现',
        '电', '画', '疗', '疯', '痒', '监', '盖', '盗', '盘', '睁', '矫', '矿',
        '码', '砖', '础', '确', '碍', '礼', '祸', '禄', '禅', '离', '种', '积',
        '称', '税', '稳', '穷', '窃', '竞', '笔', '笼', '筑', '筛', '签', '简',
        '篮', '类', '粤', '粪', '粮', '紧', '红', '约', '级', '纪', '纯', '纱',
        '纲', '纳', '纵', '纷', '纸', '纹', '线', '练', '组', '细', '织', '终',
        '经', '绑', '绒', '结', '绕', '绘', '给', '络', '绝', '统', '绢', '绣',
        '继', '绩', '绪', '续', '绳', '维', '综', '绿', '缓', '编', '缘', '缝',
        '缩', '缴', '网', '罗', '罚', '罢', '翘', '耻', '职', '联', '聪', '肃',
        '肠', '肤', '肾', '胆', '胜', '胶', '脉', '脑', '脚', '脱', '脸', '腾',
        '舰', '艰', '艳', '艺', '节', '苏', '苹', '茎', '荐', '荡', '荣', '药',
        '莲', '获', '莹', '萝', '营', '萧', '蓝', '虏', '虑', '虚', '虫', '虽',
        '虾', '蚀', '蚁', '蚂', '蚕', '蛮', '蜡', '蝇', '补', '衬', '袜', '装',
        '裤', '见', '观', '规', '视', '览', '觉', '触', '誉', '计', '订', '认',
    ];

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return array<string, string>
     */
    private function loadLocale(string $app, string $locale): array
    {
        $path = $this->repoRoot()."/{$app}/src/locales/lang/{$locale}.json";
        $this->assertFileExists($path, "{$app} 缺少 {$locale}.json 词条");

        $flat = [];
        $walk = static function (array $node, string $prefix) use (&$walk, &$flat): void {
            foreach ($node as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                if (is_array($value)) {
                    $walk($value, $path);
                    continue;
                }
                $flat[$path] = (string) $value;
            }
        };
        $walk(json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), '');

        return $flat;
    }

    public function test_all_apps_ship_all_locales(): void
    {
        foreach (self::APPS as $app) {
            foreach (self::LOCALES as $locale) {
                $this->assertFileExists(
                    $this->repoRoot()."/{$app}/src/locales/lang/{$locale}.json",
                    "{$app} 缺少 {$locale} 词条文件（i18n.md 第一节要求简体/台湾繁体/香港繁体/英文）"
                );
            }
        }
    }

    public function test_locale_key_sets_are_identical(): void
    {
        foreach (self::APPS as $app) {
            $base = $this->loadLocale($app, 'zh_CN');

            foreach (['zh_TW', 'zh_HK', 'en_US'] as $locale) {
                $other = $this->loadLocale($app, $locale);

                $missing = array_diff(array_keys($base), array_keys($other));
                $extra = array_diff(array_keys($other), array_keys($base));

                $this->assertSame(
                    [],
                    array_values($missing),
                    "{$app}/{$locale}.json 缺少 zh_CN 已有的词条"
                );
                $this->assertSame(
                    [],
                    array_values($extra),
                    "{$app}/{$locale}.json 存在 zh_CN 没有的词条（三份必须同步增删）"
                );
                // 只比对键集合，不要求书写顺序一致：顺序对运行没有影响，
                // 而三份文件由不同工具产出（人工补词 / opencc 生成），
                // 强行对齐顺序只会制造无意义的 diff。
                $expected = array_keys($base);
                $actual = array_keys($other);
                sort($expected);
                sort($actual);
                $this->assertSame($expected, $actual, "{$app}/{$locale}.json 与 zh_CN 的词条集合不一致");
            }
        }
    }

    public function test_no_translation_is_left_empty(): void
    {
        foreach (self::APPS as $app) {
            foreach (self::LOCALES as $locale) {
                foreach ($this->loadLocale($app, $locale) as $key => $value) {
                    $this->assertNotSame('', trim($value), "{$app}/{$locale}.json 的 {$key} 为空");
                }
            }
        }
    }

    public function test_traditional_chinese_has_no_simplified_residue(): void
    {
        $needles = array_values(array_unique(preg_split('//u', implode('', self::SIMPLIFIED_ONLY), -1, PREG_SPLIT_NO_EMPTY)));

        foreach (self::APPS as $app) {
          foreach (['zh_TW', 'zh_HK'] as $variant) {
            foreach ($this->loadLocale($app, $variant) as $key => $value) {
                foreach ($needles as $char) {
                    $this->assertStringNotContainsString(
                        $char,
                        $value,
                        "{$app}/{$variant}.json 的 {$key} 仍含简体「{$char}」，请重跑 backend/scripts/i18n/build-zh-trad.mjs"
                    );
                }
            }
          }
        }
    }

    public function test_supported_locales_cover_all_four_languages(): void
    {
        foreach (self::APPS as $app) {
            $source = (string) file_get_contents($this->repoRoot()."/{$app}/src/locales/index.ts");

            $this->assertMatchesRegularExpression(
                '/supportedLocales\s*=\s*\[[^\]]*[\'"]zh_CN[\'"][^\]]*[\'"]zh_TW[\'"][^\]]*[\'"]zh_HK[\'"][^\]]*[\'"]en_US[\'"]/',
                $source,
                "{$app} 的 supportedLocales 未同时声明四种语言（i18n.md 第一节）"
            );

            $this->assertMatchesRegularExpression(
                '/(?:tdesignLocaleMap|elementLocaleMap)\s*(?::[^=]+)?=\s*\{[^}]*zh_CN[^}]*zh_TW[^}]*zh_HK[^}]*en_US/s',
                $source,
                "{$app} 的组件库语言映射未覆盖全部 locale，组件内置文案不会跟随切换"
            );
        }
    }

    public function test_switcher_exists_in_each_app(): void
    {
        $switchers = [
            'frontend-admin-v3' => 'src/layouts/setting.vue',
            'frontend-user-v4-console' => 'src/layouts/setting.vue',
            'frontend-user-v3-www' => 'src/layout/WebsiteLayout.vue',
        ];

        foreach ($switchers as $app => $relative) {
            $path = $this->repoRoot()."/{$app}/{$relative}";
            $this->assertFileExists($path);
            $this->assertStringContainsString(
                'languageList',
                (string) file_get_contents($path),
                "{$app} 的切换器未渲染 languageList（i18n.md 第七节）"
            );
            $this->assertStringContainsString(
                'changeLocale',
                (string) file_get_contents($path),
                "{$app} 的切换器未绑定 changeLocale"
            );
        }
    }

    public function test_backend_has_traditional_chinese_validation(): void
    {
        // 界面是四种语言，后端校验消息必须同样提供台湾/香港两版繁体，
        // 否则 zh_HK 用户看到的是台湾用词（或反过来）。
        $expectations = [
            'zh_TW' => ['請填寫', '已被使用', '必須是有效的電子郵件地址'],
            'zh_HK' => ['請填寫', '已被使用', '必須是有效的電郵地址'],
        ];

        foreach ($expectations as $locale => $needles) {
            $path = $this->repoRoot()."/backend/lang/{$locale}/validation.php";
            $this->assertFileExists($path, "后端缺少 {$locale} 校验消息包");

            $content = (string) file_get_contents($path);
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $content, "{$locale}/validation.php 缺少「{$needle}」");
            }
        }
    }
public function test_referenced_keys_exist_in_locales(): void
    {
        // 代码里 {{ $t('a.b') }} / t('a.b') 引用的 key 必须能在 zh_CN 找到。
        // 只比对三份词条彼此一致是不够的：三份同时缺同一个 key 时依然「一致」。
        $pattern = '/(?<![A-Za-z0-9_.])(?:\$t|t)\(\s*[\'"]([A-Za-z0-9_.]+)[\'"]\s*\)/';

        foreach (self::APPS as $app) {
            $available = array_flip(array_keys($this->loadLocale($app, 'zh_CN')));
            $missing = [];

            foreach ($this->sourceFiles($app) as $file) {
                if (! preg_match_all($pattern, (string) file_get_contents($file), $hits)) {
                    continue;
                }
                foreach (array_unique($hits[1]) as $key) {
                    if (! isset($available[$key])) {
                        $missing[$key][] = basename($file);
                    }
                }
            }

            $sample = implode(', ', array_slice(array_keys($missing), 0, 5));
            $this->assertSame(
                [],
                array_keys($missing),
                sprintf(
                    '%s 有 %d 个 t() 引用的 key 不在 zh_CN 词条中，例如：%s。请补齐词条后重跑 backend/scripts/i18n/build-zh-trad.mjs',
                    $app,
                    count($missing),
                    $sample
                )
            );
        }
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(string $app): array
    {
        $base = $this->repoRoot()."/{$app}/src";
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $name = $file->getFilename();
            if (preg_match('/\.(vue|ts|js)$/', $name) === 1 && ! str_contains($file->getPathname(), '/locales/')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
public function test_templates_have_no_hardcoded_chinese(): void
    {
        // 模板层的文案必须全部走 $t()。这是本项目唯一能自动发现「漏翻译页面」的手段：
        // 新增页面若直接写中文，这里会立刻失败。
        //
        // 检测口径与后端排查工具保持一致：只查 <template> 段内的纯文本节点，
        // 以及常见文案属性；<script> 段的字符串刻意不查——那里混着匹配用关键词常量
        // 与协议字段，翻译它们会让商品规格识别和接口调用失效（见 i18n.md 第三节）。
        $attrs = [
            'title', 'placeholder', 'alt', 'aria-label', 'label',
            'content', 'description', 'empty-text', 'confirm', 'cancel',
        ];

        foreach (self::APPS as $app) {
            $hits = [];

            foreach ($this->vueTemplates($app) as $file) {
                $src = (string) file_get_contents($file);
                $start = strpos($src, '<template>');
                $end = strpos($src, '</template>');
                if ($start === false || $end === false || $end <= $start) {
                    continue;
                }
                $tpl = substr($src, $start, $end - $start);

                if (preg_match_all('/>([^<>{}]*[\x{4e00}-\x{9fff}][^<>{}]*)</u', $tpl, $m) === 1) {
                    foreach ($m[1] as $text) {
                        if (str_contains($text, "\n")) {
                            continue;
                        }
                        if (trim($text) !== '') {
                            $hits[] = basename($file).': >'.trim($text).'<';
                        }
                    }
                }

                foreach ($attrs as $attr) {
                    $pattern = '/(?<![:\w-])'.$attr.'\s*=\s*"[^"{}<>]*[\x{4e00}-\x{9fff}][^"{}<>]*"/u';
                    if (preg_match_all($pattern, $tpl, $m) > 0) {
                        foreach ($m[0] as $raw) {
                            $hits[] = basename($file).': '.$raw;
                        }
                    }
                }
            }

            $this->assertSame(
                [],
                array_slice($hits, 0, 10),
                sprintf(
                    "%s 模板层仍有 %d 处硬编码中文未走 i18n，例如：%s",
                    $app,
                    count($hits),
                    implode(' | ', array_slice($hits, 0, 3))
                )
            );
        }
    }

    /**
     * @return list<string>
     */
    private function vueTemplates(string $app): array
    {
        $base = $this->repoRoot()."/{$app}/src";
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (str_ends_with($file->getFilename(), '.vue')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}