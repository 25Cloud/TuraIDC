<template>
  <div class="admin-roles-page">
    <t-card class="roles-card" :bordered="false">
      <div class="roles-filter">
        <t-input v-model="keyword" clearable placeholder="搜索角色编码/名称" @enter="loadRoles" @clear="loadRoles">
          <template #suffix-icon><search-icon /></template>
        </t-input>
        <t-button v-if="canManage" theme="primary" @click="openCreateDialog">
          <template #icon><add-icon /></template>
          新增角色
        </t-button>
      </div>

      <t-table
        v-if="!isMobile"
        row-key="id"
        :data="roles"
        :columns="columns"
        :loading="loading"
        hover
        table-layout="fixed"
      >
        <template #role="{ row }">
          <div class="role-cell">
            <strong>{{ row.label || row.name || '-' }}</strong>
            <span>{{ row.name || '-' }}</span>
            <t-tag v-if="row.is_builtin" theme="primary" variant="light" size="small">系统默认</t-tag>
          </div>
        </template>
        <template #permissions="{ row }">
          <div class="permission-summary">
            <t-tag variant="light">{{ permissionSummary(row) }}</t-tag>
            <t-tag v-if="riskPermissionCount(row) > 0" theme="danger" variant="light">
              高危 {{ riskPermissionCount(row) }}
            </t-tag>
          </div>
        </template>
        <template #adminCount="{ row }">{{ Number(row.admin_count || 0) }} 人</template>
        <template #updatedAt="{ row }">{{ row.updated_at || '-' }}</template>
        <template #actions="{ row }">
          <t-space size="small">
            <t-button theme="primary" variant="text" @click="openEditDialog(row)">{{
              canManage ? '权限' : '查看'
            }}</t-button>
            <t-button v-if="canManage" theme="default" variant="text" @click="handleCopy(row)">复制</t-button>
            <t-button v-if="canManage && canDeleteRole(row)" theme="danger" variant="text" @click="handleDelete(row)"
              >删除</t-button
            >
          </t-space>
        </template>
      </t-table>

      <div v-else class="roles-mobile-list">
        <t-loading :loading="loading" size="small">
          <div v-if="roles.length" class="roles-mobile-stack">
            <mobile-record-card
              v-for="row in roles"
              :key="row.id"
              :title="row.label || row.name || '-'"
              :subtitle="row.name"
              :eyebrow="`管理员 ${Number(row.admin_count || 0)} 人`"
              :rows="[
                { label: '权限', value: permissionSummary(row) },
                { label: '高危', value: riskPermissionCount(row) > 0 ? `${riskPermissionCount(row)} 项` : '无' },
                { label: '更新时间', value: row.updated_at || '-' },
              ]"
              :action-options="rolesMobileActions(row)"
              @action="(value) => handleRolesMobileAction(value, row)"
            />
          </div>
          <t-empty v-else />
        </t-loading>
      </div>
    </t-card>

    <t-dialog
      v-model:visible="dialogVisible"
      :header="form.id ? '编辑角色权限' : '新增角色'"
      width="960px"
      :confirm-btn="dialogConfirmBtn"
      :confirm-loading="saving"
      @confirm="submitForm"
    >
      <t-form ref="formRef" :data="form" :rules="rules" label-align="top">
        <div class="role-form-grid">
          <t-form-item label="角色编码" name="name">
            <t-input
              v-model="form.name"
              :disabled="!canManage || currentRoleLocked || isReadonlySuperRole"
              placeholder="例如：operator"
            />
          </t-form-item>
          <t-form-item label="角色名称" name="label">
            <t-input v-model="form.label" :disabled="!canManage || currentRoleLocked" placeholder="例如：运营人员" />
          </t-form-item>
        </div>

        <section class="permission-panel">
          <t-alert
            v-if="currentRoleLocked"
            theme="info"
            message="系统默认角色权限由后台固定维护，如需调整请复制后创建自定义角色。"
          />
          <div class="permission-panel__head">
            <div class="permission-panel__title">
              <strong>权限目录</strong>
              <span>已选 {{ selectedPermissionCount }} / {{ permissions.length }}</span>
            </div>
            <t-space size="small">
              <template v-if="canEditCurrentRole">
                <t-button
                  v-for="preset in rolePermissionPresets"
                  :key="preset.code"
                  variant="text"
                  :theme="preset.theme"
                  @click="applyRolePermissionPreset(preset)"
                >
                  {{ preset.label }}
                </t-button>
              </template>
              <t-button v-if="canEditCurrentRole" variant="text" @click="clearAllPermissions">清空全部</t-button>
            </t-space>
          </div>

          <div class="permission-toolbar">
            <t-input v-model="permissionKeyword" clearable placeholder="搜索权限名称/权限码/模块" />
            <t-checkbox v-model="showDangerousOnly">只看高危</t-checkbox>
            <t-space v-if="canEditCurrentRole" size="small">
              <t-button size="small" variant="base" @click="selectVisiblePermissions">选中当前结果</t-button>
              <t-button size="small" variant="base" @click="clearVisiblePermissions">清空当前结果</t-button>
            </t-space>
          </div>

          <t-checkbox-group v-model="form.permissions" class="permission-groups">
            <div v-for="group in permissionGroups" :key="group.key" class="permission-group">
              <div class="permission-group__head">
                <div>
                  <h4>{{ group.label }}</h4>
                  <span>{{ group.parentLabel }} · {{ group.selectedCount }} / {{ group.items.length }}</span>
                </div>
                <t-space v-if="canEditCurrentRole" size="small">
                  <t-button size="small" variant="text" theme="primary" @click="selectGroupPermissions(group)"
                    >全选</t-button
                  >
                  <t-button size="small" variant="text" @click="clearGroupPermissions(group)">清空</t-button>
                </t-space>
              </div>
              <div class="permission-grid">
                <t-checkbox
                  v-for="item in group.items"
                  :key="item.key"
                  :value="item.key"
                  :disabled="!canEditCurrentRole || (hasAllPermissionSelected && item.key !== AdminPermissions.ALL)"
                >
                  <span class="permission-name">
                    {{ item.name || item.key }}
                    <t-tag v-if="item.action_label" variant="light" size="small">{{ item.action_label }}</t-tag>
                    <t-tag v-if="item.is_dangerous" theme="danger" variant="light" size="small">高危</t-tag>
                    <t-tag v-else-if="item.risk_level === 'medium'" theme="warning" variant="light" size="small"
                      >敏感</t-tag
                    >
                  </span>
                  <em>{{ item.key }}</em>
                </t-checkbox>
              </div>
            </div>
            <div v-if="permissionGroups.length === 0" class="permission-empty">暂无匹配权限</div>
          </t-checkbox-group>
        </section>
      </t-form>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { AddIcon, SearchIcon } from 'tdesign-icons-vue-next';
