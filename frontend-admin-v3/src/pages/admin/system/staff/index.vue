<template>
  <div class="admin-staff-page">
    <t-card class="staff-card" :bordered="false">
      <div class="staff-filter">
        <t-input
          v-model="filters.keyword"
          clearable
          placeholder="搜索账号/昵称/邮箱"
          @enter="handleSearch"
          @clear="handleSearch"
        >
          <template #suffix-icon><search-icon /></template>
        </t-input>
        <t-select v-model="filters.role_id" clearable placeholder="角色" @change="handleSearch">
          <t-option v-for="role in roles" :key="role.id" :label="roleLabel(role)" :value="role.id" />
        </t-select>
        <t-select v-model="filters.status" clearable placeholder="状态" @change="handleSearch">
          <t-option label="启用" :value="1" />
          <t-option label="停用" :value="0" />
        </t-select>
        <t-button v-if="canManage" theme="primary" @click="openCreateDialog">
          <template #icon><user-add-icon /></template>
          新增员工
        </t-button>
      </div>

      <div class="staff-summary">
        <span>共 {{ total }} 位员工</span>
        <span>第 {{ page }} 页，每页 {{ pageSize }} 条</span>
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
          <span>{{ row.permissions?.includes('*') ? '全部权限' : `${row.permissions?.length || 0} 项` }}</span>
        </template>
        <template #status="{ row }">
          <t-tag :theme="Number(row.status) === 1 ? 'success' : 'danger'" variant="light">
            {{ Number(row.status) === 1 ? '启用' : '停用' }}
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
            <t-button v-if="canManage" theme="primary" variant="text" @click="openEditDialog(row)">编辑</t-button>
            <t-button v-if="canEditStaffIdentity" theme="warning" variant="text" @click="openResetDialog(row)"
              >重置密码</t-button
            >
            <t-button
              v-if="canManage"
              :theme="Number(row.status) === 1 ? 'danger' : 'success'"
              variant="text"
              @click="handleToggleStatus(row)"
            >
              {{ Number(row.status) === 1 ? '停用' : '启用' }}
            </t-button>
            <t-button v-if="canDeleteStaff(row)" theme="danger" variant="text" @click="handleDelete(row)"
              >删除</t-button
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
              :status-label="Number(row.status) === 1 ? '启用' : '停用'"
              :status-theme="Number(row.status) === 1 ? 'success' : 'danger'"
              :rows="[
                { label: '昵称', value: fieldValue(row.nickname) },
                {
                  label: '权限',
                  value: row.permissions?.includes('*') ? '全部权限' : `${row.permissions?.length || 0} 项`,
                },
                { label: '最近登录', value: fieldValue(row.last_login_at) },
                { label: '登录IP', value: fieldValue(row.last_login_ip) },
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
      :header="form.id ? '编辑员工' : '新增员工'"
      width="720px"
      :confirm-btn="{ content: '保存', theme: 'primary' }"
      :confirm-loading="saving"
      @confirm="submitForm"
    >
      <t-form ref="formRef" :data="form" :rules="formRules" label-align="top">
        <div class="staff-form-grid">
          <t-form-item label="登录账号" name="username">
            <t-input
              v-model="form.username"
              :disabled="form.id ? !canEditStaffIdentity : false"
              placeholder="字母、数字、下划线、点、横线或 @"
            />
          </t-form-item>
          <t-form-item label="昵称" name="nickname">
            <t-input v-model="form.nickname" />
          </t-form-item>
          <t-form-item label="邮箱" name="email">
            <t-input v-model="form.email" :disabled="form.id ? !canEditStaffIdentity : false" />
          </t-form-item>
          <t-form-item label="角色" name="role_id">
            <t-select v-model="form.role_id" placeholder="请选择角色">
              <t-option v-for="role in roles" :key="role.id" :label="roleLabel(role)" :value="role.id" />
            </t-select>
          </t-form-item>
          <t-form-item v-if="!form.id" label="初始密码" name="password">
            <t-input v-model="form.password" type="password" />
          </t-form-item>
          <t-form-item label="状态" name="status">
            <t-switch v-model="form.status" :custom-value="[1, 0]" :label="['启用', '停用']" />
          </t-form-item>
        </div>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="resetVisible"
      header="重置员工密码"
      width="520px"
      :confirm-btn="{ content: '确认重置', theme: 'danger' }"
      :confirm-loading="resetting"
      @confirm="submitResetPassword"
    >
      <t-form ref="resetFormRef" :data="resetForm" :rules="resetRules" label-align="top">
        <t-form-item label="新密码" name="password">
          <t-input v-model="resetForm.password" type="password" />
        </t-form-item>
        <t-form-item label="确认密码" name="password_confirmation">
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
      message: '登录账号仅支持字母、数字、下划线、点、横线和 @',
      type: 'error',
      trigger: 'blur',
    },
  ],
  role_id: [required('请选择角色')],
  password: form.id ? [] : [required('请输入初始密码'), { min: 8, message: '初始密码至少 8 位', type: 'error' }],
}));
const resetRules: Record<string, FormRule[]> = {
  password: [required('请输入新密码'), { min: 8, message: '新密码至少 8 位', type: 'error' }],
  password_confirmation: [required('请再次输入新密码')],
};
const columns: PrimaryTableCol<TableRowData>[] = [
  { title: 'ID', colKey: 'id', width: 80 },
  { title: '账号 / 邮箱', colKey: 'account', minWidth: 220 },
  { title: '昵称', colKey: 'name', minWidth: 150 },
  { title: '角色', colKey: 'role', width: 150 },
  { title: '权限', colKey: 'permissionCount', width: 120 },
  { title: '状态', colKey: 'status', width: 100 },
  { title: '最近登录', colKey: 'lastLogin', minWidth: 210 },
  { title: '操作', colKey: 'actions', fixed: 'right', width: 230 },
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
    MessagePlugin.warning('请选择角色');
    return;
  }

  if (!form.id && form.password.length < 8) {
    MessagePlugin.warning('初始密码至少 8 位');
    return;
  }

  saving.value = true;
  try {
    if (form.id) {
      await adminStaffApi.update(form.id, buildUpdatePayload());
      MessagePlugin.success('员工已更新');
    } else {
      await adminStaffApi.create(buildCreatePayload());
      MessagePlugin.success('员工已创建');
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
    MessagePlugin.warning('新密码至少 8 位');
    return;
  }

  if (resetForm.password !== resetForm.password_confirmation) {
    MessagePlugin.warning('两次输入的密码不一致');
    return;
  }

  resetting.value = true;
  try {
    await adminStaffApi.resetPassword(currentResetStaff.value.id, { ...resetForm });
    MessagePlugin.success('员工密码已重置');
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
    cancelBtn: '取消',
    onConfirm: async () => {
      try {
        await adminStaffApi.toggleStatus(row.id, enabling);
        MessagePlugin.success('员工状态已更新');
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
    cancelBtn: '取消',
    onConfirm: async () => {
      try {
        await adminStaffApi.delete(row.id);
        MessagePlugin.success('员工已删除');
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
