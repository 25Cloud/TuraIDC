<template>
  <div class="admin-roles-page">
    <t-card class="roles-card" :bordered="false">
      <div class="roles-filter">
        <t-input
          v-model="keyword"
          clearable
          :placeholder="$t('roles.index.searchPlaceholder')"
          @enter="loadRoles"
          @clear="loadRoles"
        >
          <template #suffix-icon><search-icon /></template>
        </t-input>
        <t-button v-if="canManage" theme="primary" @click="openCreateDialog">
          <template #icon><add-icon /></template>
          {{ $t('roles.index.addRole') }}
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
            <t-tag v-if="row.is_builtin" theme="primary" variant="light" size="small">
              {{ $t('roles.index.systemDefault') }}
            </t-tag>
          </div>
        </template>
        <template #permissions="{ row }">
          <div class="permission-summary">
            <t-tag variant="light">{{ permissionSummary(row) }}</t-tag>
            <t-tag v-if="riskPermissionCount(row) > 0" theme="danger" variant="light">
              {{ $t('roles.index.dangerousCount', { count: riskPermissionCount(row) }) }}
            </t-tag>
          </div>
        </template>
        <template #adminCount="{ row }">{{
          $t('roles.index.adminCount', { count: Number(row.admin_count || 0) })
        }}</template>
        <template #updatedAt="{ row }">{{ row.updated_at || '-' }}</template>
        <template #actions="{ row }">
          <t-space size="small">
            <t-button theme="primary" variant="text" @click="openEditDialog(row)">{{
              canManage ? $t('roles.index.permissions') : $t('roles.index.view')
            }}</t-button>
            <t-button v-if="canManage" theme="default" variant="text" @click="handleCopy(row)">
              {{ $t('roles.index.copy') }}
            </t-button>
            <t-button v-if="canManage && canDeleteRole(row)" theme="danger" variant="text" @click="handleDelete(row)">{{
              $t('roles.index.delete')
            }}</t-button>
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
              :eyebrow="$t('roles.index.adminCount', { count: Number(row.admin_count || 0) })"
              :rows="[
                { label: $t('roles.index.permissions'), value: permissionSummary(row) },
                {
                  label: $t('roles.index.dangerous'),
                  value:
                    riskPermissionCount(row) > 0
                      ? $t('roles.index.itemCount', { count: riskPermissionCount(row) })
                      : $t('roles.index.none'),
                },
                { label: $t('roles.index.updatedAt'), value: row.updated_at || '-' },
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
      :header="form.id ? $t('roles.index.editPermissions') : $t('roles.index.addRoleTitle')"
      width="960px"
      :confirm-btn="dialogConfirmBtn"
      :confirm-loading="saving"
      @confirm="submitForm"
    >
      <t-form ref="formRef" :data="form" :rules="rules" label-align="top">
        <div class="role-form-grid">
          <t-form-item :label="$t('roles.index.roleCode')" name="name">
            <t-input
              v-model="form.name"
              :disabled="!canManage || currentRoleLocked || isReadonlySuperRole"
              :placeholder="$t('roles.index.roleCodePlaceholder')"
            />
          </t-form-item>
          <t-form-item :label="$t('roles.index.roleName')" name="label">
            <t-input
              v-model="form.label"
              :disabled="!canManage || currentRoleLocked"
              :placeholder="$t('roles.index.roleNamePlaceholder')"
            />
          </t-form-item>
        </div>

        <section class="permission-panel">
          <t-alert v-if="currentRoleLocked" theme="info" :message="$t('roles.index.builtinRoleNotice')" />
          <div class="permission-panel__head">
            <div class="permission-panel__title">
              <strong>{{ $t('roles.index.permissionCatalog') }}</strong>
              <span>
                {{
                  $t('roles.index.selectedCount', {
                    selected: selectedPermissionCount,
                    total: permissions.length,
                  })
                }}
              </span>
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
              <t-button v-if="canEditCurrentRole" variant="text" @click="clearAllPermissions">
                {{ $t('roles.index.clearAll') }}
              </t-button>
            </t-space>
          </div>

          <div class="permission-toolbar">
            <t-input
              v-model="permissionKeyword"
              clearable
              :placeholder="$t('roles.index.permissionSearchPlaceholder')"
            />
            <t-checkbox v-model="showDangerousOnly">{{ $t('roles.index.dangerousOnly') }}</t-checkbox>
            <t-space v-if="canEditCurrentRole" size="small">
              <t-button size="small" variant="base" @click="selectVisiblePermissions">
                {{ $t('roles.index.selectVisible') }}
              </t-button>
              <t-button size="small" variant="base" @click="clearVisiblePermissions">
                {{ $t('roles.index.clearVisible') }}
              </t-button>
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
                  <t-button size="small" variant="text" theme="primary" @click="selectGroupPermissions(group)">{{
                    $t('roles.index.selectAll')
                  }}</t-button>
                  <t-button size="small" variant="text" @click="clearGroupPermissions(group)">
                    {{ $t('roles.index.clear') }}
                  </t-button>
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
                    <t-tag v-if="item.is_dangerous" theme="danger" variant="light" size="small">
                      {{ $t('roles.index.dangerous') }}
                    </t-tag>
                    <t-tag v-else-if="item.risk_level === 'medium'" theme="warning" variant="light" size="small">{{
                      $t('roles.index.sensitive')
                    }}</t-tag>
                  </span>
                  <em>{{ item.key }}</em>
                </t-checkbox>
              </div>
            </div>
            <div v-if="permissionGroups.length === 0" class="permission-empty">
              {{ $t('roles.index.noMatchingPermission') }}
            </div>
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
import { t } from '@/locales';
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
const dialogConfirmBtn = computed(() =>
  canEditCurrentRole.value ? { content: t('roles.index.save'), theme: 'primary' as const } : null,
);
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
    parentLabel: items[0]?.module_label || t('roles.index.systemLabel'),
    items,
    selectedCount: items.filter((item) => hasPermissionSelected(item.key)).length,
  }));
});