import type { FormInstanceFunctions, FormRule, PrimaryTableCol, TableRowData } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';

import type { PermissionItem, RolePayload, RoleRecord } from '@/api/admin-roles';
import { adminRoleApi } from '@/api/admin-roles';
import MobileRecordCard from '@/components/mobile-record-card/index.vue';
import {
  ADMIN_DEFAULT_PERMISSION_CODES,
  AdminPermissions,
  BUILTIN_ROLE_LABELS,
  hasPermissionInList,
  VISITOR_PERMISSION_CODES,
} from '@/constants/permissions';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { useUserStore } from '@/store';
import { required } from '@/utils/formRules';
import { errorMessage } from '@/utils/userMessage';

defineOptions({
  name: 'AdminRoles',
});

interface RoleForm {
  id: number | string | null;
  name: string;
  label: string;
  permissions: string[];
}

interface PermissionGroup {
  key: string;
  label: string;
  parentLabel: string;
  items: PermissionItem[];
  selectedCount: number;
}

interface RolePermissionPreset {
  code: 'super_admin' | 'admin' | 'visitor';
  label: string;
  permissions: string[];
  theme: 'primary' | 'default' | 'danger';
}

const userStore = useUserStore();
const loading = ref(false);
const saving = ref(false);
const dialogVisible = ref(false);
const formRef = ref<FormInstanceFunctions>();
const keyword = ref('');
const permissionKeyword = ref('');
const showDangerousOnly = ref(false);
const roles = ref<RoleRecord[]>([]);
const permissions = ref<PermissionItem[]>([]);
const form = reactive<RoleForm>(createDefaultForm());
const rolePermissionPresets: RolePermissionPreset[] = [
  {
    code: 'super_admin',
    label: BUILTIN_ROLE_LABELS.super_admin,
    permissions: [AdminPermissions.ALL],
    theme: 'danger',
  },
  {
    code: 'admin',
    label: BUILTIN_ROLE_LABELS.admin,
    permissions: [...ADMIN_DEFAULT_PERMISSION_CODES],
    theme: 'primary',
  },
  {
    code: 'visitor',
    label: BUILTIN_ROLE_LABELS.visitor,
    permissions: [...VISITOR_PERMISSION_CODES],
    theme: 'default',
  },
] as const;

