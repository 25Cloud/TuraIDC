import type { SelectValue } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, reactive, ref } from 'vue';

import clientApi from '@/api/client';
import { t } from '@/locales';
import type { ConsoleSelectOption, SecurityGroupRecord, SecurityRuleRecord } from '@/types/client';

import { resolveErrorMessage } from './useConsoleCore';

export interface UseConsoleSecurityOptions {
  serviceId: { value: number };
}

export function useConsoleSecurity(options: UseConsoleSecurityOptions) {
  const { serviceId } = options;

  const securityState = reactive({
    loading: false,
    /** 是否已经拿到过一份有效数据：区分「首次加载遮罩」与「后台刷新保留内容」 */
    loaded: false,
    submitting: false,
    rulesLoading: false,
    /** 规则子面板同理：加载失败不能和「该安全组没有规则」共用一个空白视图 */
    rulesLoaded: false,
    rulesError: '',
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

  /**
   * 切换实例时复位安全组与规则的全部状态。
   *
   * 不复位的话新实例会先渲染上一个实例的分组列表，看起来像「这台机器有别人的安全组」。
   */
  function resetSecurityState() {
    securityState.loaded = false;
    securityState.error = '';
    securityState.rulesLoaded = false;
    securityState.rulesError = '';
    securityState.groups = [];
    securityState.rules = [];
    activeSecurityGroupId.value = 0;
  }

  function resolveActionMessage(payload: { message?: string } | null | undefined, fallback: string) {
    return String(payload?.message || '').trim() || fallback;
  }

  /**
   * @param fresh true 表示用户主动刷新（绕过上游缓存）
   *
   * 这里刻意不接 silent：安全组列表的失败已经由面板内的错误态 + 重试按钮呈现，
   * 自动选中的安全组也不再弹「规则已加载」提示，弹窗只会打断当前操作。
   */
  async function loadSecurityGroups(fresh = false) {
    securityState.loading = true;
    securityState.error = '';
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
      securityState.loaded = true;
      const viewableGroups = securityState.groups.filter((item) => item.can_view !== false);
      const current = viewableGroups.find((item) => Number(item.id || 0) === activeSecurityGroupId.value);
      const active = current || viewableGroups.find((item) => item.is_applied) || viewableGroups[0];
      if (active?.id) {
        await selectSecurityGroup(active, true);
      } else {
        activeSecurityGroupId.value = 0;
        securityState.rules = [];
        securityState.rulesLoaded = false;
      }
    } catch (error: unknown) {
      securityState.error = resolveErrorMessage(error, t('serviceConsole.errors.securityLoadFailed'));
    } finally {
      securityState.loading = false;
    }
  }

  async function loadSecurityGroupRules(groupId: number | string, silent = false) {
    securityState.rulesLoading = true;
    securityState.rulesError = '';
    try {
      const res = await clientApi.serviceSecurityGroupRules(serviceId.value, groupId);
      securityState.rules = Array.isArray(res.data?.list) ? res.data.list : [];
      securityState.rulesLoaded = true;
    } catch (error: unknown) {
      securityState.rules = [];
      securityState.rulesLoaded = false;
      securityState.rulesError = resolveErrorMessage(error, t('serviceConsole.errors.securityRulesLoadFailed'));
      if (!silent) MessagePlugin.error(securityState.rulesError);
    } finally {
      securityState.rulesLoading = false;
    }
  }

  async function selectSecurityGroup(group: SecurityGroupRecord, silent = false) {
    const groupId = Number(group.id || 0);
    if (group.can_view === false) {
      activeSecurityGroupId.value = 0;
      securityState.rules = [];
      securityState.rulesLoaded = false;
      return;
    }

    // 换了安全组就重新盖回遮罩：上一组的规则不能拿来冒充当前组的数据
    if (activeSecurityGroupId.value !== groupId) {
      securityState.rulesLoaded = false;
      securityState.rulesError = '';
    }

    activeSecurityGroupId.value = groupId;
    if (!groupId) {
      securityState.rules = [];
      securityState.rulesLoaded = false;
      return;
    }
    await loadSecurityGroupRules(groupId, silent);
    if (!silent) MessagePlugin.success(t('serviceConsole.security.rulesLoaded'));
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
      MessagePlugin.warning(t('serviceConsole.security.groupNameRequired'));
      return;
    }
    securityState.submitting = true;
    try {
      const res = await clientApi.createSecurityGroup(serviceId.value, {
        name: groupForm.name.trim(),
        description: groupForm.description.trim(),
      });
      groupVisible.value = false;
      MessagePlugin.success(resolveActionMessage(res.data, t('serviceConsole.security.groupCreateSuccess')));
      await loadSecurityGroups();
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, t('serviceConsole.security.groupCreateFailed')));
    } finally {
      securityState.submitting = false;
    }
  }

  async function applySecurityGroup(group: SecurityGroupRecord) {
    if (!group.id) return;
    securityState.submitting = true;
    try {
      const res = await clientApi.applySecurityGroup(serviceId.value, group.id);
      MessagePlugin.success(resolveActionMessage(res.data, t('serviceConsole.security.groupApplied')));
      await loadSecurityGroups();
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, t('serviceConsole.security.groupApplyFailed')));
    } finally {
      securityState.submitting = false;
    }
  }

  async function deleteSecurityGroup(group: SecurityGroupRecord) {
    if (!group.id) return;
    const dialog = DialogPlugin.confirm({
      header: t('serviceConsole.security.deleteGroupHeader'),
      body: t('serviceConsole.security.deleteGroupBody', { name: group.name || group.id }),
      theme: 'warning',
      confirmBtn: t('common.confirmDelete'),
      cancelBtn: t('common.cancel'),
      onConfirm: async () => {
        dialog.setConfirmLoading(true);
        securityState.submitting = true;
        try {
          const res = await clientApi.deleteSecurityGroup(serviceId.value, group.id);
          MessagePlugin.success(resolveActionMessage(res.data, t('serviceConsole.security.groupDeleted')));
          await loadSecurityGroups();
          dialog.hide();
        } catch (error: unknown) {
          MessagePlugin.error(resolveErrorMessage(error, t('serviceConsole.security.groupDeleteFailed')));
        } finally {
          securityState.submitting = false;
          dialog.setConfirmLoading(false);
        }
      },
    });
  }

  async function submitSecurityRule() {
    if (!activeSecurityGroupId.value) {
      MessagePlugin.warning(t('serviceConsole.security.selectGroupFirst'));
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
      MessagePlugin.warning(t('serviceConsole.security.ruleRequired'));
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
      MessagePlugin.success(resolveActionMessage(res.data, t('serviceConsole.security.ruleCreateSuccess')));
      await loadSecurityGroupRules(activeSecurityGroupId.value);
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, t('serviceConsole.security.ruleCreateFailed')));
    } finally {
      securityState.submitting = false;
    }
  }

  async function deleteSecurityRule(rule: SecurityRuleRecord) {
    if (!rule.id || !activeSecurityGroupId.value) return;
    const dialog = DialogPlugin.confirm({
      header: t('serviceConsole.security.deleteRuleHeader'),
      body: t('serviceConsole.security.deleteRuleBody'),
      theme: 'warning',
      confirmBtn: t('common.confirmDelete'),
      cancelBtn: t('common.cancel'),
      onConfirm: async () => {
        dialog.setConfirmLoading(true);
        securityState.submitting = true;
        try {
          const res = await clientApi.deleteSecurityRule(serviceId.value, activeSecurityGroupId.value, rule.id);
          MessagePlugin.success(resolveActionMessage(res.data, t('serviceConsole.security.ruleDeleted')));
          await loadSecurityGroupRules(activeSecurityGroupId.value);
          dialog.hide();
        } catch (error: unknown) {
          MessagePlugin.error(resolveErrorMessage(error, t('serviceConsole.security.ruleDeleteFailed')));
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
    resetSecurityState,
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
