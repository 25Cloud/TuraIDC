<template>
  <div class="verification-panel">
    <section v-if="activePane === 'list'" class="verification-section">
      <div class="verification-filter">
        <t-input
          v-model="filters.keyword"
          clearable
          placeholder="输入关键字"
          @enter="handleSearch"
          @clear="handleSearch"
        >
          <template #suffix-icon><search-icon /></template>
        </t-input>
        <t-select v-model="quickStatus" placeholder="状态筛选" @change="handleQuickStatusChange">
          <t-option label="全部" value="all" />
          <t-option label="待认证" value="pending" />
          <t-option label="成功" value="success" />
          <t-option label="失败" value="failed" />
        </t-select>
      </div>

      <t-table
        class="verification-table"
        row-key="id"
        :data="list"
        :columns="columns"
        :loading="listLoading"
        :hover="true"
        :pagination="pagination"
        table-layout="fixed"
        @page-change="handlePageChange"
      >
        <template #displayName="{ row }">{{ row.display_name || '-' }}</template>
        <template #realName="{ row }">{{ row.real_name || '-' }}</template>
        <template #method="{ row }">{{ verificationMethodLabel(row) }}</template>
        <template #status="{ row }">
          <t-tag :theme="verificationStatusTheme(row)" variant="light">{{ verificationStatusLabel(row) }}</t-tag>
        </template>
        <template #createdAt="{ row }">{{ formatDateTime(row.created_at) }}</template>
        <template #operation="{ row }">
          <t-space size="small">
            <t-button variant="text" theme="primary" size="small" @click="openDetail(row)">查看</t-button>
            <t-button variant="text" theme="primary" size="small" @click="openHistory(row)">历史记录</t-button>
            <t-button
              v-if="canReject(row)"
              variant="text"
              theme="danger"
              size="small"
              :loading="actionLoadingId === row.id"
              @click="openReject(row)"
            >
              驳回
            </t-button>
          </t-space>
        </template>
      </t-table>

      <div class="verification-mobile-list">
        <t-loading :loading="listLoading" size="small">
          <div v-if="list.length" class="verification-mobile-stack">
            <article v-for="row in list" :key="row.id" class="verification-mobile-card">
              <div class="verification-mobile-card__head">
                <strong>{{ row.display_name || row.real_name || '-' }}</strong>
                <t-tag :theme="verificationStatusTheme(row)" variant="light">{{ verificationStatusLabel(row) }}</t-tag>
              </div>
              <dl class="verification-mobile-card__meta">
                <div>
                  <dt>实名名称</dt>
                  <dd>{{ row.real_name || '-' }}</dd>
                </div>
                <div>
                  <dt>身份证号码</dt>
                  <dd>{{ row.id_card_masked || '-' }}</dd>
                </div>
                <div>
                  <dt>认证方式</dt>
                  <dd>{{ verificationMethodLabel(row) }}</dd>
                </div>
                <div>
                  <dt>提交时间</dt>
                  <dd>{{ formatDateTime(row.created_at) }}</dd>
                </div>
              </dl>
              <div class="verification-mobile-card__actions">
                <t-button variant="outline" theme="primary" size="small" @click="openDetail(row)">查看</t-button>
                <t-button variant="outline" theme="primary" size="small" @click="openHistory(row)">历史记录</t-button>
                <t-button v-if="canReject(row)" variant="outline" theme="danger" size="small" @click="openReject(row)">
                  驳回
                </t-button>
              </div>
            </article>
          </div>
          <t-empty v-else />
        </t-loading>
        <t-pagination
          v-model:current="page"
          v-model:page-size="pageSize"
          class="verification-mobile-pagination"
          :total="total"
          :page-size-options="[20, 50, 100]"
          @change="handlePageChange"
        />
      </div>
    </section>

    <section v-else-if="activePane === 'manage'" class="verification-section verification-form-section">
      <t-form ref="feeFormRef" :data="feeForm" :rules="feeRules" label-align="top">
        <t-form-item label="免费认证次数" name="free_attempts">
          <t-input-number v-model="feeForm.free_attempts" :min="0" :max="10" />
          <p class="verification-help">每个用户可免费进行实名认证的次数。</p>
        </t-form-item>
        <t-form-item label="失败后再次认证费用" name="retry_fee">
          <t-input-number v-model="feeForm.retry_fee" :min="0" :decimal-places="2" :step="0.5" />
          <p class="verification-help">超过免费次数后，每次再次认证收取的费用。</p>
        </t-form-item>
        <t-form-item>
          <t-button theme="primary" :loading="feeLoading" @click="saveFeeSettings">保存费用设置</t-button>
        </t-form-item>
      </t-form>
    </section>

    <t-drawer
      v-model:visible="detailVisible"
      size="560px"
      header="实名认证详情"
      :footer="false"
      @close="closeVerificationDetail"
    >
      <t-loading :loading="detailLoading" size="small">
        <t-descriptions :column="1" bordered>
          <t-descriptions-item label="用户名称">{{ formatDetailValue(detail.display_name) }}</t-descriptions-item>
          <t-descriptions-item label="真实姓名">{{ formatDetailValue(detail.real_name) }}</t-descriptions-item>
          <t-descriptions-item label="认证方式">{{
            formatDetailValue(detail.verification_method_label)
          }}</t-descriptions-item>
          <t-descriptions-item label="认证类型">{{
            formatDetailValue(detail.verification_type_label)
          }}</t-descriptions-item>
          <t-descriptions-item label="证件类型">{{
            formatDetailValue(detail.document_type_label)
          }}</t-descriptions-item>
          <t-descriptions-item label="身份地区">{{
            formatDetailValue(detail.identity_region_label)
          }}</t-descriptions-item>
          <t-descriptions-item label="证件号码">{{
            formatDetailValue(detail.id_card || detail.id_card_masked)
          }}</t-descriptions-item>
          <t-descriptions-item label="接口单号">{{
            formatDetailValue(detail.verification_certify_id)
          }}</t-descriptions-item>
          <t-descriptions-item label="状态说明">{{
            formatDetailValue(detail.verification_message)
          }}</t-descriptions-item>
        </t-descriptions>
        <div class="verification-drawer-actions">
          <t-button variant="outline" @click="closeVerificationDetail">
            <template #icon><chevron-left-icon /></template>
            返回
          </t-button>
        </div>
      </t-loading>
    </t-drawer>

    <t-drawer
      v-model:visible="historyVisible"
      size="620px"
      :header="historyTitle"
      :footer="false"
      @close="closeVerificationHistory"
    >
      <t-loading :loading="historyLoading" size="small">
        <t-table row-key="id" :data="historyList" :columns="historyColumns" table-layout="fixed">
          <template #historyStatus="{ row }">
            <t-tag :theme="verificationStatusTheme(row)" variant="light">{{ verificationStatusLabel(row) }}</t-tag>
          </template>
          <template #submittedAt="{ row }">{{ formatDateTime(row.submitted_at || row.created_at) }}</template>
        </t-table>
        <div class="verification-drawer-actions">
          <t-button variant="outline" @click="closeVerificationHistory">
            <template #icon><chevron-left-icon /></template>
            返回
          </t-button>
        </div>
      </t-loading>
    </t-drawer>

    <t-dialog
      v-model:visible="rejectVisible"
      header="驳回实名"
      width="460px"
      :confirm-btn="{ content: '确认驳回', loading: Boolean(actionLoadingId) }"
      @confirm="handleReject"
    >
      <p class="verification-help">请输入驳回原因，提交后会解除当前实名认证状态。</p>
      <t-textarea v-model="rejectReason" placeholder="请输入驳回原因" :autosize="{ minRows: 3, maxRows: 6 }" />
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import { ChevronLeftIcon, SearchIcon } from 'tdesign-icons-vue-next';
import type { FormInstanceFunctions, FormRule, PageInfo, PrimaryTableCol, TableRowData } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