const canManage = computed(() => hasPermission(AdminPermissions.ROLE_MANAGE));
const isMobile = useMediaQuery('(max-width: 768px)');
const hasAllPermissionSelected = computed(() => form.permissions.includes(AdminPermissions.ALL));
const isReadonlySuperRole = computed(() => hasAllPermissionSelected.value);
const currentRole = computed(() => roles.value.find((item) => String(item.id) === String(form.id)) || null);
const currentRoleLocked = computed(() => Boolean(currentRole.value?.is_locked || currentRole.value?.is_builtin));
const canEditCurrentRole = computed(() => canManage.value && !currentRoleLocked.value);
const selectedPermissionCount = computed(() =>
  hasAllPermissionSelected.value ? permissions.value.length : form.permissions.length,
);
const dialogConfirmBtn = computed(() => (canEditCurrentRole.value ? { content: '保存', theme: 'primary' } : null));
const permissionMap = computed(() => new Map(permissions.value.map((item) => [item.key, item])));
const filteredPermissions = computed(() => {
  const keywordValue = permissionKeyword.value.trim().toLowerCase();

  return permissions.value.filter((item) => {
    if (showDangerousOnly.value && !item.is_dangerous) return false;
    if (!keywordValue) return true;

    return [item.key, item.name, item.module, item.module_label, item.group, item.group_label, item.action_label]
      .filter(Boolean)
      .some((value) => String(value).toLowerCase().includes(keywordValue));
  });
});
const permissionGroups = computed<PermissionGroup[]>(() => {
  const grouped = new Map<string, PermissionItem[]>();
  filteredPermissions.value.forEach((item) => {
    const group = item.group || item.module || 'system';
    grouped.set(group, [...(grouped.get(group) || []), item]);
  });

  return Array.from(grouped.entries()).map(([key, items]) => ({
    key,
    label: items[0]?.group_label || items[0]?.module_label || groupFallbackLabel(key),
    parentLabel: items[0]?.module_label || '系统',
    items,
    selectedCount: items.filter((item) => hasPermissionSelected(item.key)).length,
  }));
});

const rules: Record<string, FormRule[]> = {
  name: [required('请输入角色编码')],
  label: [required('请输入角色名称')],
};
const columns: PrimaryTableCol<TableRowData>[] = [
  { title: 'ID', colKey: 'id', width: 80 },
  { title: '角色', colKey: 'role', minWidth: 220 },
  { title: '权限', colKey: 'permissions', width: 180 },
  { title: '员工数', colKey: 'adminCount', width: 110 },
  { title: '更新时间', colKey: 'updatedAt', width: 180 },
  { title: '操作', colKey: 'actions', fixed: 'right', width: 180 },
];

