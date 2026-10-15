<template>
  <div class="admin-staff-page">
    <t-card class="staff-card" :bordered="false">
      <div class="staff-filter">
        <t-input
          v-model="filters.keyword"
          clearable
          :placeholder="$t('admin.staff.searchPlaceholder')"
          @enter="handleSearch"
          @clear="handleSearch"
        >
          <template #suffix-icon><search-icon /></template>
        </t-input>
        <t-select v-model="filters.role_id" clearable :placeholder="$t('admin.staff.filterRole')" @change="handleSearch">
          <t-option v-for="role in roles" :key="role.id" :label="roleLabel(role)" :value="role.id" />
        </t-select>
        <t-select v-model="filters.status" clearable :placeholder="$t('admin.staff.filterStatus')" @change="handleSearch">
          <t-option :label="$t('admin.staff.enabled')" :value="1" />
          <t-option :label="$t('admin.staff.disabled')" :value="0" />
        </t-select>
        <t-button v-if="canManage" theme="primary" @click="openCreateDialog">
          <template #icon><user-add-icon /></template>
          {{ $t('admin.staff.addStaff') }}
        </t-button>
      </div>

      <div class="staff-summary">
        <span>{{ $t('admin.staff.totalStaff', { count: total }) }}</span>
        <span>{{ $t('admin.staff.pageInfo', { page, size: pageSize }) }}</span>
      </div>

      <t-table
        v-if="!isMobile"
        row-key="id"
        :data="list"
        :columns="columns"
        :loading="loading"
        :pagination="pagination"
        hover
        table-layout="fixed"
        @page-change="handlePageChange"
      >
        <template #account="{ row }">
          <div class="staff-account">
            <strong>{{ fieldValue(row.username) }}</strong>
            <span>{{ fieldValue(row.email) }}</span>
          </div>
        </template>
        <template #name="{ row }">{{ fieldValue(row.nickname) }}</template>
        <template #role="{ row }">
          <t-tag variant="light">{{ fieldValue(row.role_label || row.role?.label || row.role?.name) }}</t-tag>
        </template>
        <template #permissionCount="{ row }">
          <span>{{ row.permissions?.includes('*') ? $t('admin.staff.allPermissions') : $t('admin.staff.permissionCount', { count: row.permissions?.length || 0 }) }}</span>
        </template>
        <template #status="{ row }">
          <t-tag :theme="Number(row.status) === 1 ? 'success' : 'danger'" variant="light">
            {{ Number(row.status) === 1 ? $t('admin.staff.enabled') : $t('admin.staff.disabled') }}
          </t-tag>
        </template>
        <template #lastLogin="{ row }">
          <div class="staff-login">
            <span>{{ fieldValue(row.last_login_at) }}</span>
            <em>{{ fieldValue(row.last_login_ip) }}</em>
          </div>
        </template>
        <template #actions="{ row }">
          <t-space size="small">
            <t-button v-if="canManage" theme="primary" variant="text" @click="openEditDialog(row)">{{ $t('admin.staff.edit') }}</t-button>
            <t-button v-if="canEditStaffIdentity" theme="warning" variant="text" @click="openResetDialog(row)"
              >{{ $t('admin.staff.resetPassword') }}</t-button
            >
            <t-button
              v-if="canManage"
              :theme="Number(row.status) === 1 ? 'danger' : 'success'"
              variant="text"
              @click="handleToggleStatus(row)"
            >
              {{ Number(row.status) === 1 ? $t('admin.staff.disabled') : $t('admin.staff.enabled') }}
            </t-button>
            <t-button v-if="canDeleteStaff(row)" theme="danger" variant="text" @click="handleDelete(row)"
              >{{ $t('admin.staff.delete') }}</t-button
            >
          </t-space>
        </template>
      </t-table>

      <div v-else class="staff-mobile-list">
        <t-loading :loading="loading" size="small">
          <div v-if="list.length" class="staff-mobile-stack">
            <mobile-record-card
              v-for="row in list"
              :key="row.id"
              :title="fieldValue(row.username)"
              :subtitle="fieldValue(row.role_label || row.role?.label || row.role?.name)"
              :description="fieldValue(row.email)"
              :status-label="Number(row.status) === 1 ? $t('admin.staff.enabled') : $t('admin.staff.disabled')"
              :status-theme="Number(row.status) === 1 ? 'success' : 'danger'"
              :rows="[
                { label: $t('admin.staff.nicknameLabel'), value: fieldValue(row.nickname) },
                {
                  label: $t('admin.staff.permissionLabel'),
                  value: row.permissions?.includes('*') ? $t('admin.staff.allPermissions') : $t('admin.staff.permissionCount', { count: row.permissions?.length || 0 }),
                },
                { label: $t('admin.staff.lastLoginLabel'), value: fieldValue(row.last_login_at) },
                { label: $t('admin.staff.lastLoginIpLabel'), value: fieldValue(row.last_login_ip) },
              ]"
              :action-options="staffMobileActions(row)"
              @action="(value) => handleStaffMobileAction(value, row)"
            />
          </div>
          <t-empty v-else />
        </t-loading>
        <t-pagination
          v-if="total > 0"
          v-model:current="page"
          v-model:page-size="pageSize"
          class="staff-mobile-pagination"
          :total="total"
          @change="handlePageChange"
        />
      </div>
    </t-card>

    <t-dialog
      v-model:visible="formVisible"
      :header="form.id ? $t('admin.staff.editStaffTitle') : $t('admin.staff.addStaffTitle')"
      width="720px"
      :confirm-btn="{ content: $t('admin.staff.save'), theme: 'primary' }"
      :confirm-loading="saving"
      @confirm="submitForm"
    >
      <t-form ref="formRef" :data="form" :rules="formRules" label-align="top">
        <div class="staff-form-grid">
          <t-form-item :label="$t('admin.staff.accountLabel')" name="username">
            <t-input
              v-model="form.username"
              :disabled="form.id ? !canEditStaffIdentity : false"
              :placeholder="$t('admin.staff.accountPlaceholder')"
            />
          </t-form-item>
          <t-form-item :label="$t('admin.staff.nicknameLabel')" name="nickname">
            <t-input v-model="form.nickname" />
          </t-form-item>
          <t-form-item :label="$t('admin.staff.emailLabel')" name="email">
            <t-input v-model="form.email" :disabled="form.id ? !canEditStaffIdentity : false" />
          </t-form-item>
          <t-form-item :label="$t('admin.staff.roleLabel')" name="role_id">
            <t-select v-model="form.role_id" :placeholder="$t('admin.staff.selectRolePlaceholder')">
              <t-option v-for="role in roles" :key="role.id" :label="roleLabel(role)" :value="role.id" />
            </t-select>
          </t-form-item>
          <t-form-item v-if="!form.id" :label="$t('admin.staff.initialPasswordLabel')" name="password">
            <t-input v-model="form.password" type="password" />
          </t-form-item>
          <t-form-item :label="$t('admin.staff.statusLabel')" name="status">
            <t-switch v-model="form.status" :custom-value="[1, 0]" :label="[$t('admin.staff.enabled'), $t('admin.staff.disabled')]" />
          </t-form-item>
        </div>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="resetVisible"
      :header="$t('admin.staff.resetPasswordTitle')"
      width="520px"
      :confirm-btn="{ content: $t('admin.staff.confirmReset'), theme: 'danger' }"
      :confirm-loading="resetting"
      @confirm="submitResetPassword"
    >
      <t-form ref="resetFormRef" :data="resetForm" :rules="resetRules" label-align="top">
        <t-form-item :label="$t('admin.staff.newPasswordLabel')" name="password">
          <t-input v-model="resetForm.password" type="password" />
        </t-form-item>
        <t-form-item :label="$t('admin.staff.confirmPasswordLabel')" name="password_confirmation">
          <t-input v-model="resetForm.password_confirmation" type="password" />
        </t-form-item>
      </t-form>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { SearchIcon, UserAddIcon } from 'tdesign-icons-vue-next';
