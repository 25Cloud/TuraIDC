<template>
  <t-drawer
    :visible="visible"
    :size="drawerSize"
    :header="$t('autoRefund.upstreamProducts.title')"
    :footer="false"
    @update:visible="(value: boolean) => emit('update:visible', value)"
    @close="emit('close')"
  >
    <div class="upstream-products">
      <p class="upstream-products__hint">{{ $t('autoRefund.upstreamProducts.hint') }}</p>

      <div class="upstream-products__toolbar">
        <t-input
          v-model="keyword"
          class="upstream-products__search"
          clearable
          :placeholder="$t('autoRefund.upstreamProducts.searchPlaceholder')"
        >
          <template #suffix-icon><search-icon /></template>
        </t-input>
        <t-button variant="outline" :loading="loading" @click="load">{{
          $t('autoRefund.upstreamProducts.refresh')
        }}</t-button>
      </div>

      <t-alert v-if="error" theme="error" :message="error" />
      <t-alert v-if="upstreamError" theme="warning" :message="upstreamError" />
      <p v-if="!loading && !error" class="upstream-products__summary">{{ summaryText }}</p>

      <t-loading :loading="loading" size="small">
        <t-table
          v-if="rows.length"
          row-key="hostid"
          size="small"
          hover
          table-layout="fixed"
          :data="rows"
          :columns="columns"
        >
          <template #hostid="{ row }">
            <span class="upstream-products__mono">{{ fieldValue(row.hostid) }}</span>
          </template>
          <template #product="{ row }">{{ fieldValue(row.product_name) }}</template>
          <template #address="{ row }">
            <div class="upstream-products__stack">
              <strong>{{ fieldValue(row.domain) }}</strong>
              <span>{{ fieldValue(row.ip) }}</span>
            </div>
          </template>
          <template #nextDueDate="{ row }">{{ dateText(row.next_due_date) }}</template>
          <template #localService="{ row }">
            <div class="upstream-products__stack">
              <button
                v-if="canGoService(row.service_id, row.user_id)"
                type="button"
                class="upstream-products__link"
                @click="goService(router, row.service_id, row.user_id)"
              >
                <strong>{{ fieldValue(row.service_name) }}</strong>
              </button>
              <strong v-else>{{ boundLabel(row) }}</strong>
              <span>{{ localBindingText(row) }}</span>
            </div>
          </template>
          <template #bound="{ row }">
            <t-tag size="small" variant="light" :theme="row.bound ? 'success' : 'default'">
              {{ row.bound ? $t('autoRefund.upstreamProducts.bound') : $t('autoRefund.upstreamProducts.unbound') }}
            </t-tag>
          </template>
        </t-table>
        <t-empty v-else-if="!loading" :description="$t('autoRefund.upstreamProducts.empty')" />
      </t-loading>
    </div>
  </t-drawer>
</template>
<script setup lang="ts">
import { SearchIcon } from 'tdesign-icons-vue-next';
import type { PrimaryTableCol, TableRowData } from 'tdesign-vue-next';
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';

import type { AutoRefundUpstreamProduct, AutoRefundUpstreamProducts } from '@/api/admin/autoRefund';
import { autoRefundApi } from '@/api/admin/autoRefund';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { t } from '@/locales';
import { fieldValue, formatDateTime } from '@/utils/format';
import { canGoService, goService } from '@/utils/route/adminEntityLink';
import { errorMessage } from '@/utils/userMessage';

const props = withDefaults(defineProps<{ visible?: boolean }>(), { visible: false });

const emit = defineEmits<{
  (event: 'update:visible', value: boolean): void;
  (event: 'close'): void;
}>();

const router = useRouter();
const isMobile = useMediaQuery('(max-width: 768px)');
const drawerSize = computed(() => (isMobile.value ? '100%' : '920px'));

const loading = ref(false);
const error = ref('');
const keyword = ref('');
const data = ref<AutoRefundUpstreamProducts | null>(null);

const upstreamError = computed(() => {
  const message = data.value?.upstream_error || '';

  return message ? t('autoRefund.upstreamError', { message }) : '';
});