watch(
  () => [...form.permissions],
  (values) => {
    if (values.includes(AdminPermissions.ALL) && values.length > 1) {
      form.permissions = [AdminPermissions.ALL];
    }
  },
);

onMounted(async () => {
  await Promise.all([loadPermissions(), loadRoles()]);
});

function createDefaultForm(): RoleForm {
  return {
    id: null,
    name: '',
    label: '',
    permissions: [],
  };
}

async function loadPermissions() {
  try {
    const response = await adminRoleApi.permissions();
    permissions.value = response.list || [];
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载权限目录失败'));
  }
}

async function loadRoles() {
  loading.value = true;
  try {
    const response = await adminRoleApi.list({ keyword: keyword.value });
    roles.value = response.list || [];
  } catch (error) {
    roles.value = [];
    MessagePlugin.error(errorMessage(error, '加载角色列表失败'));
  } finally {
    loading.value = false;
  }
}

function openCreateDialog() {
  permissionKeyword.value = '';
  showDangerousOnly.value = false;
  Object.assign(form, createDefaultForm());
  dialogVisible.value = true;
}

function rolesMobileActions(row: RoleRecord) {
  const actions = [{ content: canManage.value ? '权限' : '查看', value: 'edit' }];
  if (canManage.value) {
    actions.push({ content: '复制', value: 'copy' });
    if (canDeleteRole(row)) {
      actions.push({ content: '删除', value: 'delete' });
    }
  }
  return actions;
}

function handleRolesMobileAction(value: unknown, row: RoleRecord) {
  if (value === 'edit') openEditDialog(row);
  else if (value === 'copy') handleCopy(row);
  else if (value === 'delete') handleDelete(row);
}

function openEditDialog(row: RoleRecord) {
  const selectedPermissions = row.stored_permissions?.length ? row.stored_permissions : row.permissions || [];

  permissionKeyword.value = '';
  showDangerousOnly.value = false;
  Object.assign(form, {
    id: row.id,
    name: String(row.name || ''),
    label: String(row.label || row.name || ''),
    permissions: normalizePermissionSelection(selectedPermissions),
  });
  dialogVisible.value = true;
}

async function submitForm() {
  if (!canManage.value) {
    MessagePlugin.warning('当前账号无角色管理权限');
    return;
  }
  if (currentRoleLocked.value) {
    MessagePlugin.warning('系统默认角色不可直接编辑，请复制后自定义');
    return;
  }

  const result = await formRef.value?.validate?.();
  if (result !== true) return;

  const payload = buildPayload();
  if (payload.permissions.includes(AdminPermissions.ALL) && !(await confirmAllPermission())) {
    return;
  }

  saving.value = true;
  try {
    if (form.id) {
      await adminRoleApi.update(form.id, payload);
      MessagePlugin.success('角色已更新');
    } else {
      await adminRoleApi.create(payload);
      MessagePlugin.success('角色已创建');
    }
    dialogVisible.value = false;
    await loadRoles();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存角色失败'));
  } finally {
    saving.value = false;
  }
}

function handleCopy(row: RoleRecord) {
  const dialog = DialogPlugin.confirm({
    header: '复制角色',
    body: `确认复制角色「${row.label || row.name || row.id}」？`,
    confirmBtn: { content: '复制', theme: 'primary' },
    cancelBtn: '取消',
    onConfirm: async () => {
      try {
        await adminRoleApi.copy(row.id);
        MessagePlugin.success('角色已复制');
        await loadRoles();
        dialog.destroy();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '复制角色失败'));
      }
    },
  });
}

function handleDelete(row: RoleRecord) {
  if (!canDeleteRole(row)) {
    MessagePlugin.warning('系统默认角色不可删除');
    return;
  }

  const dialog = DialogPlugin.confirm({
    header: '删除角色',
    body: `确认删除角色「${row.label || row.name || row.id}」？已分配员工的角色无法删除。`,
    confirmBtn: { content: '删除', theme: 'danger' },
    cancelBtn: '取消',
    onConfirm: async () => {
      try {
        await adminRoleApi.delete(row.id);
        MessagePlugin.success('角色已删除');
        await loadRoles();
        dialog.destroy();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '删除角色失败'));
      }
    },
  });
}