import type { FormInstanceFunctions, FormRule, PageInfo, PrimaryTableCol, TableRowData } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';

import type { CreateStaffPayload, StaffPayload, StaffRecord, StaffRoleOption } from '@/api/admin-staff';
import { adminStaffApi } from '@/api/admin-staff';
import MobileRecordCard from '@/components/mobile-record-card/index.vue';
import { AdminPermissions, hasPermissionInList } from '@/constants/permissions';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { t } from '@/locales';
import { useUserStore } from '@/store';
import { required } from '@/utils/formRules';
import { errorMessage } from '@/utils/userMessage';

defineOptions({
  name: 'AdminStaff',
});

interface StaffForm {
  id: number | string | null;
  username: string;
  nickname: string;
  email: string;
  role_id: number | string | '';
  password: string;
  status: number;
}

const userStore = useUserStore();
const loading = ref(false);
const saving = ref(false);
const resetting = ref(false);
const formVisible = ref(false);
const resetVisible = ref(false);
const formRef = ref<FormInstanceFunctions>();
const resetFormRef = ref<FormInstanceFunctions>();
const list = ref<StaffRecord[]>([]);
const roles = ref<StaffRoleOption[]>([]);
const total = ref(0);
const page = ref(1);
const pageSize = ref(20);
const currentResetStaff = ref<StaffRecord | null>(null);