import type { VerificationRecord } from '@/api/admin';
import { adminApi } from '@/api/admin';
import { useUserStore } from '@/store';
import { formatDateTime } from '@/utils/format';
import { required } from '@/utils/formRules';

const userStore = useUserStore();
const route = useRoute();
const validPanes = ['list', 'manage'] as const;
type VerificationPane = (typeof validPanes)[number];
const activePane = ref<VerificationPane>(resolveRoutePane());
const listLoading = ref(false);
const feeLoading = ref(false);
const actionLoadingId = ref<number | string | null>(null);
const list = ref<VerificationRecord[]>([]);
const total = ref(0);
const page = ref(1);
const pageSize = ref(20);
const quickStatus = ref('all');
const filters = reactive({ keyword: '' });

const feeFormRef = ref<FormInstanceFunctions>();
const feeForm = reactive({
  free_attempts: 3,
  retry_fee: 2,
});

const detailVisible = ref(false);
const detailLoading = ref(false);
const detail = ref<Partial<VerificationRecord>>({});
const historyVisible = ref(false);
const historyLoading = ref(false);
const historyTitle = ref('历史记录');
const historyList = ref<VerificationRecord[]>([]);
const rejectVisible = ref(false);
const rejectRow = ref<VerificationRecord | null>(null);
const rejectReason = ref('');
const summaryConfig = reactive<Record<string, unknown>>({});

