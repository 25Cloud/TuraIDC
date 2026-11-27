<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ClientServiceConsole\ServiceSecurityGroupService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * qzmf 系（魔方防火墙）安全组面板的解析。
 *
 * 上游 MC 的安全组模块 function=firewall，是单层防火墙规则表：
 *   - table.qzmf-table，无 table-responsive、无 tbody；7 列：方向/动作/协议/端口/IP/优先级/操作
 *   - select 用 id（qzmf-fw-direction / qzmf-fw-protocol），不是 name
 *   - 删除按钮 class 是 delete-fw + data-id
 *   - 没有 showSecurityRules：规则内嵌在页面 HTML 里
 * 只认旧模板时，这个面板会一个组都没有（「当前主机未开放安全组模块」）。
 */
class ServiceSecurityGroupQzmfTemplateTest extends TestCase
{
    /** 有两条规则的真实结构（域名/IP 已替换成示例值） */
    private function qzmfHtml(): string
    {
        return <<<'HTML'
<select id="qzmf-fw-direction">
  <option value="in">入站</option>
  <option value="out">出站</option>
</select>
<select id="qzmf-fw-protocol">
  <option value="ANY">ANY</option>
  <option value="TCP">TCP</option>
  <option value="UDP">UDP</option>
  <option value="ICMP">ICMP</option>
</select>
<table class="qzmf-table">
  <tr>
    <th>方向</th><th>动作</th><th>协议</th><th>端口</th><th>IP</th><th>优先级</th><th>操作</th>
  </tr>
  <tr>
    <td>入站</td>
    <td>允许</td>
    <td>TCP</td>
    <td>80,443</td>
    <td>ANY</td>
    <td>10</td>
    <td><div class="qzmf-del delete-fw" data-id="88123">删除</div></td>
  </tr>
  <tr>
    <td>出站</td>
    <td>拒绝</td>
    <td>UDP</td>
    <td>53</td>
    <td>10.0.0.0/8</td>
    <td>20</td>
    <td><div class="qzmf-del delete-fw" data-id="88124">删除</div></td>
  </tr>
  </table>
HTML;
    }

    /** 空态：单格 colspan 行，一个规则都不该解析出来 */
    private function qzmfEmptyHtml(): string
    {
        return <<<'HTML'
<table class="qzmf-table">
  <tr>
    <th>方向</th><th>动作</th><th>协议</th><th>端口</th><th>IP</th><th>优先级</th><th>操作</th>
  </tr>
    <tr><td colspan="7">暂无防火墙规则</td></tr>
  </table>
HTML;
    }

    private function invoke(string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod(ServiceSecurityGroupService::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(app(ServiceSecurityGroupService::class), $args);
    }

    public function test_qzmf_template_is_detected(): void
    {
        $this->assertSame('qzmf', $this->invoke('resolveSecurityTemplate', [$this->qzmfHtml()]));
        $this->assertSame('legacy', $this->invoke('resolveSecurityTemplate', ['<div class="table-responsive"><table><tbody></tbody></table></div>']));
    }

    public function test_qzmf_select_options_use_id_attribute(): void
    {
        $directions = $this->invoke('extractQzmfSelectOptions', [$this->qzmfHtml(), 'qzmf-fw-direction']);
        $protocols = $this->invoke('extractQzmfSelectOptions', [$this->qzmfHtml(), 'qzmf-fw-protocol']);

        $this->assertSame(['in', 'out'], array_column($directions, 'value'));
        $this->assertSame(['入站', '出站'], array_column($directions, 'label'));
        $this->assertSame(['ANY', 'TCP', 'UDP', 'ICMP'], array_column($protocols, 'value'));
    }

    public function test_qzmf_rules_are_parsed_with_labels_and_delete_id(): void
    {
        $rules = $this->invoke('extractQzmfSecurityRules', [$this->qzmfHtml()]);

        $this->assertCount(2, $rules, '表头不算规则');

        $first = $rules[0];
        $this->assertSame(88123, $first['id']);
        $this->assertSame('in', $first['direction']);
        $this->assertSame('入方向', $first['direction_label']);
        $this->assertSame('TCP', $first['protocol']);
        $this->assertSame('80,443', $first['port']);
        $this->assertSame('ANY', $first['ip']);
        $this->assertSame('accept', $first['action']);
        $this->assertSame('允许', $first['action_label']);
        $this->assertSame(10, $first['priority']);

        // drop 要归一成 deny，前端只认 accept/deny 两个标签
        $this->assertSame('deny', $rules[1]['action']);
        $this->assertSame('拒绝', $rules[1]['action_label']);
        $this->assertSame('out', $rules[1]['direction']);
    }

    public function test_qzmf_empty_state_row_is_not_a_rule(): void
    {
        $this->assertSame([], $this->invoke('extractQzmfSecurityRules', [$this->qzmfEmptyHtml()]));
    }

    public function test_qzmf_virtual_group_keeps_the_two_level_contract(): void
    {
        $group = $this->invoke('buildQzmfSecurityGroup', []);

        $this->assertSame(1, $group['id'], '哨兵组 id 固定为 1，与上游规则 id 不冲突');
        $this->assertTrue($group['can_add_rule']);
        $this->assertTrue($group['can_view']);
        $this->assertTrue($group['is_applied']);
        $this->assertFalse($group['can_delete'], '单层防火墙没有组可删');
    }
}
