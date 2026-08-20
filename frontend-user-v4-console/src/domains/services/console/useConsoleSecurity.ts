import type { SelectValue } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, reactive, ref } from 'vue';

import clientApi from '@/api/client';
import type { ConsoleSelectOption, SecurityGroupRecord, SecurityRuleRecord } from '@/types/client';

import { resolveErrorMessage } from './useConsoleCore';

export interface UseConsoleSecurityOptions {
  serviceId: { value: number };
}

export function useConsoleSecurity(options: UseConsoleSecurityOptions) {
  const { serviceId } = options;

  const securityState = reactive({
    loading: false,
    submitting: false,
    rulesLoading: false,
    supported: true,
    canCreate: true,
    message: '',
    error: '',
    directions: [] as ConsoleSelectOption[],
    protocols: [] as ConsoleSelectOption[],
    groups: [] as SecurityGroupRecord[],
    rules: [] as SecurityRuleRecord[],
  });

  const activeSecurityGroupId = ref(0);
  const groupVisible = ref(false);
  const groupForm = reactive({ name: '', description: '' });
  const ruleVisible = ref(false);
  const ruleForm = reactive({ direction: '', protocol: '', port: '', ip: '', description: '' });

  /** 已知协议的默认端口映射（小写）。值为 null 表示该协议无端口（如 ICMP）。 */
  const PROTOCOL_DEFAULT_PORT: Record<string, string | null> = {
    all: '1-65535',
    icmp: null,
    icmpv6: null,
    ssh: '22',
    telnet: '23',
    smtp: '25',
    dns: '53',
    dhcp: '67-68',
    http: '80',
    pop3: '110',
    imap: '143',
    https: '443',
    rdp: '3389',
    mysql: '3306',
    redis: '6379',
    postgres: '5432',
  };

  /** 解析当前选中协议的默认端口（兼容 全部/全部 TCP/UDP 这种组合标签） */
  function resolveProtocolDefaultPort(): string | null {
    const raw = String(ruleForm.protocol || '').toLowerCase();
    const label = (
      securityState.protocols.find((p) => String(p.value).toLowerCase() === raw)?.label || ''
    ).toLowerCase();

    // 精确匹配 PROTOCOL_DEFAULT_PORT
    if (Object.hasOwn(PROTOCOL_DEFAULT_PORT, raw)) {
      return PROTOCOL_DEFAULT_PORT[raw];
    }

    // 兼容标签中含 all/全部 的组合（全部、全部 TCP、全部 UDP、TCP ALL 等）→ 端口 1-65535
    if (raw.includes('all') || label.includes('全部') || label === 'all') {
      return '1-65535';
    }

    // 兼容标签中含 icmp/ICMP 的组合
    if (raw.includes('icmp') || label.includes('icmp')) {
      return null;
    }

    // 常规协议（TCP/UDP）由用户手动填写端口
    return undefined;
  }

  /** 端口输入是否禁用（协议为固定/无端口类型时） */
  const isPortDisabled = computed(() => {
    const port = resolveProtocolDefaultPort();
    return port !== undefined;
  });

  /** 旧字段保留兼容：是否所有端口 */
  const isAllPortProtocol = computed(() => resolveProtocolDefaultPort() === '1-65535');

  /** 协议变化时自动填充端口 */
  function onProtocolChange(val: SelectValue) {
    ruleForm.protocol = String(val);
    const defaultPort = resolveProtocolDefaultPort();
    if (defaultPort === undefined) {
      // 普通协议：若之前是自动填充值则清空，让用户输入
      if (ruleForm.port === '1-65535') ruleForm.port = '';
    } else if (defaultPort === null) {
      // ICMP 等无端口协议：留空提交
      ruleForm.port = '';
    } else {
      ruleForm.port = defaultPort;
    }
  }

  const activeSecurityGroup = computed(
    () => securityState.groups.find((item) => Number(item.id || 0) === activeSecurityGroupId.value) || null,
  );

  function resolveActionMessage(payload: { message?: string } | null | undefined, fallback: string) {
    return String(payload?.message || '').trim() || fallback;
  }

  async function loadSecurityGroups(fresh = false) {
    securityState.loading = true;
    try {
      const res = await clientApi.serviceSecurityGroups(serviceId.value, fresh ? { fresh: true } : undefined);
      const payload = res.data || {};
      securityState.supported = payload.supported !== false;
      securityState.canCreate = payload.can_create !== false;
      securityState.message = String(payload.message || '');
      securityState.error = String(payload.error || '');
      securityState.directions = Array.isArray(payload.directions) ? payload.directions : [];
      securityState.protocols = Array.isArray(payload.protocols) ? payload.protocols : [];
      securityState.groups = Array.isArray(payload.groups) ? payload.groups : [];
      const viewableGroups = securityState.groups.filter((item) => item.can_view !== false);
      const current = viewableGroups.find((item) => Number(item.id || 0) === activeSecurityGroupId.value);
      const active = current || viewableGroups.find((item) => item.is_applied) || viewableGroups[0];
      if (active?.id) {
        await selectSecurityGroup(active, true);
      } else {
        activeSecurityGroupId.value = 0;
        securityState.rules = [];
      }
    } catch (error: unknown) {
      securityState.error = resolveErrorMessage(error, '加载安全组失败');
    } finally {
      securityState.loading = false;
    }
  }

  async function loadSecurityGroupRules(groupId: number | string) {
    securityState.rulesLoading = true;
    try {
      const res = await clientApi.serviceSecurityGroupRules(serviceId.value, groupId);
      securityState.rules = Array.isArray(res.data?.list) ? res.data.list : [];
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, '加载安全组规则失败'));
    } finally {
      securityState.rulesLoading = false;
    }
  }

  async function selectSecurityGroup(group: SecurityGroupRecord, silent = false) {
    const groupId = Number(group.id || 0);
    if (group.can_view === false) {
      activeSecurityGroupId.value = 0;
      securityState.rules = [];
      return;
    }

    activeSecurityGroupId.value = groupId;
    if (!groupId) {
      securityState.rules = [];
      return;
    }
    await loadSecurityGroupRules(groupId);
    if (!silent) MessagePlugin.success('安全组规则已加载');
  }

  function openSecurityGroupDialog() {
    groupForm.name = '';
    groupForm.description = '';
    groupVisible.value = true;
  }

  function openSecurityRuleDialog() {
    ruleForm.direction = String(securityState.directions[0]?.value || '');
    ruleForm.protocol = String(securityState.protocols[0]?.value || '');
    const defaultPort = resolveProtocolDefaultPort();
    ruleForm.port = defaultPort ?? '';
    ruleForm.ip = '0.0.0.0/0';
    ruleForm.description = '';
    ruleVisible.value = true;
  }

  async function submitSecurityGroup() {
    if (!groupForm.name.trim()) {
      MessagePlugin.warning('请输入安全组名称');
      return;
    }
    securityState.submitting = true;
    try {
      const res = await clientApi.createSecurityGroup(serviceId.value, {
        name: groupForm.name.trim(),
        description: groupForm.description.trim(),
      });
      groupVisible.value = false;
      MessagePlugin.success(resolveActionMessage(res.data, '安全组创建成功'));
      await loadSecurityGroups();
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, '创建安全组失败'));
    } finally {
      securityState.submitting = false;
    }
  }

  async function applySecurityGroup(group: SecurityGroupRecord) {
    if (!group.id) return;
    securityState.submitting = true;
    try {
      const res = await clientApi.applySecurityGroup(serviceId.value, group.id);
      MessagePlugin.success(resolveActionMessage(res.data, '安全组已应用'));
      await loadSecurityGroups();
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, '应用安全组失败'));
    } finally {
      securityState.submitting = false;
    }
  }

  async function deleteSecurityGroup(group: SecurityGroupRecord) {
    if (!group.id) return;
    const dialog = DialogPlugin.confirm({
      header: '删除安全组',
      body: `确认删除安全组“${group.name || group.id}”吗？`,
      theme: 'warning',
      confirmBtn: '确认删除',
      cancelBtn: '取消',
      onConfirm: async () => {
        dialog.setConfirmLoading(true);
        securityState.submitting = true;
        try {
          const res = await clientApi.deleteSecurityGroup(serviceId.value, group.id);
          MessagePlugin.success(resolveActionMessage(res.data, '安全组已删除'));
          await loadSecurityGroups();
          dialog.hide();
        } catch (error: unknown) {
          MessagePlugin.error(resolveErrorMessage(error, '删除安全组失败'));
        } finally {
          securityState.submitting = false;
          dialog.setConfirmLoading(false);
        }
      },
    });
  }

  async function submitSecurityRule() {
    if (!activeSecurityGroupId.value) {
      MessagePlugin.warning('请先选择安全组');
      return;
    }
    const defaultPort = resolveProtocolDefaultPort();
    const isNoPortProtocol = defaultPort === null;
    if (
      !ruleForm.direction ||
      !ruleForm.protocol ||
      (!isNoPortProtocol && !ruleForm.port.trim()) ||
      !ruleForm.ip.trim()
    ) {
      MessagePlugin.warning('请填写完整的规则信息');
      return;
    }
    securityState.submitting = true;
    try {
      const portValue = isNoPortProtocol ? ruleForm.port.trim() || '1-65535' : ruleForm.port.trim();
      const res = await clientApi.createSecurityRule(serviceId.value, activeSecurityGroupId.value, {
        direction: ruleForm.direction,
        protocol: ruleForm.protocol,
        port: portValue,
        ip: ruleForm.ip.trim(),
        description: ruleForm.description.trim(),
      });
      ruleVisible.value = false;
      MessagePlugin.success(resolveActionMessage(res.data, '安全组规则创建成功'));
      await loadSecurityGroupRules(activeSecurityGroupId.value);
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, '创建安全组规则失败'));
    } finally {
      securityState.submitting = false;
    }
  }

  async function deleteSecurityRule(rule: SecurityRuleRecord) {
    if (!rule.id || !activeSecurityGroupId.value) return;
    const dialog = DialogPlugin.confirm({
      header: '删除规则',
      body: '确认删除该安全组规则吗？',
      theme: 'warning',
      confirmBtn: '确认删除',
      cancelBtn: '取消',
      onConfirm: async () => {
        dialog.setConfirmLoading(true);
        securityState.submitting = true;
        try {
          const res = await clientApi.deleteSecurityRule(serviceId.value, activeSecurityGroupId.value, rule.id);
          MessagePlugin.success(resolveActionMessage(res.data, '安全组规则已删除'));
          await loadSecurityGroupRules(activeSecurityGroupId.value);
          dialog.hide();
        } catch (error: unknown) {
          MessagePlugin.error(resolveErrorMessage(error, '删除安全组规则失败'));
        } finally {
          securityState.submitting = false;
          dialog.setConfirmLoading(false);
        }
      },
    });
  }

  return {
    securityState,
    activeSecurityGroupId,
    activeSecurityGroup,
    groupVisible,
    groupForm,
    ruleVisible,
    ruleForm,
    isPortDisabled,
    isAllPortProtocol,
    onProtocolChange,
    loadSecurityGroups,
    loadSecurityGroupRules,
    selectSecurityGroup,
    openSecurityGroupDialog,
    openSecurityRuleDialog,
    submitSecurityGroup,
    applySecurityGroup,
    deleteSecurityGroup,
    submitSecurityRule,
    deleteSecurityRule,
  };
}
