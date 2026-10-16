<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * 后端业务异常消息的四语言回归。
 *
 * 消息表以中文原文为 key，因此：
 *   - 四份词表的 key 必须完全一致（新增业务消息后重跑生成脚本）；
 *   - 每条都必须有英文翻译；
 *   - 繁体两版由 OpenCC 生成，关键用词要抽样核对；
 *   - BusinessException 构造时按当前 locale 取译文，取不到回落中文原文。
 *
 * 简体残留的全量扫描在 I18nLocaleConsistencyTest 里针对前端词条做，
 * 这里用抽样覆盖后端消息，两边不重复维护同一份字表。
 *
 * 详见 docs/references/engineering/i18n.md。
 */
class BackendMessageLocaleTest extends TestCase
{
    private const LOCALES = ['zh_CN', 'zh_TW', 'zh_HK', 'en_US'];

    private const SAMPLE = '商品分组不存在';

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function loadAll(): array
    {
        $out = [];
        foreach (self::LOCALES as $locale) {
            $file = $this->repoRoot()."/backend/lang/{$locale}/messages.php";
            $this->assertFileExists($file, "后端缺少 {$locale}/messages.php");

            $data = require $file;
            $this->assertIsArray($data);

            $map = [];
            foreach ($data as $key => $value) {
                $this->assertIsString($value);
                $map[(string) $key] = $value;
            }
            $out[$locale] = $map;
        }

        return $out;
    }

    public function test_all_locales_share_the_same_message_keys(): void
    {
        $all = $this->loadAll();
        $base = array_keys($all['zh_CN']);
        $this->assertNotEmpty($base, '消息表为空，说明扫描规则已失配');

        sort($base);
        foreach (self::LOCALES as $locale) {
            $keys = array_keys($all[$locale]);
            sort($keys);
            $this->assertSame(
                $base,
                $keys,
                "backend/lang/{$locale}/messages.php 的 key 与 zh_CN 不一致，请重跑 node backend/scripts/i18n/build-biz-messages.mjs"
            );
        }
    }

    public function test_every_message_has_an_english_translation(): void
    {
        $en = $this->loadAll()['en_US'];

        $missing = [];
        foreach ($en as $key => $value) {
            // 回落成中文原文说明这条还没翻译
            if (trim($value) === '' || $value === $key) {
                $missing[] = $key;
            }
        }

        $this->assertSame(
            [],
            array_slice($missing, 0, 10),
            sprintf('有 %d 条业务消息没有英文翻译，例如：%s', count($missing), implode(' | ', array_slice($missing, 0, 3)))
        );
    }

    public function test_traditional_locales_actually_converted(): void
    {
        $all = $this->loadAll();
        $cn = $all['zh_CN'];

        foreach (['zh_TW', 'zh_HK'] as $locale) {
            $same = 0;
            foreach ($cn as $key => $value) {
                $this->assertNotSame('', trim($all[$locale][$key]), "{$locale} 的「{$key}」是空的");
                if ($all[$locale][$key] === $value) {
                    $same += 1;
                }
            }

            // 简繁同形的词转换后不变是正常的，但不能一条都没转
            $this->assertLessThan(
                count($cn),
                $same,
                "{$locale} 与原文完全相同，说明转换脚本没生效"
            );
        }
    }

    /**
     * 关键用词抽样：这些正是 OpenCC 两版差异最大、或曾经出过错的地方。
     */
    public function test_key_wording_sampled_across_locales(): void
    {
        $all = $this->loadAll();

        // 只断言确实存在于消息表中的条目，避免词表变动导致测试脆裂
        $expectations = [
            'zh_TW' => ['默认' => '預設', '内存' => '記憶體', '邮箱' => '電子郵件', '注册' => '註冊'],
            'zh_HK' => ['默认' => '默認', '内存' => '記憶體', '邮箱' => '電郵', '注册' => '註冊'],
        ];

        foreach ($expectations as $locale => $pairs) {
            foreach ($pairs as $simplified => $expected) {
                $found = null;
                foreach ($all[$locale] as $key => $value) {
                    if (str_contains((string) $key, $simplified)) {
                        $found = $value;
                        break;
                    }
                }

                if ($found !== null) {
                    $this->assertStringContainsString(
                        $expected,
                        $found,
                        "{$locale} 中含「{$simplified}」的文案没有用上「{$expected}」"
                    );
                }
            }
        }
    }