const columns: PrimaryTableCol<TableRowData>[] = [
  { title: 'ID', colKey: 'id', width: 80 },
  { title: '姓名', colKey: 'displayName', width: 140 },
  { title: '实名认证名称', colKey: 'realName', width: 160 },
  { title: '身份证号码', colKey: 'id_card_masked', width: 180 },
  { title: '认证方式', colKey: 'method', width: 120 },
  { title: '认证类型', colKey: 'verification_type_label', width: 120 },
  { title: '状态/原因', colKey: 'status', width: 220 },
  { title: '提交时间', colKey: 'createdAt', width: 180 },
  { title: '操作', colKey: 'operation', width: 220 },
];

const historyColumns: PrimaryTableCol<TableRowData>[] = [
  { title: '真实姓名', colKey: 'real_name', width: 150 },
  { title: '身份证号码', colKey: 'id_card_masked', width: 180 },
  { title: '状态', colKey: 'historyStatus', width: 150 },
  { title: '提交时间', colKey: 'submittedAt', width: 180 },
];

const pagination = computed(() => ({
  current: page.value,
  pageSize: pageSize.value,
  total: total.value,
  pageSizeOptions: [20, 50, 100],
  showJumper: true,
}));

const feeRules: Record<string, FormRule[]> = {
  free_attempts: [required('请输入免费认证次数')],
  retry_fee: [required('请输入再次认证费用')],
};

function normalizePane(value: unknown): VerificationPane | null {
  const pane = Array.isArray(value) ? value[0] : value;
  return validPanes.includes(pane as VerificationPane) ? (pane as VerificationPane) : null;
}

function resolveRoutePane(): VerificationPane {
  return normalizePane(route.query.tab) || normalizePane(route.meta.verificationPane) || 'list';
}

watch(
  () => [route.path, route.query.tab, route.meta.verificationPane],
  () => {
    activePane.value = resolveRoutePane();
  },
);

function buildListParams() {
  const params: Record<string, string | number> = {
    page: page.value,
    page_size: pageSize.value,
  };
  if (filters.keyword) params.keyword = filters.keyword;
  if (quickStatus.value === 'success') {
    params.verification_status = 2;
    params.is_verified = 1;
  } else if (quickStatus.value === 'pending') {
    params.verification_status = 1;
  } else if (quickStatus.value === 'failed') {
    params.verification_status = 3;
  }
  return params;
}

async function loadList() {
  listLoading.value = true;
  try {
    const response = await adminApi.verifications.list(buildListParams());
    list.value = response.list || [];
    total.value = Number(response.total || 0);
  } finally {
    listLoading.value = false;
  }
}

async function loadSummary() {
  try {
    const response = await adminApi.verifications.summary();
    const config = response.config || {};
    Object.keys(summaryConfig).forEach((key) => delete summaryConfig[key]);
    Object.assign(summaryConfig, config);
    feeForm.free_attempts = Number(config.free_attempts ?? 0);
    feeForm.retry_fee = Number(config.retry_fee ?? config.amount ?? 0);
  } catch {
    Object.keys(summaryConfig).forEach((key) => delete summaryConfig[key]);
    Object.assign(summaryConfig, {});
  }
}

function handleSearch() {
  page.value = 1;
  loadList();
}

function handleQuickStatusChange(value: string | number) {
  quickStatus.value = String(value || 'all');
  page.value = 1;
  loadList();
}

function handlePageChange(pageInfo: PageInfo) {
  page.value = pageInfo.current;
  pageSize.value = pageInfo.pageSize;
  loadList();
}

