<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 校验文案里的中文字段名词典。
 *
 * 没定义 attributes 时，Laravel 会把 snake_case 字段名原样塞进提示语，
 * 中文界面里就会出现「first_product_group_id必须为整数」这类半中半英的文案。
 * 词典按「校验规则本身的中文提示」组织，规则提示语没覆盖到的字段全靠它兜底。
 */
class ValidationAttributeNameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 不依赖外部 .env，保证断言跑在中文 locale 上
        app()->setLocale('zh_CN');
    }

    public function test_top_level_field_name_is_rendered_in_chinese(): void
    {
        $message = $this->firstMessage(
            ['first_product_group_id' => 'not-an-int'],
            ['first_product_group_id' => ['integer']]
        );

        $this->assertStringNotContainsString(
            'first_product_group_id',
            $message,
            '提示语不得泄漏原始字段名'
        );
        $this->assertStringContainsString('一级分组', $message);
    }

    public function test_wildcard_and_nested_attribute_names_are_resolved(): void
    {
        $wildcard = $this->firstMessage(
            ['recipients' => ['not-an-email']],
            ['recipients.*' => ['email']]
        );

        $this->assertStringNotContainsString('recipients', $wildcard);
        $this->assertStringContainsString('收件人', $wildcard);

        $nested = $this->firstMessage(
            ['payload' => ['to' => 'not-an-email']],
            ['payload.to' => ['email']]
        );

        $this->assertStringNotContainsString('payload.to', $nested);
        $this->assertStringContainsString('收件邮箱', $nested);
    }

    /**
     * decimal 规则的 :decimal 由 Laravel 的 replaceDecimal 负责替换，必须保留占位符。
     * 换成写死「两位小数」的静态文案会在 decimal:0,4 之类的规则上给出错误提示。
     */
    public function test_decimal_rule_keeps_its_placeholder(): void
    {
        // decimal:2 是精确匹配，提示语会渲染成「2 位小数」，可以直接看出占位符是否被替换
        $message = $this->firstMessage(
            ['discount_value' => '1.2345'],
            ['discount_value' => ['decimal:2']]
        );

        $this->assertStringNotContainsString(':decimal', $message, ':decimal 未被替换说明占位符丢了');
        $this->assertStringContainsString('2', $message);
        $this->assertStringContainsString('折扣值', $message);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     */
    private function firstMessage(array $data, array $rules): string
    {
        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->fails(), '用例前提：该数据必须校验失败');

        // 通配符规则的错误键是 'recipients.0' 之类，这里统一取第一条消息
        return $validator->errors()->first() ?? '';
    }
}
