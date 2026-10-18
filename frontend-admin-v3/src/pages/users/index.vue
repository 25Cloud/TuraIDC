<template>
  <div class="users-page">
    <t-card class="users-table-card" :bordered="false">
      <div class="users-filter">
        <t-input
          v-model="filters.keyword"
          clearable
          :placeholder="$t('users.index.searchPlaceholder')"
          @enter="handleSearch"
          @clear="handleSearch"
        >
          <template #suffix-icon><search-icon /></template>
        </t-input>
        <t-select
          v-model="filters.status"
          clearable
          :placeholder="$t('users.index.statusPlaceholder')"
          @change="handleSearch"
        >
          <t-option :label="$t('users.index.statusNormal')" :value="1" />
          <t-option :label="$t('users.index.statusDisabled')" :value="0" />
        </t-select>
        <t-button v-if="canManageUsers" size="medium" theme="primary" @click="openCreateDialog">
          <template #icon><user-add-icon /></template>
          {{ $t('users.index.addUser') }}
        </t-button>
      </div>

      <div class="users-list-summary">
        <span
          >{{ $t('users.index.totalUsersPrefix') }} {{ total }} {{ $t('users.index.totalUsersSuffix') }}</span
        >
        <span>
          {{ $t('users.index.pagePrefix') }} {{ page }} {{ $t('users.index.pageMiddle') }} {{ pageSize }}
          {{ $t('users.index.pageSuffix') }}
        </span>
      </div>

      <div v-if="!isMobile" class="admin-list-toolbar">
        <admin-view-mode-switch inline :mode="viewMode" @change="setViewMode" />
      </div>

      <t-table
        v-if="!isCardView"
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
        <template #id="{ row }">
          <button type="button" class="user-link" :disabled="!isEntityId(row.id)" @click="openDetail(row.id)">
            <strong>{{ fieldValue(row.id) }}</strong>
          </button>
        </template>
        <template #account="{ row }">
          <button class="users-account" type="button" @click="openDetail(row.id)">
            <span>{{ row.phone || $t('users.index.unboundPhone') }}</span>
            <em>{{ row.email || $t('users.index.unboundEmail') }}</em>
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
            {{ Number(row.opened_product_count || 0) ? `${row.opened_product_count} ${$t('users.index.itemUnit')}` : '-' }}
          </span>
        </template>
        <template #agentGroup="{ row }">
          <t-tag v-if="row.agent_group?.name" theme="primary" variant="light">{{ row.agent_group.name }}</t-tag>
          <span v-else>-</span>
        </template>
        <template #status="{ row }">
          <t-tag :theme="Number(row.status) === 1 ? 'success' : 'danger'" variant="light">
            {{ Number(row.status) === 1 ? $t('users.index.statusNormal') : $t('users.index.statusDisabled') }}
          </t-tag>
        </template>
        <template #createdAt="{ row }">{{ formatDateTime(row.created_at) }}</template>
      </t-table>

      <div
        v-if="isMobile || isCardView"
        class="users-mobile-list"
        :style="isCardView && !isMobile ? { display: 'block' } : undefined"
      >
        <t-loading :loading="loading" size="small">
          <div
            v-if="list.length"
            class="users-mobile-stack"
            :style="isCardView && !isMobile ? ADMIN_CARD_GRID_STYLE : undefined"
          >
            <article v-for="row in list" :key="row.id" class="users-mobile-card">
              <div class="users-mobile-card__head">
                <button class="users-mobile-card__account" type="button" @click="openDetail(row.id)">
                  <span>{{ row.phone || $t('users.index.unboundPhone') }}</span>
                  <em>{{ row.email || $t('users.index.unboundEmail') }}</em>
                </button>
                <t-tag :theme="Number(row.status) === 1 ? 'success' : 'danger'" variant="light">
                  {{ Number(row.status) === 1 ? $t('users.index.statusNormal') : $t('users.index.statusDisabled') }}
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
                  <dt>{{ $t('users.index.balance') }}</dt>
                  <dd class="users-balance">{{ formatMoney(row.cash_balance) }}</dd>
                </div>
                <div>
                  <dt>{{ $t('users.index.agentGroup') }}</dt>
                  <dd>
                    <t-tag v-if="row.agent_group?.name" theme="primary" variant="light">{{
                      row.agent_group.name
                    }}</t-tag>
                    <span v-else>-</span>
                  </dd>
                </div>
                <div>
                  <dt>{{ $t('users.index.services') }}</dt>
                  <dd>{{ Number(row.opened_product_count || 0) ? `${row.opened_product_count} ${$t('users.index.itemUnit')}` : '-' }}</dd>
                </div>
                <div>
                  <dt>{{ $t('users.index.registeredAt') }}</dt>
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
      :header="$t('users.index.addUser')"
      width="520px"
      :confirm-btn="{ content: $t('users.index.confirm'), loading: submitLoading }"
      @confirm="handleCreate"
    >
      <t-form ref="createFormRef" class="users-dialog-form" :data="createForm" :rules="createRules" label-align="top">
        <t-form-item :label="$t('users.index.email')" name="email" required-mark>
          <t-input v-model="createForm.email" />
        </t-form-item>
        <t-form-item :label="$t('users.index.nickname')" name="nickname">
          <t-input v-model="createForm.nickname" />
        </t-form-item>
        <t-form-item :label="$t('users.index.phone')" name="phone" required-mark>
          <t-input v-model="createForm.phone" />
        </t-form-item>
        <t-form-item :label="$t('users.index.password')" name="password" required-mark>
          <t-input v-model="createForm.password" type="password" />
        </t-form-item>
      </t-form>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import AdminViewModeSwitch from '@/components/admin-view-mode-switch/index.vue';