    public function test_business_exception_translates_by_current_locale(): void
    {
        $expected = [
            'zh_CN' => self::SAMPLE,
            'zh_TW' => '商品分組不存在',
            'zh_HK' => '商品分組不存在',
            'en_US' => 'The product group does not exist',
        ];

        foreach ($expected as $locale => $message) {
            Lang::setLocale($locale);
            $this->assertSame(
                $message,
                (new BusinessException(self::SAMPLE, 42200, 422))->getMessage(),
                "locale={$locale} 翻译不正确"
            );
        }

        Lang::setLocale('zh_CN');
    }

    public function test_untranslated_message_falls_back_to_chinese_source(): void
    {
        Lang::setLocale('zh_TW');
        $this->assertSame(
            '这是一条没有翻译的业务消息',
            (new BusinessException('这是一条没有翻译的业务消息', 42200, 422))->getMessage()
        );

        Lang::setLocale('zh_CN');
    }

    public function test_non_chinese_messages_pass_through(): void
    {
        Lang::setLocale('en_US');
        $this->assertSame(
            'Plain english technical message',
            (new BusinessException('Plain english technical message', 50000, 500))->getMessage()
        );

        Lang::setLocale('zh_CN');
    }

    public function test_locale_header_negotiation(): void
    {
        $middleware = new SetLocale;

        $cases = [
            ['X-Locale' => 'zh_HK', 'Accept-Language' => 'zh-CN', 'expect' => 'zh_HK'],
            ['X-Locale' => 'en_US', 'Accept-Language' => 'zh-TW', 'expect' => 'en_US'],
            ['X-Locale' => '', 'Accept-Language' => 'zh-HK,zh;q=0.9,en;q=0.8', 'expect' => 'zh_HK'],
            ['X-Locale' => '', 'Accept-Language' => 'en-GB,en;q=0.9', 'expect' => 'en_US'],
            ['X-Locale' => '', 'Accept-Language' => 'zh-Hant-HK', 'expect' => 'zh_HK'],
            ['X-Locale' => '', 'Accept-Language' => 'fr-FR', 'expect' => 'zh_CN'],
            ['X-Locale' => '', 'Accept-Language' => '', 'expect' => 'zh_CN'],
            // 逐级前缀回退：只退到主语言时 'zh-hant-hk-mo' 会命中 'zh' 被判成简体，
            // 繁体用户会拿到简体中文的业务消息。前端 shared/locale/index.ts 同套逻辑。
            ['X-Locale' => '', 'Accept-Language' => 'zh-Hant-HK-MO', 'expect' => 'zh_HK'],
            ['X-Locale' => '', 'Accept-Language' => 'zh-Hant-TW', 'expect' => 'zh_TW'],
            ['X-Locale' => '', 'Accept-Language' => 'zh-Hans-CN-x-private', 'expect' => 'zh_CN'],
            ['X-Locale' => '', 'Accept-Language' => 'en-Latn-GB', 'expect' => 'en_US'],
            ['X-Locale' => 'zh_hk', 'Accept-Language' => '', 'expect' => 'zh_HK'],
            ['X-Locale' => 'zh-TW;q=0.9', 'Accept-Language' => '', 'expect' => 'zh_TW'],
            ['X-Locale' => 'ja', 'Accept-Language' => 'zh-HK', 'expect' => 'zh_HK'],
        ];

        foreach ($cases as $index => $case) {
            $request = Request::create('/api/v2/site/config', 'GET');
            $request->headers->set('X-Locale', $case['X-Locale']);
            $request->headers->set('Accept-Language', $case['Accept-Language']);

            $this->assertSame(
                $case['expect'],
                $this->resolveLocale($middleware, $request),
                "case #{$index} 协商结果不对"
            );
        }
    }

    /**
     * 通过反射取出中间件私有的 resolve，避免真的跑一遍中间件栈。
     */
    private function resolveLocale(SetLocale $middleware, Request $request): string
    {
        $method = new \ReflectionMethod(SetLocale::class, 'resolve');
        $method->setAccessible(true);

        return (string) $method->invoke($middleware, $request);
    }
}