const filters = reactive<{ keyword: string; status: number | ''; role_id: number | string | '' }>({
  keyword: '',
  status: '',
  role_id: '',
});
const form = reactive<StaffForm>(createDefaultForm());
const resetForm = reactive({ password: '', password_confirmation: '' });

const canManage = computed(() => hasPermission(AdminPermissions.STAFF_MANAGE));
const canEditStaffIdentity = computed(() => hasPermission(AdminPermissions.ALL));
const isMobile = useMediaQuery('(max-width: 768px)');
const pagination = computed(() => ({
  current: page.value,
  pageSize: pageSize.value,
  total: total.value,
  pageSizeOptions: [20, 50, 100],
  showJumper: true,
}));

const formRules = computed<Record<string, FormRule[]>>(() => ({
  username: [
    required('请输入登录账号'),
    {
      pattern: /^[\w.@-]+$/,
      message: t('admin.staff.accountPatternTip'),
      type: 'error',
      trigger: 'blur',
    },
  ],
  role_id: [required('请选择角色')],
  password: form.id ? [] : [required('请输入初始密码'), { min: 8, message: t('admin.staff.initialPasswordMinLength'), type: 'error' }],
}));
const resetRules: Record<string, FormRule[]> = {
  password: [required('请输入新密码'), { min: 8, message: t('admin.staff.newPasswordMinLength'), type: 'error' }],
  password_confirmation: [required('请再次输入新密码')],
};
const columns: PrimaryTableCol<TableRowData>[] = [
  { title: 'ID', colKey: 'id', width: 80 },
  { title: t('admin.staff.accountOrEmailLabel'), colKey: 'account', minWidth: 220 },
  { title: t('admin.staff.nicknameLabel'), colKey: 'name', minWidth: 150 },
  { title: t('admin.staff.roleLabel'), colKey: 'role', width: 150 },
  { title: t('admin.staff.permissionLabel'), colKey: 'permissionCount', width: 120 },
  { title: t('common.status'), colKey: 'status', width: 100 },
  { title: t('admin.staff.lastLoginLabel'), colKey: 'lastLogin', minWidth: 210 },
  { title: t('common.action'), colKey: 'actions', fixed: 'right', width: 230 },
];

onMounted(async () => {
  await Promise.all([loadRoles(), loadList()]);
});

function createDefaultForm(): StaffForm {
  return {
    id: null,
    username: '',
    nickname: '',
    email: '',
    role_id: '',
    password: '',
    status: 1,
  };
}

async function loadRoles() {
  try {
    const response = await adminStaffApi.roles();
    roles.value = response.list || [];
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载角色列表失败'));
  }
}