const rules: Record<string, FormRule[]> = {
  name: [required(t('roles.index.codeRequired'))],
  label: [required(t('roles.index.nameRequired'))],
};
const columns: PrimaryTableCol<TableRowData>[] = [
  { title: 'ID', colKey: 'id', width: 80 },
  { title: t('roles.index.role'), colKey: 'role', minWidth: 220 },
  { title: t('roles.index.permissions'), colKey: 'permissions', width: 180 },
  { title: t('roles.index.staffCount'), colKey: 'adminCount', width: 110 },
  { title: t('roles.index.updatedAt'), colKey: 'updatedAt', width: 180 },
  { title: t('common.action'), colKey: 'actions', fixed: 'right', width: 180 },
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
    MessagePlugin.error(errorMessage(error, t('roles.index.loadPermissionCatalogFailed')));
  }
}

async function loadRoles() {
  loading.value = true;
  try {
    const response = await adminRoleApi.list({ keyword: keyword.value });
    roles.value = response.list || [];
  } catch (error) {
    roles.value = [];
    MessagePlugin.error(errorMessage(error, t('roles.index.loadRolesFailed')));
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
  const actions = [{ content: canManage.value ? t('roles.index.permission') : t('roles.index.view'), value: 'edit' }];
  if (canManage.value) {
    actions.push({ content: t('roles.index.copy'), value: 'copy' });
    if (canDeleteRole(row)) {
      actions.push({ content: t('roles.index.delete'), value: 'delete' });
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
    MessagePlugin.warning(t('roles.index.noPermission'));
    return;
  }
  if (currentRoleLocked.value) {
    MessagePlugin.warning(t('roles.index.systemDefaultNotEditable'));
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
      MessagePlugin.success(t('roles.index.updated'));
    } else {
      await adminRoleApi.create(payload);
      MessagePlugin.success(t('roles.index.created'));
    }
    dialogVisible.value = false;
    await loadRoles();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('roles.index.saveFailed')));
  } finally {
    saving.value = false;
  }
}

function handleCopy(row: RoleRecord) {
  const dialog = DialogPlugin.confirm({
    header: t('roles.index.copyTitle'),
    body: t('roles.index.copyConfirm', { name: row.label || row.name || row.id }),
    confirmBtn: { content: t('roles.index.copy'), theme: 'primary' },
    cancelBtn: t('common.cancel'),
    onConfirm: async () => {
      try {
        await adminRoleApi.copy(row.id);
        MessagePlugin.success(t('roles.index.copied'));
        await loadRoles();
        dialog.destroy();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, t('roles.index.copyFailed')));
      }
    },
  });
}

function handleDelete(row: RoleRecord) {
  if (!canDeleteRole(row)) {
    MessagePlugin.warning(t('roles.index.systemDefaultNotDeletable'));
    return;
  }

  const dialog = DialogPlugin.confirm({
    header: t('roles.index.deleteTitle'),
    body: t('roles.index.deleteConfirm', { name: row.label || row.name || row.id }),
    confirmBtn: { content: t('roles.index.delete'), theme: 'danger' },
    cancelBtn: t('common.cancel'),
    onConfirm: async () => {
      try {
        await adminRoleApi.delete(row.id);
        MessagePlugin.success(t('roles.index.deleted'));
        await loadRoles();
        dialog.destroy();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, t('roles.index.deleteFailed')));
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
  if (resolved.includes(AdminPermissions.ALL)) return t('roles.index.allPermissions');
  return t('roles.index.permissionCount', { count: resolved.length });
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
      system_root: t('roles.index.moduleSystemRoot'),
      organization_staff: t('roles.index.moduleOrganizationStaff'),
      organization_role: t('roles.index.moduleOrganizationRole'),
      organization_permission: t('roles.index.moduleOrganizationPermission'),
      dashboard_workbench: t('roles.index.moduleDashboardWorkbench'),
      customer_profile: t('roles.index.moduleCustomerProfile'),
      customer_verification: t('roles.index.moduleCustomerVerification'),
      finance_order: t('roles.index.moduleFinanceOrder'),
      finance_invoice: t('roles.index.moduleFinanceInvoice'),
      finance_funds: t('roles.index.moduleFinanceFunds'),
      finance_report: t('roles.index.moduleFinanceReport'),
      support_ticket: t('roles.index.moduleSupportTicket'),
      product_catalog: t('roles.index.moduleProductCatalog'),
      content_ops: t('roles.index.moduleContentOps'),
      marketing_growth: t('roles.index.moduleMarketingGrowth'),
      system_config: t('roles.index.moduleSystemConfig'),
      system_audit: t('roles.index.moduleSystemAudit'),
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
      header: t('roles.index.grantAllTitle'),
      body: t('roles.index.grantAllBody'),
      confirmBtn: { content: t('roles.index.confirmGrant'), theme: 'danger' },
      cancelBtn: t('common.cancel'),
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