function verificationMethodLabel(row: VerificationRecord) {
  if (!hasVerificationRecord(row)) return '-';
  const bizCode = String(summaryConfig.verification_biz_code || 'FACE');
  const labels: Record<string, string> = {
    FACE: '人脸识别',
    CERT_PHOTO: '证照认证',
    CERT_PHOTO_FACE: '证照+人脸',
    SMART_FACE: '快捷认证',
  };
  return row.verification_method_label || labels[bizCode] || '人脸识别';
}

function hasVerificationRecord(row: VerificationRecord) {
  return Number(row.verification_status || 0) > 0 || Boolean(row.real_name) || Boolean(row.id_card_masked);
}

function verificationStatusLabel(row: VerificationRecord) {
  const status = Number(row.verification_status || 0);
  const message = normalizeVerificationMessage(row.verification_message);
  if (status === 2) return '认证成功';
  if (status === 3) return message ? `认证失败：${message}` : '认证失败';
  if (status === 5) return message ? `已驳回：${message}` : '已驳回';
  if (status === 1 || status === 4) return '待认证';
  return message || '未提交认证';
}

function verificationStatusTheme(row: VerificationRecord): 'default' | 'success' | 'primary' | 'warning' | 'danger' {
  const status = Number(row.verification_status || 0);
  if (status === 2) return 'success';
  if (status === 3 || status === 5) return 'danger';
  if (status === 1 || status === 4) return 'warning';
  return 'default';
}

function normalizeVerificationMessage(message?: string) {
  const normalized = String(message || '').trim();
  if (!normalized || normalized === '0' || normalized === '1' || normalized === '等待认证' || normalized === '待认证') {
    return '';
  }
  return normalized.toLowerCase() === 'null' ? '' : normalized;
}

function canReject(row: VerificationRecord) {
  const permissions = userStore.userInfo?.permissions || [];
  return (
    (permissions.includes('*') || permissions.includes('verification.unbind')) &&
    Number(row.verification_status || 0) === 2
  );
}

async function openDetail(row: VerificationRecord) {
  detailVisible.value = true;
  detailLoading.value = true;
  detail.value = { ...row };
  try {
    detail.value = await adminApi.verifications.detail(row.id);
  } finally {
    detailLoading.value = false;
  }
}

async function openHistory(row: VerificationRecord) {
  historyVisible.value = true;
  historyLoading.value = true;
  historyTitle.value = row.display_name ? `历史记录(${row.display_name})` : '历史记录';
  historyList.value = [];
  try {
    const response = await adminApi.verifications.history(row.id);
    historyTitle.value = response.user_name ? `历史记录(${response.user_name})` : historyTitle.value;
    historyList.value = response.list?.length ? response.list : [row];
  } finally {
    historyLoading.value = false;
  }
}

function closeVerificationDetail() {
  detailVisible.value = false;
}

function closeVerificationHistory() {
  historyVisible.value = false;
}

function openReject(row: VerificationRecord) {
  rejectRow.value = row;
  rejectReason.value = '';
  rejectVisible.value = true;
}

async function handleReject() {
  if (!rejectRow.value) return;
  const reason = rejectReason.value.trim();
  if (!reason) {
    MessagePlugin.error('请输入驳回原因');
    return;
  }

  actionLoadingId.value = rejectRow.value.id;
  try {
    await adminApi.verifications.unbind(rejectRow.value.id, { reject_reason: reason });
    MessagePlugin.success('操作成功');
    rejectVisible.value = false;
    await Promise.all([loadList(), loadSummary()]);
  } finally {
    actionLoadingId.value = null;
  }
}

async function saveFeeSettings() {
  const result = await feeFormRef.value?.validate?.();
  if (result !== true) return;
  feeLoading.value = true;
  try {
    const plugin = await resolveActiveVerificationPlugin();
    if (!plugin?.id) {
      MessagePlugin.error('请先在插件管理中安装并启用实名认证插件');
      return;
    }

    const detail = await adminApi.plugins.detail(plugin.id);
    const retryFee = Math.max(0, Number(feeForm.retry_fee || 0));
    await adminApi.plugins.updateConfig(plugin.id, {
      ...(detail.config || {}),
      free_times: Math.max(0, Number(feeForm.free_attempts || 0)),
      amount: retryFee,
      charge_enabled: retryFee > 0,
    });
    await loadSummary();
    MessagePlugin.success('费用设置已保存');
  } finally {
    feeLoading.value = false;
  }
}

