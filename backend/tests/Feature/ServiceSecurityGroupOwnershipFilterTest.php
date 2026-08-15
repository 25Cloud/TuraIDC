<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceNatService;
use App\Services\ClientServiceConsole\ServiceSecurityGroupService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\System\OperationLogService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ServiceSecurityGroupOwnershipFilterTest extends TestCase
{
    #[Test]
    public function it_only_returns_security_groups_that_belong_to_the_current_machine(): void
    {
        $service = new ServiceSecurityGroupService(
            $this->createMock(OperationLogService::class),
            $this->createMock(ServiceDetailService::class),
            $this->createMock(ServiceTransformService::class),
            $this->createMock(ServiceNatService::class),
        );

        $html = <<<'HTML'
<div class="table-responsive">
  <table>
    <tbody>
      <tr>
        <td>（当前）machine-current-group</td>
        <td>当前机器正在使用</td>
        <td>
          <button class="trview" data-id="101">查看</button>
          <button class="apply" data-id="101" disabled>已应用</button>
          <button class="deletegroup" data-id="101">删除</button>
        </td>
      </tr>
      <tr>
        <td>other-machine-group</td>
        <td>不是当前机器的候选安全组</td>
        <td>
          <button class="apply" data-id="102">应用</button>
          <button class="deletegroup" data-id="102">删除</button>
        </td>
      </tr>
    </tbody>
  </table>
</div>
HTML;

        $groups = $this->invokePrivateMethod($service, 'extractSecurityGroupRows', [
            $html,
            ['ids' => [], 'names' => ['machine-current-group']],
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame(101, $groups[0]['id']);
        $this->assertSame('machine-current-group', $groups[0]['name']);
        $this->assertTrue($groups[0]['is_applied']);
    }

    #[Test]
    public function it_filters_out_other_machine_groups_even_when_both_rows_are_viewable(): void
    {
        $service = new ServiceSecurityGroupService(
            $this->createMock(OperationLogService::class),
            $this->createMock(ServiceDetailService::class),
            $this->createMock(ServiceTransformService::class),
            $this->createMock(ServiceNatService::class),
        );

        $html = <<<'HTML'
<div class="table-responsive">
  <table>
    <tbody>
      <tr>
        <td>1</td>
        <td>当前机器安全组</td>
        <td>
          <button class="trview" data-id="976">查看</button>
          <button class="apply" data-id="976">应用</button>
          <button class="deletegroup" data-id="976">删除</button>
        </td>
      </tr>
      <tr>
        <td>123</td>
        <td>别人的安全组</td>
        <td>
          <button class="trview" data-id="1001">查看</button>
          <button class="apply" data-id="1001">应用</button>
          <button class="deletegroup" data-id="1001">删除</button>
        </td>
      </tr>
    </tbody>
  </table>
</div>
HTML;

        $groups = $this->invokePrivateMethod($service, 'extractSecurityGroupRows', [
            $html,
            ['ids' => [976], 'names' => []],
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame(976, $groups[0]['id']);
        $this->assertSame('1', $groups[0]['name']);
        $this->assertFalse($groups[0]['is_applied']);
    }

    #[Test]
    public function it_collects_security_group_bindings_by_upstream_host_id(): void
    {
        $service = new ServiceSecurityGroupService(
            $this->createMock(OperationLogService::class),
            $this->createMock(ServiceDetailService::class),
            $this->createMock(ServiceTransformService::class),
            $this->createMock(ServiceNatService::class),
        );

        $bindings = $this->invokePrivateMethod($service, 'collectOwnedSecurityGroupBindingsFromLogs', [[
            [
                'action' => 'service.console.security_group.create',
                'detail' => ['host_id' => 110231, 'group_name' => '19132'],
            ],
            [
                'action' => 'service.console.security_group.apply',
                'detail' => ['host_id' => 110231, 'group_id' => 1337, 'group_name' => '19132'],
            ],
            [
                'action' => 'service.console.security_group.apply',
                'detail' => ['host_id' => 220462, 'group_id' => 1151, 'group_name' => '1'],
            ],
            [
                'action' => 'service.console.security_group.delete',
                'detail' => ['host_id' => 220462, 'group_id' => 1151, 'group_name' => '1'],
            ],
        ], 110231]);

        $this->assertSame(['ids' => [1337], 'names' => ['19132']], $bindings);
    }

    #[Test]
    public function it_rejects_viewing_security_group_rules_when_group_is_not_bound_to_current_host(): void
    {
        $service = new ServiceSecurityGroupService(
            $this->createMock(OperationLogService::class),
            $this->createMock(ServiceDetailService::class),
            $this->createMock(ServiceTransformService::class),
            $this->createMock(ServiceNatService::class),
        );

        $allowed = $this->invokePrivateMethod($service, 'isSecurityGroupBoundToBindings', [
            ['ids' => [976], 'names' => ['1']],
            1001,
        ]);

        $this->assertFalse($allowed);
    }

    #[Test]
    public function it_allows_viewing_security_group_rules_when_group_is_bound_to_current_host(): void
    {
        $service = new ServiceSecurityGroupService(
            $this->createMock(OperationLogService::class),
            $this->createMock(ServiceDetailService::class),
            $this->createMock(ServiceTransformService::class),
            $this->createMock(ServiceNatService::class),
        );

        $allowed = $this->invokePrivateMethod($service, 'isSecurityGroupBoundToBindings', [
            ['ids' => [1001, 976], 'names' => ['123', '1']],
            1001,
        ]);

        $this->assertTrue($allowed);
    }

    #[Test]
    public function it_allows_viewing_security_group_rules_when_group_name_is_bound_but_group_id_is_new(): void
    {
        $service = new ServiceSecurityGroupService(
            $this->createMock(OperationLogService::class),
            $this->createMock(ServiceDetailService::class),
            $this->createMock(ServiceTransformService::class),
            $this->createMock(ServiceNatService::class),
        );

        $allowed = $this->invokePrivateMethod($service, 'isSecurityGroupBoundToBindings', [
            ['ids' => [1001, 976], 'names' => ['123', '1', '222']],
            1007,
            [
                ['id' => 1007, 'name' => '222'],
                ['id' => 1001, 'name' => '123'],
                ['id' => 976, 'name' => '1'],
            ],
        ]);

        $this->assertTrue($allowed);
    }

    #[Test]
    public function it_rejects_hidden_security_group_ids_for_write_operations(): void
    {
        $service = new class($this->createMock(OperationLogService::class), $this->createMock(ServiceDetailService::class), $this->createMock(ServiceTransformService::class), $this->createMock(ServiceNatService::class)) extends ServiceSecurityGroupService
        {
            public function resolveSecurityGroupContext(Service $service, bool $fresh = false): array
            {
                return [
                    'groups' => [
                        ['id' => 976, 'name' => '1'],
                    ],
                    'raw_groups' => [
                        ['id' => 976, 'name' => '1'],
                        ['id' => 1001, 'name' => '123'],
                    ],
                ];
            }
        };

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('安全组不存在或不允许当前服务操作');

        $this->invokePrivateMethod($service, 'assertSecurityGroupVisibleToCurrentHost', [new Service, 1001]);
    }

    #[Test]
    public function it_allows_visible_security_group_ids_for_write_operations(): void
    {
        $service = new class($this->createMock(OperationLogService::class), $this->createMock(ServiceDetailService::class), $this->createMock(ServiceTransformService::class), $this->createMock(ServiceNatService::class)) extends ServiceSecurityGroupService
        {
            public function resolveSecurityGroupContext(Service $service, bool $fresh = false): array
            {
                return [
                    'groups' => [
                        ['id' => 976, 'name' => '1'],
                    ],
                    'raw_groups' => [
                        ['id' => 976, 'name' => '1'],
                        ['id' => 1001, 'name' => '123'],
                    ],
                ];
            }
        };

        $context = $this->invokePrivateMethod($service, 'assertSecurityGroupVisibleToCurrentHost', [new Service, 976]);

        $this->assertSame([['id' => 976, 'name' => '1']], $context['groups']);
    }

    private function invokePrivateMethod(object $target, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $arguments);
    }
}