const rows = computed<AutoRefundUpstreamProduct[]>(() => {
  const list = data.value?.list || [];
  const key = keyword.value.trim().toLowerCase();
  if (!key) return list;

  return list.filter((row) =>
    [row.hostid, row.product_name, row.domain, row.ip, row.service_name, userLabel(row)]
      .filter(Boolean)
      .some((value) => String(value).toLowerCase().includes(key)),
  );
});

const summaryText = computed(() => {
  const payload = data.value;
  if (!payload) return '';

  return t('autoRefund.upstreamProducts.summary', {
    total: payload.total,
    bound: payload.bound,
    unbound: payload.unbound,
  });
});

const columns = computed<PrimaryTableCol<TableRowData>[]>(() => [
  { colKey: 'hostid', title: t('autoRefund.upstreamProducts.columns.hostid'), width: 110 },
  { colKey: 'product', title: t('autoRefund.upstreamProducts.columns.product'), minWidth: 130, ellipsis: true },
  { colKey: 'address', title: t('autoRefund.upstreamProducts.columns.address'), minWidth: 160 },
  { colKey: 'nextDueDate', title: t('autoRefund.upstreamProducts.columns.nextDueDate'), width: 130 },
  { colKey: 'localService', title: t('autoRefund.upstreamProducts.columns.localService'), minWidth: 210 },
  { colKey: 'bound', title: t('autoRefund.upstreamProducts.columns.bound'), width: 88, fixed: 'right' },
]);

function userLabel(row: AutoRefundUpstreamProduct) {
  const user = row.user;

  return fieldValue(user?.display_name || user?.nickname || user?.email || user?.phone);
}

/** 本地绑定信息：实例 ID + 归属用户，两行足够，不再单开一列挤掉对账主体 */
function localBindingText(row: AutoRefundUpstreamProduct) {
  if (!row.bound) return '-';

  const user = row.user;
  const email = user?.email && user.email !== userLabel(row) ? ` · ${user.email}` : '';
  const idPrefix = t('autoRefund.fields.serviceId');

  return `${idPrefix} ${fieldValue(row.service_id)} · ${userLabel(row)}${email}`;
}

function boundLabel(row: AutoRefundUpstreamProduct) {
  return row.bound ? fieldValue(row.service_name) : t('autoRefund.upstreamProducts.unbound');
}

/** 上游到期日常常只有日期：原样展示，别让时区把它变成 08:00:00 */
function dateText(value: unknown) {
  const text = fieldValue(value);

  return /^\d{4}-\d{2}-\d{2}$/.test(text) ? text : formatDateTime(value);
}

async function load() {
  loading.value = true;
  error.value = '';
  try {
    data.value = await autoRefundApi.upstreamProducts();
  } catch (err) {
    data.value = null;
    error.value = errorMessage(err, t('autoRefund.upstreamProducts.loadFailed'));
  } finally {
    loading.value = false;
  }
}

// 每次打开都重新拉：对账看的是上游当下状态，缓存上一次的结果没有意义
watch(
  () => props.visible,
  (visible) => {
    if (!visible) return;
    keyword.value = '';
    void load();
  },
  { immediate: true },
);
</script>
<style lang="less" scoped>
.upstream-products {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.upstream-products__hint {
  margin: 0;
  color: var(--td-text-color-secondary);
  font-size: var(--td-font-size-size-2, 13px);
  line-height: 1.7;
}

.upstream-products__toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
}

.upstream-products__search {
  width: 260px;
}

.upstream-products__summary {
  margin: 0;
  color: var(--td-text-color-primary);
  font-size: var(--td-font-size-size-2, 13px);
  font-weight: 600;
}

.upstream-products__mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: var(--td-font-size-size-1, 12px);
}

.upstream-products__stack {
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: 2px;
}

.upstream-products__stack strong {
  overflow: hidden;
  color: var(--td-text-color-primary);
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.upstream-products__stack span {
  overflow: hidden;
  color: var(--td-text-color-secondary);
  font-size: var(--td-font-size-size-1, 12px);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.upstream-products__link {
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--td-brand-color);
  font: inherit;
  text-align: left;
  cursor: pointer;
}

@media (width <= 768px) {
  .upstream-products__toolbar {
    flex-wrap: wrap;
  }

  .upstream-products__search {
    width: 100%;
  }
}
</style>