async function loadList() {
  loading.value = true;
  try {
    const response = await adminStaffApi.list({
      keyword: filters.keyword,
      status: filters.status,
      role_id: filters.role_id,
      page: page.value,
      page_size: pageSize.value,
    });
    list.value = response.list || [];
    total.value = Number(response.total || 0);
  } catch (error) {
    list.value = [];
    total.value = 0;
    MessagePlugin.error(errorMessage(error, '加载员工列表失败'));
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  page.value = 1;
  loadList();
}

function handlePageChange(pageInfo: PageInfo) {
  page.value = pageInfo.current;
  pageSize.value = pageInfo.pageSize;
  loadList();
}

function staffMobileActions(row: StaffRecord) {
  if (!canManage.value) return [];
  const actions = [
    { content: '编辑', value: 'edit' },
    { content: Number(row.status) === 1 ? '停用' : '启用', value: 'toggle' },
  ];
  if (canEditStaffIdentity.value) {
    actions.splice(1, 0, { content: '重置密码', value: 'reset' });
  }
  if (canDeleteStaff(row)) {
    actions.push({ content: '删除', value: 'delete' });
  }
  return actions;
}

function handleStaffMobileAction(value: unknown, row: StaffRecord) {
  if (value === 'edit') openEditDialog(row);
  else if (value === 'reset') openResetDialog(row);
  else if (value === 'toggle') handleToggleStatus(row);
  else if (value === 'delete') handleDelete(row);
}

function openCreateDialog() {
  Object.assign(form, createDefaultForm());
  formVisible.value = true;
}

function openEditDialog(row: StaffRecord) {
  Object.assign(form, {
    id: row.id,
    username: String(row.username || ''),
    nickname: String(row.nickname || ''),
    email: String(row.email || ''),
    role_id: row.role_id || '',
    password: '',
    status: Number(row.status ?? 1),
  });
  formVisible.value = true;
}

async function submitForm() {
  const result = await formRef.value?.validate?.();
  if (result !== true) return;

  if (!form.role_id) {
    MessagePlugin.warning(t('admin.staff.selectRolePlaceholder'));
    return;
  }

  if (!form.id && form.password.length < 8) {
    MessagePlugin.warning(t('admin.staff.initialPasswordMinLength'));
    return;
  }

  saving.value = true;
  try {
    if (form.id) {
      await adminStaffApi.update(form.id, buildUpdatePayload());
      MessagePlugin.success(t('admin.staff.updateSuccess'));
    } else {
      await adminStaffApi.create(buildCreatePayload());
      MessagePlugin.success(t('admin.staff.createSuccess'));
    }
    formVisible.value = false;
    await loadList();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存员工失败'));
  } finally {
    saving.value = false;
  }
}

function openResetDialog(row: StaffRecord) {
  currentResetStaff.value = row;
  Object.assign(resetForm, { password: '', password_confirmation: '' });
  resetVisible.value = true;
}

async function submitResetPassword() {
  const result = await resetFormRef.value?.validate?.();
  if (result !== true || !currentResetStaff.value) return;

  if (resetForm.password.length < 8) {
    MessagePlugin.warning(t('admin.staff.newPasswordMinLength'));
    return;
  }

  if (resetForm.password !== resetForm.password_confirmation) {
    MessagePlugin.warning(t('admin.staff.passwordMismatch'));
    return;
  }

  resetting.value = true;
  try {
    await adminStaffApi.resetPassword(currentResetStaff.value.id, { ...resetForm });
    MessagePlugin.success(t('admin.staff.passwordResetSuccess'));
    resetVisible.value = false;
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '重置密码失败'));
  } finally {
    resetting.value = false;
  }
}

function handleToggleStatus(row: StaffRecord) {
  const enabling = Number(row.status) !== 1;
  const dialog = DialogPlugin.confirm({
    header: enabling ? '启用员工' : '停用员工',
    body: `确认${enabling ? '启用' : '停用'}员工「${row.username || row.nickname || row.id}」？`,
    confirmBtn: { content: enabling ? '启用' : '停用', theme: enabling ? 'success' : 'danger' },
    cancelBtn: t('common.cancel'),
    onConfirm: async () => {
      try {
        await adminStaffApi.toggleStatus(row.id, enabling);
        MessagePlugin.success(t('admin.staff.statusUpdateSuccess'));
        await loadList();
        dialog.destroy();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '更新员工状态失败'));
      }
    },
  });
}

function handleDelete(row: StaffRecord) {
  const dialog = DialogPlugin.confirm({
    header: '删除员工',
    body: `确认删除已停用员工「${row.username || row.nickname || row.id}」？删除后该员工将无法登录。`,
    confirmBtn: { content: '删除', theme: 'danger' },
    cancelBtn: t('common.cancel'),
    onConfirm: async () => {
      try {
        await adminStaffApi.delete(row.id);
        MessagePlugin.success(t('admin.staff.deleteSuccess'));
        await loadList();
        dialog.destroy();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '删除员工失败'));
      }
    },
  });
}

function buildCreatePayload(): CreateStaffPayload {
  return {
    ...buildUpdatePayload(),
    password: form.password,
  };
}

function buildUpdatePayload(): StaffPayload {
  const payload: StaffPayload = {
    nickname: form.nickname.trim() || null,
    role_id: form.role_id,
    status: Number(form.status),
  };

  if (!form.id || canEditStaffIdentity.value) {
    payload.username = form.username.trim();
    payload.email = form.email.trim() || null;
  }

  return payload;
}

function roleLabel(role: StaffRoleOption) {
  return String(role.label || role.name || role.id);
}

function fieldValue(value: unknown) {
  const text = String(value ?? '').trim();
  return text || '-';
}

function hasPermission(permission: string) {
  const permissions = userStore.userInfo?.permissions || [];
  return hasPermissionInList(permissions, permission);
}

function canDeleteStaff(row: StaffRecord) {
  return canEditStaffIdentity.value && Number(row.status) !== 1 && Number(row.id) !== Number(userStore.userInfo?.id);
}
</script>
