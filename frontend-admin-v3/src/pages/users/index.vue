<template>
  <div class="users-page">
    <t-card class="users-table-card" :bordered="false">
      <div class="users-filter">
        <t-input
          v-model="filters.keyword"
          clearable
          placeholder="搜索用户ID/邮箱/手机号/昵称/真实姓名/QQ/身份证"
          @enter="handleSearch"
          @clear="handleSearch"
        >
          <template #suffix-icon><search-icon /></template>
        </t-input>
        <t-select v-model="filters.status" clearable placeholder="状态" @change="handleSearch">
          <t-option label="正常" :value="1" />
          <t-option label="禁用" :value="0" />
        </t-select>
        <t-button v-if="canManageUsers" size="medium" theme="primary" @click="openCreateDialog">
          <template #icon><user-add-icon /></template>
          新增用户
        </t-button>
      </div>

      <div class="users-list-summary">
        <span>共 {{ total }} 位用户</span>
        <span>第 {{ page }} 页，每页 {{ pageSize }} 条</span>
      </div>

      <t-table
        class="users-desktop-table"
        row-key="id"
        :data="list"
        :columns="columns"
        :loading="loading"
        :hover="true"
        :pagination="pagination"
        table-layout="fixed"
        @page-change="handlePageChange"
      >
        <template #account="{ row }">
          <button class="users-account" type="button" @click="openDetail(row.id)">
            <span>{{ row.phone || '未绑定手机号' }}</span>
            <em>{{ row.email || '未绑定邮箱' }}</em>
          </button>
        </template>
        <template #nickname="{ row }">
          <span class="users-name">
            <check-circle-filled-icon
              v-if="Number(row.verification_status) === 2 || Number(row.is_verified) === 1"
              class="users-verified"
            />
            <span>{{ row.display_name || row.nickname || '-' }}</span>
          </span>
        </template>
        <template #balance="{ row }">
          <span class="users-balance">{{ formatMoney(row.cash_balance) }}</span>
        </template>
        <template #openedServices="{ row }">
          <span :class="{ 'users-empty-count': !Number(row.opened_product_count || 0) }">
            {{ Number(row.opened_product_count || 0) ? `${row.opened_product_count} 个` : '-' }}
          </span>
        </template>
        <template #agentGroup="{ row }">
          <t-tag v-if="row.agent_group?.name" theme="primary" variant="light">{{ row.agent_group.name }}</t-tag>
          <span v-else>-</span>
        </template>
        <template #status="{ row }">
          <t-tag :theme="Number(row.status) === 1 ? 'success' : 'danger'" variant="light">
            {{ Number(row.status) === 1 ? '正常' : '禁用' }}
          </t-tag>
        </template>
        <template #createdAt="{ row }">{{ formatDateTime(row.created_at) }}</template>
      </t-table>

      <div class="users-mobile-list">
        <t-loading :loading="loading" size="small">
          <div v-if="list.length" class="users-mobile-stack">
            <article v-for="row in list" :key="row.id" class="users-mobile-card">
              <div class="users-mobile-card__head">
                <button class="users-mobile-card__account" type="button" @click="openDetail(row.id)">
                  <span>{{ row.phone || '未绑定手机号' }}</span>
                  <em>{{ row.email || '未绑定邮箱' }}</em>
                </button>
                <t-tag :theme="Number(row.status) === 1 ? 'success' : 'danger'" variant="light">
                  {{ Number(row.status) === 1 ? '正常' : '禁用' }}
                </t-tag>
              </div>
              <div class="users-mobile-card__name">
                <check-circle-filled-icon
                  v-if="Number(row.verification_status) === 2 || Number(row.is_verified) === 1"
                  class="users-verified"
                />
                <span>{{ row.display_name || row.nickname || '-' }}</span>
              </div>
              <dl class="users-mobile-card__meta">
                <div>
                  <dt>余额</dt>
                  <dd class="users-balance">{{ formatMoney(row.cash_balance) }}</dd>
                </div>
                <div>
                  <dt>代理组</dt>
                  <dd>
                    <t-tag v-if="row.agent_group?.name" theme="primary" variant="light">{{
                      row.agent_group.name
                    }}</t-tag>
                    <span v-else>-</span>
                  </dd>
                </div>
                <div>
                  <dt>服务</dt>
                  <dd>{{ Number(row.opened_product_count || 0) ? `${row.opened_product_count} 个` : '-' }}</dd>
                </div>
                <div>
                  <dt>注册时间</dt>
                  <dd>{{ formatDateTime(row.created_at) }}</dd>
                </div>
              </dl>
            </article>
          </div>
          <t-empty v-else />
        </t-loading>
        <t-pagination
          v-model:current="page"
          v-model:page-size="pageSize"
          class="users-mobile-pagination"
          :total="total"
          :page-size-options="[20, 50, 100]"
          @change="handlePageChange"
        />
      </div>
    </t-card>

    <t-dialog
      v-model:visible="createVisible"
      header="新增用户"
      width="520px"
      :confirm-btn="{ content: '确定', loading: submitLoading }"
      @confirm="handleCreate"
    >
      <t-form ref="createFormRef" class="users-dialog-form" :data="createForm" :rules="createRules" label-align="top">
        <t-form-item label="邮箱" name="email" required-mark>
          <t-input v-model="createForm.email" />
        </t-form-item>
        <t-form-item label="昵称" name="nickname">
          <t-input v-model="createForm.nickname" />
        </t-form-item>
        <t-form-item label="手机号" name="phone" required-mark>
          <t-input v-model="createForm.phone" />
        </t-form-item>
        <t-form-item label="密码" name="password" required-mark>
          <t-input v-model="createForm.password" type="password" />
        </t-form-item>
      </t-form>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { CheckCircleFilledIcon, SearchIcon, UserAddIcon } from 'tdesign-icons-vue-next';