async function resolveActiveVerificationPlugin() {
  const response = await adminApi.plugins.list({ domain: 'verification' });
  return (response.list || []).find((item) => item.is_enabled && item.id) || null;
}

function formatDetailValue(value?: string | number | null) {
  if (value === undefined || value === null || value === '') return '-';
  return value;
}

onMounted(() => {
  loadList();
  loadSummary();
});
</script>
<style scoped lang="less">
.verification-panel {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-l);
}

.verification-section {
  padding-top: var(--td-comp-paddingTB-l);
}

.verification-filter {
  display: grid;
  grid-template-columns: minmax(220px, 1fr) 180px;
  gap: var(--td-comp-margin-m);
  align-items: stretch;
  margin-bottom: var(--td-comp-margin-l);
}

.verification-filter > * {
  min-width: 0;
}

.verification-filter .t-button {
  min-width: 88px;
}

.verification-form-section {
  max-width: 640px;
}

.verification-help {
  margin: var(--td-comp-margin-s) 0 0;
  color: var(--td-text-color-secondary);
  font-size: var(--td-font-size-size-2, 13px);
}

.verification-mobile-list {
  display: none;
}

.verification-mobile-stack {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.verification-mobile-card {
  overflow: hidden;
  border: 1px solid var(--td-component-border);
  border-radius: var(--td-radius-default, 4px);
  background: var(--td-bg-color-container);
}

.verification-mobile-card__head {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 8px;
  min-height: 42px;
  border-bottom: 1px solid var(--td-component-border);
  padding: 9px 10px;
}

.verification-mobile-card__head strong {
  overflow: hidden;
  color: var(--td-text-color-primary);
  font-size: var(--td-font-size-size-3, 14px);
  font-weight: 600;
  line-height: 20px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.verification-mobile-card__actions {
  display: flex;
  align-items: center;
  gap: 8px;
  border-top: 1px solid var(--td-component-border);
  padding: 8px 10px;
}

.verification-mobile-card__actions .t-button {
  flex: 1 1 0;
  min-width: 0;
}

.verification-mobile-card .t-tag {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  min-width: 44px;
  padding-right: 8px;
  padding-left: 8px;
}

.verification-mobile-card__meta {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0;
  margin: 0;
  padding: 7px 10px 8px;
}

.verification-mobile-card__meta div {
  display: grid;
  grid-template-columns: 72px minmax(0, 1fr);
  align-items: center;
  min-width: 0;
  padding: 3px 0;
}

.verification-mobile-card__meta dt {
  color: var(--td-text-color-placeholder);
  font-size: var(--td-font-size-size-1, 12px);
  line-height: 20px;
}

.verification-mobile-card__meta dd {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  min-width: 0;
  margin: 0;
  overflow: hidden;
  color: var(--td-text-color-primary);
  font-size: var(--td-font-size-size-2, 13px);
  line-height: 20px;
  text-align: right;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.verification-mobile-pagination {
  margin-top: var(--td-comp-margin-l);
}

.verification-drawer-actions {
  position: sticky;
  bottom: 0;
  display: flex;
  justify-content: flex-end;
  margin: var(--td-comp-margin-l) -24px -24px;
  padding: var(--td-comp-paddingTB-m) 24px;
  border-top: 1px solid var(--td-component-border);
  background: var(--td-bg-color-container);
}

@media (max-width: 768px) {
  .verification-filter {
    grid-template-columns: minmax(140px, 1fr) 130px;
    gap: var(--td-comp-margin-s);
  }

  .verification-table {
    display: none;
  }

  .verification-mobile-list {
    display: block;
  }

  .verification-mobile-card__actions {
    flex-wrap: wrap;
    align-items: center;
  }

  .verification-mobile-card__actions .t-button {
    width: auto;
    margin-left: 0;
  }

  .verification-drawer-actions {
    margin-right: -16px;
    margin-left: -16px;
    padding-right: 16px;
    padding-left: 16px;
  }

  .verification-drawer-actions .t-button {
    width: 100%;
  }
}

@media (max-width: 360px) {
  .verification-filter {
    grid-template-columns: 1fr 110px;
  }
}
</style>
