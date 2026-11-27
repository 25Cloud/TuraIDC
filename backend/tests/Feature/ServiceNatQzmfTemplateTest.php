<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ClientServiceConsole\ServiceNatService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * qzmf 系（魔方财务）NAT 面板模板的解析与请求体。
 *
 * 背景：上游同一功能有两套面板。旧版是「弹窗 + select-protocol + table-responsive + tbody」，
 * qzmf 系是「页内表单 + table.qzmf-table（无 tbody）+ delete-nat」。只认旧结构时，
 * 上游明明有规则也会被解析成空列表，客户看到的是「暂无端口转发规则」。
 */
class ServiceNatQzmfTemplateTest extends TestCase
{
    /** 真实页面去掉样式与脚本后的结构骨架 */
    private function qzmfHtml(): string
    {
        return <<<'HTML'
<table class="qzmf-table">
  <tr>
    <th>名称</th>
    <th>外网端口</th>
    <th>内网端口</th>
    <th>协议</th>
    <th>操作</th>
  </tr>
    <tr>
    <td>远程桌面</td>
    <td>abc123.nat.example.com:57869</td>
    <td>3389</td>
    <td>all</td>
    <td><div class="qzmf-del delete-nat" data-id="123367" data-dport="3389" data-sport="57869">删除</div></td>
  </tr>
    <tr>
    <td>ssh</td>
    <td>abc123.nat.example.com:49865</td>
    <td>22</td>
    <td>all</td>
    <td><div class="qzmf-del delete-nat" data-id="123366" data-dport="22" data-sport="49865">删除</div></td>
  </tr>
  </table>
<div class="qzmf-create" id="qzmf-show-nat">创建端口转发</div>
<div class="qzmf-form" id="qzmf-nat-form">
  <input type="text" id="qzmf-nat-name">
  <input type="number" id="qzmf-nat-port">
  <button type="button" class="qzmf-submit" id="qzmf-nat-submit">确定</button>
</div>
HTML;
    }

    /** 旧模板：弹窗按钮 + select-protocol + table-responsive/tbody + deleteNAT */
    private function legacyHtml(): string
    {
        return <<<'HTML'
<button type="button" data-target="#natAclAddModal">新增</button>
<select name="select-protocol">
  <option value="tcp">TCP</option>
  <option value="udp">UDP</option>
</select>
<div class="table-responsive">
  <table>
    <tbody>
      <tr>
        <td>默认</td>
        <td>1.2.3.4:8080</td>
        <td>80</td>
        <td>tcp</td>
        <td><span class="deleteNAT" data-id="99">删除</span></td>
      </tr>
    </tbody>
  </table>
</div>
HTML;
    }

    private function invoke(string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod(ServiceNatService::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(app(ServiceNatService::class), $args);
    }

    public function test_qzmf_page_parses_existing_rules(): void
    {
        $page = $this->invoke('parseNatAclPage', [$this->qzmfHtml(), 'https://upstream.example.com/provision/custom/1']);

        $this->assertSame('qzmf', $page['template']);
        $this->assertCount(2, $page['list'], '表头不能算一行，两条规则都要解析出来');

        $first = $page['list'][0];
        $this->assertSame('远程桌面', $first['name']);
        $this->assertSame('3389', $first['internal_port']);
        $this->assertSame('57869', $first['external_port']);
        $this->assertSame('all', $first['protocol']);
        $this->assertSame(123367, $first['id']);
        $this->assertTrue($first['can_delete']);
    }

    public function test_qzmf_page_reports_can_create_and_all_protocol(): void
    {
        $page = $this->invoke('parseNatAclPage', [$this->qzmfHtml(), 'https://upstream.example.com/provision/custom/1']);

        $this->assertTrue($page['can_create'], 'qzmf 用页内表单创建，没有弹窗按钮');
        $this->assertSame([['value' => 'all', 'label' => 'all']], $page['protocols']);
    }

    public function test_qzmf_create_payload_uses_upstream_field_names(): void
    {
        $payload = $this->invoke('buildNatCreatePayload', [
            ['template' => 'qzmf'],
            ['name' => 'web', 'ext_port' => '58000', 'int_port' => '80'],
            'all',
        ]);

        $this->assertSame([
            'func' => 'AddNatAcl',
            'name' => 'web',
            'interior_port' => '80',
            'sport' => '58000',
        ], $payload);
    }

    public function test_qzmf_delete_payload_carries_both_ports(): void
    {
        $page = $this->invoke('parseNatAclPage', [$this->qzmfHtml(), 'https://upstream.example.com/provision/custom/1']);

        $payload = $this->invoke('buildNatDeletePayload', [
            ['template' => 'qzmf', 'list' => $page['list']],
            123366,
        ]);

        $this->assertSame([
            'func' => 'DelNatAcl',
            'natid' => '123366',
            'dport' => '22',
            'sport' => '49865',
        ], $payload);
    }

    public function test_legacy_template_is_untouched(): void
    {
        $page = $this->invoke('parseNatAclPage', [$this->legacyHtml(), 'https://upstream.example.com/provision/custom/1']);

        $this->assertSame('legacy', $page['template']);
        $this->assertTrue($page['can_create']);
        $this->assertSame(['tcp', 'udp'], array_column($page['protocols'], 'value'));
        $this->assertCount(1, $page['list']);
        $this->assertSame(99, $page['list'][0]['id']);

        $create = $this->invoke('buildNatCreatePayload', [
            ['template' => 'legacy'],
            ['name' => 'web', 'ext_port' => '58000', 'int_port' => '80'],
            'tcp',
        ]);
        $this->assertSame('addNatAcl', $create['func']);
        $this->assertSame('tcp', $create['select-protocol']);

        $delete = $this->invoke('buildNatDeletePayload', [['template' => 'legacy'], 99]);
        $this->assertSame(['func' => 'delNatAcl', 'id' => '99'], $delete);
    }
}