import type { FormInstanceFunctions, FormRule, PageInfo, PrimaryTableCol, TableRowData } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import type { AdminUser } from '@/api/user';
import { userApi } from '@/api/user';
import { AdminPermissions } from '@/constants/permissions';
import { formatDateTime, formatMoney } from '@/utils/format';
import { required } from '@/utils/formRules';
import { hasAdminPermission } from '@/utils/permission';

defineOptions({
  name: 'AdminUsers',
});

const router = useRouter();
const canManageUsers = computed(() => hasAdminPermission(AdminPermissions.USER_MANAGE));

const loading = ref(false);
const submitLoading = ref(false);
const list = ref<AdminUser[]>([]);
const total = ref(0);
const page = ref(1);
const pageSize = ref(20);
const filters = reactive<{ keyword: string; status: number | '' }>({ keyword: '', status: '' });

const columns: PrimaryTableCol<TableRowData>[] = [
  { title: 'ID', colKey: 'id', width: 80 },
  { title: '手机号 / 邮箱', colKey: 'account', width: 220 },
  { title: '昵称', colKey: 'nickname', width: 180 },
  { title: '余额', colKey: 'cash_balance', width: 120 },
  { title: '已开通服务', colKey: 'openedServices', width: 130, align: 'center' },
  { title: '代理组', colKey: 'agentGroup', width: 140 },
  { title: '状态', colKey: 'status', width: 100 },
  { title: '注册时间', colKey: 'createdAt', width: 180 },
];

const pagination = computed(() => ({
  current: page.value,
  pageSize: pageSize.value,
  total: total.value,
  pageSizeOptions: [20, 50, 100],
  showJumper: true,
}));

const createVisible = ref(false);
const createFormRef = ref<FormInstanceFunctions>();
const createForm = reactive({ email: '', nickname: '', phone: '', password: '' });
const createRules: Record<string, FormRule[]> = {
  email: [required('请输入有效邮箱'), { email: true, message: '请输入有效邮箱', type: 'warning' }],
  phone: [required('请输入手机号')],
  // 后端 StoreUserRequest 是 ['required','string','min:8']；此前只判必填，
  // 管理员输 3 位也能提交，直到 422 才知道。
  password: [required('请输入密码'), { min: 8, message: '密码至少需要 8 位', type: 'error' }],
};

async function loadList() {
  loading.value = true;
  try {
    const response = await userApi.list({
      keyword: filters.keyword,
      status: filters.status,
      page: page.value,
      page_size: pageSize.value,
    });
    list.value = response.list || [];
    total.value = Number(response.total || 0);
  } catch {
    list.value = [];
    total.value = 0;
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

function openCreateDialog() {
  if (!canManageUsers.value) {
    MessagePlugin.warning('当前账号无用户管理权限');
    return;
  }

  Object.assign(createForm, { email: '', nickname: '', phone: '', password: '' });
  createVisible.value = true;
}

async function handleCreate() {
  if (!canManageUsers.value) {
    MessagePlugin.warning('当前账号无用户管理权限');
    return;
  }

  const result = await createFormRef.value?.validate?.();
  if (result !== true) return;

  submitLoading.value = true;
  try {
    await userApi.create(createForm);
    MessagePlugin.success('创建成功');
    createVisible.value = false;
    loadList();
  } finally {
    submitLoading.value = false;
  }
}

function openDetail(id: number | string) {
  router.push(`/admin/users/${id}`);
}

onMounted(() => loadList());
</script>