import { ADMIN_CARD_GRID_STYLE, useAdminViewMode } from '@/composables/useAdminViewMode';

const { mode: viewMode, isCard: isCardView, setMode: setViewMode } = useAdminViewMode('admin-users', 'list');

// 与其它列表页同一个断点（含 768px）
const isMobile = useMediaQuery('(max-width: 768px)');

import './index.less';

import { CheckCircleFilledIcon, SearchIcon, UserAddIcon } from 'tdesign-icons-vue-next';
import type { FormInstanceFunctions, FormRule, PageInfo, PrimaryTableCol, TableRowData } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useMediaQuery } from '@vueuse/core';

import type { AdminUser } from '@/api/user';
import { userApi } from '@/api/user';
import { AdminPermissions } from '@/constants/permissions';
import { t } from '@/locales';
import { fieldValue, formatDateTime, formatMoney } from '@/utils/format';
import { required } from '@/utils/formRules';
import { hasAdminPermission } from '@/utils/permission';
import { goUser, isEntityId } from '@/utils/route/adminEntityLink';
import { errorMessage } from '@/utils/userMessage';

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
  { title: t('users.index.account'), colKey: 'account', width: 220 },
  { title: t('users.index.nickname'), colKey: 'nickname', width: 180 },
  { title: t('users.index.balance'), colKey: 'cash_balance', width: 120 },
  { title: t('users.index.openedServices'), colKey: 'openedServices', width: 130, align: 'center' },
  { title: t('users.index.agentGroup'), colKey: 'agentGroup', width: 140 },
  { title: t('users.index.statusPlaceholder'), colKey: 'status', width: 100 },
  { title: t('users.index.registeredAt'), colKey: 'createdAt', width: 180 },
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
  email: [required(t('users.index.emailInvalid')), { email: true, message: t('users.index.emailInvalid'), type: 'warning' }],
  phone: [required('请输入手机号')],
  // 后端 StoreUserRequest 是 ['required','string','min:8']；此前只判必填，
  // 管理员输 3 位也能提交，直到 422 才知道。
  password: [required('请输入密码'), { min: 8, message: t('users.index.passwordMinLength'), type: 'error' }],
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
    MessagePlugin.warning(t('users.index.noPermission'));
    return;
  }

  Object.assign(createForm, { email: '', nickname: '', phone: '', password: '' });
  createVisible.value = true;
}

async function handleCreate() {
  if (!canManageUsers.value) {
    MessagePlugin.warning(t('users.index.noPermission'));
    return;
  }

  const result = await createFormRef.value?.validate?.();
  if (result !== true) return;

  submitLoading.value = true;
  try {
    await userApi.create(createForm);
    MessagePlugin.success(t('users.index.created'));
    createVisible.value = false;
    loadList();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '创建失败，请检查手机号/邮箱是否已被占用或格式是否正确'));
  } finally {
    submitLoading.value = false;
  }
}

function openDetail(id: number | string) {
  goUser(router, id);
}

onMounted(() => loadList());
</script>
