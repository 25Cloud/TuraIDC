<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 国际化护栏：三语言词条一致性回归。
 *
 * 覆盖 docs/develop/i18n.md 的约定：
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
        'frontend-user-v3-www'];

    /**
     * 简体独有字：这些字在繁体中一定写成别的形。
     * 出现即说明 zh_TW 没重新生成（详见 i18n.md 第五节）。
     *
     * @var list<string>
     */
    private const SIMPLIFIED_ONLY = [
        '们', '条', '决', '这', '国', '说', '问', '题', '实', '现', '数', '网', '络',
        '务', '缓', '认', '户', '软', '导', '邮', '册', '机', '构',
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
        '裤', '见', '观', '规', '视', '览', '觉', '触', '誉', '计', '订', '认'];

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
        // 四语言清单现在收敛在 shared/locale 一处，各端从它导出。
        // 护栏要查的仍是「四语言都在、且没有任何一端私自改小集合」，
        // 只是真源从每端各写一遍字面量变成了单一常量。
        $shared = (string) file_get_contents($this->repoRoot().'/shared/locale/index.ts');
        $this->assertMatchesRegularExpression(
            '/SUPPORTED_LOCALES\s*=\s*\[[^\]]*[\'"]zh_CN[\'"][^\]]*[\'"]zh_TW[\'"][^\]]*[\'"]zh_HK[\'"][^\]]*[\'"]en_US[\'"]/',
            $shared,
            'shared/locale 的 SUPPORTED_LOCALES 未同时声明四种语言（i18n.md 第一节）'
        );

        foreach (self::APPS as $app) {
            $source = (string) file_get_contents($this->repoRoot()."/{$app}/src/locales/index.ts");

            $this->assertMatchesRegularExpression(
                '/supportedLocales\s*=\s*SUPPORTED_LOCALES/',
                $source,
                "{$app} 的 supportedLocales 必须从 shared/locale 的 SUPPORTED_LOCALES 导出，"
                    .'各端自己写一份字面量迟早会漂移'
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
            'frontend-user-v3-www' => 'src/layout/WebsiteLayout.vue'];

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
            'zh_HK' => ['請填寫', '已被使用', '必須是有效的電郵地址']];

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
    }    /**
     * 模板层硬编码中文的存量基线（app → 相对 src 的路径 → 处数）。
     *
     * 这是**债务棘轮**，不是豁免名单：
     *   - 清单外的文件出现中文 → 失败（新代码必须走 i18n）
     *   - 清单内文件的处数**超过**登记值 → 失败（存量只减不增）
     *   - 处数变少 → 通过；请顺手把这里的数字改小，让进度出现在 diff 里
     *
     * 为什么需要它：这道守卫此前因两处缺陷从未真正执行——`preg_match_all(...) === 1`
     * 把「匹配条数」当成布尔（真实页面几十处命中时条件恒为假），以及命中后
     * 「含换行就整条跳过」（Vue 模板里文本节点几乎总带缩进换行，真命中全被放过）。
     * 另外它只扫每个文件**第一个** `<template>` 段，大型 SFC 从第二段起完全在盲区里。
     * 三处修好后一次性暴露 482 处存量，逐条清理不现实，故先冻结存量、堵死新增，再分批还。
     */
    private const TEMPLATE_CHINESE_BASELINE = [
        'frontend-admin-v3' => [
            'pages/products/edit.vue' => 90,
            'pages/tickets/detail/index.vue' => 37,
            'pages/content/edit.vue' => 34,
            'pages/notifications/index.vue' => 29,
            'pages/content/index.vue' => 18,
            'pages/notifications/email-template-detail/index.vue' => 16,
            'pages/member-levels/index.vue' => 15,
            'pages/referral/index.vue' => 12,
            'pages/services/detail/console/tabs/AreaTab.vue' => 4,
            'pages/services/detail/index.vue' => 4,
            'components/record-detail-page/index.vue' => 2,
            'pages/products/specs/index.vue' => 2,
        ], // 12 个文件 / 263 处
        'frontend-user-v4-console' => [
            'pages/client/invoice-detail/index.vue' => 36,
            'pages/client/service-console/components/tabs/OverviewTab.vue' => 22,
            'pages/client/service-console/components/cdn/tabs/CdnOverviewTab.vue' => 21,
            'pages/client/tickets/index.vue' => 16,
            'pages/client/api-keys/index.vue' => 15,
            'pages/client/payment-detail/index.vue' => 15,
            'pages/client/dashboard/index.vue' => 14,
            'pages/client-auth/login.vue' => 9,
            'pages/client/service-console/components/ConsoleHeader.vue' => 7,
            'pages/client/recharge/index.vue' => 5,
            'pages/client/service-console/components/tabs/AreaTab.vue' => 4,
            'pages/client-auth/forgot-password.vue' => 3,
            'pages/client/content-list/ContentListPage.vue' => 3,
            'pages/client/service-console/components/tabs/MonitorTab.vue' => 3,
            'components/auth/AuthShell.vue' => 2,
            'pages/client/orders/index.vue' => 2,
            'layouts/components/Header.vue' => 1,
            'layouts/components/Notice.vue' => 1,
            'pages/client/content-detail/ContentDetailPage.vue' => 1,
        ], // 19 个文件 / 180 处
        'frontend-user-v3-www' => [
            'layout/WebsiteLayout.vue' => 35,
            'views/website/content/ContentListPage.vue' => 2,
            'views/website/content/ContentDetailPage.vue' => 1,
            'views/website/Home/components/HomeNewsSection.vue' => 1,
        ], // 4 个文件 / 39 处
    ];

    /** 值为文案的属性：值里带中文即为硬编码 */
    private const TEMPLATE_TEXT_ATTRS = [
        'title', 'placeholder', 'alt', 'aria-label', 'label',
        'content', 'description', 'empty-text', 'confirm', 'cancel',
    ];

    /**
     * 模板层的文案必须全部走 \$t()。这是本项目唯一能自动发现「漏翻译页面」的手段。
     *
     * 只查 <template> 段内的纯文本节点与常见文案属性；<script> 段的字符串刻意不查——
     * 那里混着匹配用关键词常量与协议字段，翻译它们会让商品规格识别和接口调用失效
     * （见 i18n.md 第三节）。
     *
     * 判定按文件粒度对照 TEMPLATE_CHINESE_BASELINE，而不是要求全仓一次性清零。
     */
    public function test_templates_have_no_hardcoded_chinese(): void
    {
        foreach (self::APPS as $app) {
            $counts = $this->templateChineseCounts($app);
            $baseline = self::TEMPLATE_CHINESE_BASELINE[$app] ?? [];

            $newFiles = [];
            foreach ($counts as $file => $count) {
                if (! array_key_exists($file, $baseline)) {
                    $newFiles[] = $file.'（'.$count.' 处）';
                }
            }

            $grown = [];
            foreach ($counts as $file => $count) {
                if (array_key_exists($file, $baseline) && $count > $baseline[$file]) {
                    $grown[] = $file.'（'.$baseline[$file].' → '.$count.'）';
                }
            }

            $this->assertSame(
                [],
                $newFiles,
                sprintf(
                    '%s 有 %d 个文件新增了模板层硬编码中文：%s。新代码请直接用 $t()，不要加进基线（i18n.md 第一节）',
                    $app,
                    count($newFiles),
                    implode(' | ', $newFiles)
                )
            );

            $this->assertSame(
                [],
                $grown,
                sprintf(
                    '%s 有 %d 个文件的硬编码中文超出基线：%s。存量只减不增，新增文案请走 $t()',
                    $app,
                    count($grown),
                    implode(' | ', $grown)
                )
            );
        }
    }

    /**
     * 统计某个 app 每个 .vue 的模板层硬编码中文处数（只返回 > 0 的文件）。
     *
     * @return array<string, int> 相对该 app 的 src 的路径 => 处数
     */
    private function templateChineseCounts(string $app): array
    {
        $base = $this->repoRoot()."/{$app}/src";
        $counts = [];

        foreach ($this->vueTemplates($app) as $file) {
            $tpl = $this->rootTemplateBlock((string) file_get_contents($file));
            if ($tpl === '') {
                continue;
            }

            $count = 0;

            // 纯文本节点按行拆开逐行判断：Vue 模板里文本节点几乎总是带缩进换行，
            // 「含换行就整条跳过」会把绝大多数真实命中放过去。
            if (preg_match_all('/>([^<>{}]*[\x{4e00}-\x{9fff}][^<>{}]*)</u', $tpl, $m) > 0) {
                foreach ($m[1] as $text) {
                    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
                        if (trim($line) !== '') {
                            $count += 1;
                        }
                    }
                }
            }

            foreach (self::TEMPLATE_TEXT_ATTRS as $attr) {
                $pattern = '/(?<![:\w-])'.$attr.'\s*=\s*"[^"{}<>]*[\x{4e00}-\x{9fff}][^"{}<>]*"/u';
                $count += (int) preg_match_all($pattern, $tpl, $m);
            }

            if ($count > 0) {
                $counts[str_replace('\\', '/', substr($file, strlen($base) + 1))] = $count;
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * 取 SFC 的根 <template> 块（栈平衡配对，含标签）。
     *
     * 必须用栈，不能用「开标签 → 其后第一个闭标签」的线性配对：\`<template #slot>\` 可以嵌套，
     * 线性配对会在内层闭合处提前截断，之后的正文整片落在扫描范围外。取根块
     * （第一个 <template> 到使深度归零的那个 </template>）天然包含全部嵌套内容，
     * 且每段只算一次，不会重复计数。
     *
     * 返回值**含标签**：纯文本节点的判据是 \`>文本<\`，若只返回标签之间的内容，
     * \`<template #title>等级列表</template>\` 就只剩「等级列表」，前后没有 > 与 <，永远匹配不上。
     */
    private function rootTemplateBlock(string $src): string
    {
        if (preg_match_all('/<template(?:\s[^>]*)?>|<\/template>/', $src, $m, PREG_OFFSET_CAPTURE) === 0) {
            return '';
        }

        $depth = 0;
        $start = -1;

        foreach ($m[0] as $token) {
            [$text, $offset] = $token;

            if (str_starts_with($text, '</')) {
                if ($depth === 0) {
                    continue;
                }

                $depth -= 1;
                if ($depth === 0) {
                    return substr($src, $start, $offset + strlen($text) - $start);
                }

                continue;
            }

            if ($depth === 0) {
                $start = $offset;
            }

            $depth += 1;
        }

        return '';
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