function applyRolePermissionPreset(preset: RolePermissionPreset) {
  if (!canEditCurrentRole.value) return;
  form.permissions = normalizePermissionSelection([...preset.permissions]);
}

function selectGroupPermissions(group: PermissionGroup) {
  mergePermissions(group.items.map((item) => item.key));
}

function clearGroupPermissions(group: PermissionGroup) {
  clearPermissions(group.items.map((item) => item.key));
}

function selectVisiblePermissions() {
  mergePermissions(filteredPermissions.value.map((item) => item.key));
}

function clearVisiblePermissions() {
  clearPermissions(filteredPermissions.value.map((item) => item.key));
}

function clearAllPermissions() {
  form.permissions = [];
}

function mergePermissions(keys: string[]) {
  if (!canEditCurrentRole.value) return;
  form.permissions = normalizePermissionSelection([...form.permissions, ...keys]);
}

function clearPermissions(keys: string[]) {
  if (!canEditCurrentRole.value) return;
  const keySet = new Set(keys);
  form.permissions = form.permissions.filter((key) => !keySet.has(key));
}

function canDeleteRole(row: RoleRecord) {
  return row.can_delete !== false && !row.is_locked && !row.is_builtin;
}

function buildPayload(): RolePayload {
  return {
    name: form.name.trim(),
    label: form.label.trim(),
    permissions: normalizePermissionSelection(form.permissions),
  };
}

function normalizePermissionSelection(values: string[]) {
  const normalized = Array.from(new Set(values.filter(Boolean)));
  if (normalized.includes(AdminPermissions.ALL)) return [AdminPermissions.ALL];
  return normalized;
}

function permissionSummary(row: RoleRecord) {
  const resolved = row.permissions || [];
  if (resolved.includes(AdminPermissions.ALL)) return '全部权限';
  return `${resolved.length} 项权限`;
}

function riskPermissionCount(row: RoleRecord) {
  const resolved = row.permissions || [];
  if (resolved.includes(AdminPermissions.ALL)) return 1;

  return resolved.filter((permission) => permissionMap.value.get(permission)?.is_dangerous).length;
}

function hasPermissionSelected(permission: string) {
  return hasAllPermissionSelected.value || form.permissions.includes(permission);
}

function groupFallbackLabel(group: string) {
  return (
    {
      system_root: '超级权限',
      organization_staff: '员工账号',
      organization_role: '角色授权',
      organization_permission: '权限目录',
      dashboard_workbench: '工作台',
      customer_profile: '客户资料',
      customer_verification: '实名审核',
      finance_order: '订单管理',
      finance_invoice: '账单管理',
      finance_funds: '资金操作',
      finance_report: '财务报表',
      support_ticket: '工单支持',
      product_catalog: '商品配置',
      content_ops: '内容运营',
      marketing_growth: '推广会员',
      system_config: '系统设置',
      system_audit: '日志审计',
    }[group] || group
  );
}

function confirmAllPermission() {
  return new Promise<boolean>((resolve) => {
    let settled = false;
    const settle = (value: boolean) => {
      if (settled) return;
      settled = true;
      resolve(value);
    };
    const dialog = DialogPlugin.confirm({
      header: '确认授予全部权限',
      body: '全部权限将允许该角色访问并操作后台所有功能。',
      confirmBtn: { content: '确认授予', theme: 'danger' },
      cancelBtn: '取消',
      onConfirm: () => {
        settle(true);
        dialog.destroy();
      },
      onCancel: () => settle(false),
      onClose: () => settle(false),
    });
  });
}

function hasPermission(permission: string) {
  const userPermissions = userStore.userInfo?.permissions || [];
  return hasPermissionInList(userPermissions, permission);
}
</script>
