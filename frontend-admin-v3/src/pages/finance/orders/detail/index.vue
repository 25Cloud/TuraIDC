<template>
  <div class="order-detail-page">
    <record-detail-page
      :loading="detailLoading"
      :ready="Boolean(order.id)"
      back-text="返回订单列表"
      eyebrow="订单详情"
      :title="fieldValue(order.order_no || order.id)"
      :description="`服务ID：${serviceIdLabel(order.service)}`"
      :status-label="orderStatusLabel(order.status)"
      :status-theme="orderStatusTheme(order.status)"
      :metrics="summaryMetrics"
      :tabs="tabs"
      :active-tab="activeTab"
      empty-text="订单不存在"
      @back="goBack"
      @refresh="loadDetail"
      @update:active-tab="(value) => (activeTab = value)"
    >
      <template #relations>
        <t-button v-if="order.invoice_id" variant="outline" size="small" @click="openInvoiceDetail(order.invoice_id)">
          查看账单详情
        </t-button>
        <t-button
          v-if="order.user_id"
          variant="outline"
          size="small"
          @click="router.push(`/admin/users/${order.user_id}`)"
        >
          查看用户详情
        </t-button>
      </template>

      <template #tab-basic>
        <section class="order-detail-section">
          <h4>订单信息</h4>
          <div class="detail-kv-grid detail-kv-grid--two">
            <div class="detail-kv-item detail-kv-item--span-2">
              <span>订单号</span>
              <strong>{{ fieldValue(order.order_no) }}</strong>
            </div>
            <div class="detail-kv-item">
              <span>订单类型</span>
              <strong>{{ order.type_label || orderTypeLabel(order.type) }}</strong>
            </div>
            <div class="detail-kv-item">
              <span>状态</span>
              <t-tag :theme="orderStatusTheme(order.status)" variant="light">
                {{ orderStatusLabel(order.status) }}
              </t-tag>
            </div>
            <div class="detail-kv-item">
              <span>数量</span>
              <strong>{{ order.quantity || 1 }}</strong>
            </div>
            <div class="detail-kv-item">
              <span>计费周期</span>
              <strong>{{ fieldValue(order.billing_cycle) }}</strong>
            </div>
            <div class="detail-kv-item">
              <span>订单金额</span>
              <strong>{{ formatMoney(order.amount) }}</strong>
            </div>
            <div class="detail-kv-item">
              <span>优惠金额</span>
              <strong>{{ formatMoney(order.discount) }}</strong>
            </div>
            <div class="detail-kv-item">
              <span>实付金额</span>
              <strong>{{ formatMoney(order.paid_amount) }}</strong>
            </div>
            <div class="detail-kv-item">
              <span>支付时间</span>
              <strong>{{ formatDateTime(order.paid_at) }}</strong>
            </div>
            <div class="detail-kv-item">
              <span>创建时间</span>
              <strong>{{ formatDateTime(order.created_at) }}</strong>
            </div>
            <div class="detail-kv-item">
              <span>更新时间</span>
              <strong>{{ formatDateTime(order.updated_at) }}</strong>
            </div>
            <div class="detail-kv-item detail-kv-item--span-2">
              <span>链路追踪</span>
              <strong>{{ fieldValue(order.trace_id) }}</strong>
            </div>
            <div v-if="order.remark" class="detail-kv-item detail-kv-item--span-2">
              <span>备注</span>
              <strong>{{ order.remark }}</strong>
            </div>
          </div>
        </section>

        <section class="order-detail-section">
          <h4>关联信息</h4>
          <div class="detail-kv-grid detail-kv-grid--two">
            <div class="detail-kv-item">
              <span>用户 ID</span>
              <strong>{{ order.user_id || '-' }}</strong>
            </div>
            <div class="detail-kv-item">
              <span>用户</span>
              <strong>{{ userName(order.user) }}</strong>
            </div>
            <div class="detail-kv-item detail-kv-item--span-2">
              <span>账单号</span>
              <div class="detail-inline-action">
                <strong>{{ fieldValue(order.invoice?.invoice_no) }}</strong>
                <t-button
                  v-if="order.invoice_id"
                  size="small"
                  variant="text"
                  theme="primary"
                  @click="openInvoiceDetail(order.invoice_id)"
                >
                  查看
                </t-button>
              </div>
            </div>
            <div class="detail-kv-item">
              <span>账单金额</span>
              <strong>{{ formatMoney(order.invoice?.amount) }}</strong>
            </div>
            <div class="detail-kv-item">
              <span>账单支付时间</span>
              <strong>{{ formatDateTime(order.invoice?.paid_at) }}</strong>
            </div>
            <div class="detail-kv-item detail-kv-item--span-2">
              <span>账单链路追踪</span>
              <strong>{{ fieldValue(order.invoice?.trace_id) }}</strong>
            </div>
            <div class="detail-kv-item detail-kv-item--span-2">
              <span>服务 ID</span>
              <button
                type="button"
                class="user-link"
                :disabled="!Number(toRecord(order.service).id || 0)"
                @click="goServiceDetail"
              >
                <strong>{{ serviceIdLabel(order.service) }}</strong>
              </button>
            </div>
            <div class="detail-kv-item detail-kv-item--span-2">
              <span>服务到期</span>
              <strong>{{ formatDateTime(order.service?.expires_at) }}</strong>
            </div>
          </div>
        </section>

        <section v-if="couponInfo" class="order-detail-section">
          <h4>优惠券信息</h4>
          <div class="detail-kv-grid detail-kv-grid--two">
            <div class="detail-kv-item detail-kv-item--span-2">
              <span>优惠码</span>
              <strong>{{ fieldValue(couponInfo.code) }}</strong>
            </div>
            <div v-if="couponInfo.name" class="detail-kv-item">
              <span>优惠券名称</span>
              <strong>{{ fieldValue(couponInfo.name) }}</strong>
            </div>
            <div v-if="couponInfo.type" class="detail-kv-item">
              <span>类型</span>
              <strong>{{ fieldValue(couponInfo.type) }}</strong>
            </div>
            <div v-if="couponInfo.value" class="detail-kv-item detail-kv-item--span-2">
              <span>面值</span>
              <strong>{{ fieldValue(couponInfo.value) }}</strong>
            </div>
          </div>
        </section>
      </template>

      <template #tab-product>
        <section class="order-detail-section">
          <h4>产品信息</h4>
          <div class="detail-kv-grid detail-kv-grid--two">
            <div class="detail-kv-item detail-kv-item--span-2">
              <span>分类链路</span>
              <strong>{{ fieldValue(order.product_full_path || order.product_name) }}</strong>
            </div>
          </div>
        </section>

        <section v-if="configItems.length" class="order-detail-section">
          <h4>配置快照</h4>
          <div class="config-list">
            <div v-for="item in configItems" :key="item.label" class="config-item">
              <span>{{ item.label }}</span>
              <strong>{{ item.value }}</strong>
            </div>
          </div>
        </section>

        <section v-if="pricingItems.length" class="order-detail-section">
          <h4>配置定价</h4>
          <div class="config-list">
            <div v-for="item in pricingItems" :key="item.label" class="config-item">
              <span>{{ item.label }}</span>
              <strong>{{ item.value }}</strong>
            </div>
          </div>
        </section>

        <section v-if="serviceSnapshotItems.length" class="order-detail-section">
          <h4>实例快照</h4>
          <div class="config-list">
            <div v-for="item in serviceSnapshotItems" :key="item.label" class="config-item">
              <span>{{ item.label }}</span>
              <strong>{{ item.value }}</strong>
            </div>
          </div>
        </section>
      </template>

      <template #tab-payments>
        <section class="order-detail-section">
          <h4>支付记录</h4>
          <div class="payment-list">
            <div v-for="payment in payments" :key="String(payment.id || payment.payment_no)" class="payment-item">
              <div class="payment-item__head">
                <div>
                  <span>支付单号</span>
                  <strong>{{ fieldValue(payment.payment_no) }}</strong>
                </div>
                <t-tag :theme="paymentStatusTheme(payment)" variant="light">{{ paymentStatusLabel(payment) }}</t-tag>
              </div>
              <div class="detail-kv-grid detail-kv-grid--two">
                <div class="detail-kv-item">
                  <span>支付方式</span>
                  <strong>{{ fieldValue(payment.gateway) }}</strong>
                </div>
                <div class="detail-kv-item detail-kv-item--span-2">
                  <span>第三方单号</span>
                  <strong>{{ fieldValue(payment.trade_no) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>金额</span>
                  <strong>{{ formatMoney(payment.amount) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>支付时间</span>
                  <strong>{{ formatDateTime(payment.paid_at || payment.created_at) }}</strong>
                </div>
                <div class="detail-kv-item detail-kv-item--span-2">
                  <span>链路追踪</span>
                  <strong>{{ fieldValue(payment.trace_id) }}</strong>
                </div>
              </div>
            </div>
          </div>
        </section>
      </template>
    </record-detail-page>

    <invoice-detail-drawer
      v-model:visible="invoiceDrawer.visible"
      :loading="invoiceDrawer.loading"
      :invoice="currentInvoice"
      :payments="invoicePayments"
      :items="invoiceItems"
      :logs="invoiceLogs"
      :status-label="invoiceStatusLabel(currentInvoice.status)"
      :status-theme="invoiceStatusTheme(currentInvoice.status)"
      @close="closeInvoiceDetail"
      @refresh="reloadInvoiceDetail"
      @view-order="(id) => id && router.push(`/admin/finance/orders/${id}`)"
      @view-user="(id) => id && router.push(`/admin/users/${id}`)"
      @view-service="(id) => viewLinkedService(id)"
    />
  </div>
</template>
<script setup lang="ts">
import './index.less';

import {
  getStatusLabel,
  getStatusTagType,
  INVOICE_STATUS_MAP,
  ORDER_STATUS_MAP,
  ORDER_TYPE_MAP,
  PAYMENT_STATUS_MAP,
  toLabelMap,
  toTagTypeMap,
} from '@shared/statusConfig';
import type { TagProps } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import type { InvoiceRecord, OrderRecord } from '@/api/admin';
import { adminApi } from '@/api/admin';
import InvoiceDetailDrawer from '@/components/finance-record-detail/InvoiceDetailDrawer.vue';
import type { RecordDetailMetric, RecordDetailTab } from '@/components/record-detail-page/index.vue';
import RecordDetailPage from '@/components/record-detail-page/index.vue';
import { fieldValue, formatDateTime, formatMoney } from '@/utils/format';
import { errorMessage } from '@/utils/userMessage';

defineOptions({ name: 'AdminFinanceOrderDetail' });

type ThemeType = NonNullable<TagProps['theme']>;

const SNAPSHOT_LABEL_MAP: Record<string, string> = {
  // ── 产品配置 ──
  bw: '带宽',
  in_bw: '下行带宽',
  out_bw: '上行带宽',
  os: '操作系统',
  cpu: 'CPU',
  area: '区域',
  region: '区域',
  node: '节点',
  node_group: '节点分组',
  ip: 'IP数量',
  ip_num: 'IP数量',
  ipv6_num: 'IPv6数量',
  memory: '内存',
  hostname: '主机名',
  quantity: '数量',
  instance_id: '实例ID',
  // ── 金额 ──
  setup_fee: '初装费',
  base_amount: '基础金额',
  total_amount: '合计金额',
  config_amount: '配置金额',
  subtotal_amount: '小计金额',
  discount_amount: '优惠金额',
  amount: '金额',
  price: '价格',
  pricing: '价格',
  // ── 通用 ──
  items: '配置项',
  meta: '扩展信息',
  configoption: '配置参数',
  kind: '类型',
  mode: '模式',
  source_type: '来源类型',
  created_by: '创建者',
  source: '来源',
  remark: '备注',
  // ── 产品/服务 ──
  target_label: '目标服务',
  target_service_id: '目标服务ID',
  product_id: '产品ID',
  product_name: '产品名称',
  product_full_path: '产品路径',
  product_path_segments: '产品路径段',
  first_product_group_name: '一级分组',
  second_product_group_name: '二级分组',
  third_product_group_name: '三级分组',
  // ── 计费周期 ──
  billing_cycle: '周期',
  billingcycle: '周期',
  billingcycle_zh: '周期',
  period: '周期',
  // ── 续费 ──
  renew_service_id: '续费服务ID',
  renew_service_name: '续费服务名称',
  auto_renew: '自动续费',
  auto_renew_trace_id: '自动续费追踪',
  local_renew_amount: '本地续费金额',
  // ── 上游供应商 ──
  upstream_host_id: '上游主机ID',
  upstream_host_ids: '上游主机列表',
  upstream_invoice_id: '上游账单ID',
  upstream_product_id: '上游产品ID',
  upstream_product_name: '上游产品名称',
  upstream_amount: '上游金额',
  upstream_status: '上游状态',
  supports_upstream: '支持上游开通',
  provider_key: '供应商标识',
  supplier_id: '供应商ID',
  // ── 开通 ──
  requested_host: '请求主机名',
  dedicated_ip: '独立IP',
  assigned_ips: '分配IP',
  host_config_option: '主机配置',
  connection_secret: '连接信息',
  connection_cached_at: '连接缓存时间',
  last_provisioned_at: '开通时间',
  last_provision_attempt_at: '开通尝试时间',
  provision_error: '开通失败原因',
};

const route = useRoute();
const router = useRouter();
const detailLoading = ref(false);
const order = ref<OrderRecord>({} as OrderRecord);
const activeTab = ref('basic');

const orderStatusLabelMap = toLabelMap(ORDER_STATUS_MAP);
const orderStatusTypeMap = toTagTypeMap(ORDER_STATUS_MAP);
const invoiceStatusLabelMap = toLabelMap(INVOICE_STATUS_MAP);
const invoiceStatusTypeMap = toTagTypeMap(INVOICE_STATUS_MAP);

const invoiceDrawer = reactive({
  visible: false,
  loading: false,
  currentId: 0,
  detail: { invoice: {}, payments: [], items: [], logs: [] } as {
    invoice: InvoiceRecord;
    payments: Record<string, unknown>[];
    items: Record<string, unknown>[];
    logs: Record<string, unknown>[];
  },
});
const payments = computed<Record<string, unknown>[]>(() => {
  const list = (order.value as Record<string, unknown>).payments;
  return Array.isArray(list) ? list : [];
});

const summaryMetrics = computed<RecordDetailMetric[]>(() => [
  { label: '订单金额', value: formatMoney(order.value.amount), primary: true },
  { label: '实付金额', value: formatMoney(order.value.paid_amount) },
  { label: '创建时间', value: formatDateTime(order.value.created_at) },
]);

const tabs = computed<RecordDetailTab[]>(() => [
  { value: 'basic', label: '基本信息' },
  { value: 'product', label: '产品配置' },
  { value: 'payments', label: '支付记录', show: payments.value.length > 0 },
]);

const couponInfo = computed(() => {
  const raw = order.value as Record<string, unknown>;
  const coupon = raw.coupon as Record<string, unknown> | null | undefined;
  const code = String(raw.coupon_code || coupon?.code || '');
  if (!coupon && !code) return null;
  return {
    code,
    name: coupon?.name as string | undefined,
    type: coupon?.type as string | undefined,
    value: coupon?.value as string | undefined,
  };
});

const configItems = computed(() => {
  const snapshot = order.value.config_snapshot;
  if (!snapshot || typeof snapshot !== 'object') return [];
  return flattenSnapshot(snapshot as Record<string, unknown>, configValueLabelMap.value);
});

const pricingItems = computed(() => {
  const snapshot = order.value.config_pricing_snapshot;
  if (!snapshot || typeof snapshot !== 'object') return [];
  return flattenSnapshot(snapshot as Record<string, unknown>);
});

// 实例快照仅在「新购」订单写入，作为开通时实例的存档展示。
const isNewOrder = computed(() => order.value.type === 'new');

const serviceSnapshotItems = computed(() => {
  if (!isNewOrder.value) return [];
  const snapshot = order.value.service_snapshot;
  if (!snapshot || typeof snapshot !== 'object') return [];
  return flattenSnapshot(snapshot as Record<string, unknown>);
});

const configValueLabelMap = computed<Record<string, string>>(() => {
  const snapshot = order.value.config_pricing_snapshot as Record<string, unknown> | null | undefined;
  const items = Array.isArray(snapshot?.items) ? snapshot.items : [];
  return items.reduce(
    (result, item) => {
      const record = toRecord(item);
      const field = String(record.field || '').trim();
      const label = String(
        record.value_label || record.suboption_name || record.option_name || record.value || '',
      ).trim();
      if (field && label) result[field] = label;
      return result;
    },
    {} as Record<string, string>,
  );
});

const currentInvoice = computed(() => invoiceDrawer.detail.invoice || ({} as InvoiceRecord));
const invoicePayments = computed(() => invoiceDrawer.detail.payments || []);
const invoiceLogs = computed(() => invoiceDrawer.detail.logs || []);
const invoiceItems = computed(() => {
  const sceneItems = currentInvoice.value.scene?.items;
  if (Array.isArray(sceneItems)) return sceneItems as Record<string, unknown>[];
  return invoiceDrawer.detail.items || [];
});

function flattenSnapshot(
  obj: Record<string, unknown>,
  valueLabelMap: Record<string, string> = {},
): { label: string; value: string }[] {
  const result: { label: string; value: string }[] = [];
  for (const [key, val] of Object.entries(obj)) {
    if (['unit_setup_fee', 'unit_base_amount', 'unit_total_amount', 'unit_config_amount'].includes(key)) continue;
    if (key.startsWith('_')) continue;
    if (val === null || val === undefined || val === '') continue;
    if (key === 'connection_secret') continue;
    if (key === 'items' && Array.isArray(val)) {
      val.forEach((item, index) => {
        const record = toRecord(item);
        result.push({
          label: snapshotLabel(
            record.label || record.name || record.option_name || record.spec_key || `${key}.${index + 1}`,
          ),
          value: formatSnapshotItem(record),
        });
      });
      continue;
    }
    if (val && typeof val === 'object' && !Array.isArray(val)) {
      const nested = flattenSnapshot(val as Record<string, unknown>, valueLabelMap);
      nested.forEach((item) => result.push({ label: `${snapshotLabel(key)} / ${item.label}`, value: item.value }));
    } else {
      result.push({ label: snapshotLabel(key), value: formatSnapshotValue(valueLabelMap[key] || val, key) });
    }
  }
  return result;
}

function snapshotLabel(value: unknown) {
  const key = String(value || '').trim();
  if (!key) return '-';
  return SNAPSHOT_LABEL_MAP[key] || key;
}

function formatSnapshotItem(record: Record<string, unknown>) {
  const value = fieldValue(
    record.value_label ||
      record.option_value_label ||
      record.suboption_label ||
      record.value ||
      record.option_value ||
      record.suboption_name ||
      record.suboption_name_first ||
      record.option_name_first ||
      record.version ||
      record.label ||
      record.name,
  );
  const amount = record.amount ?? record.total_amount ?? record.price ?? record.pricing ?? record.fee;
  const setupFee = record.setup_fee ?? record.setupfee;
  const parts = [value];
  if (amount !== null && amount !== undefined && amount !== '') parts.push(`金额 ${formatMoney(amount)}`);
  if (setupFee !== null && setupFee !== undefined && setupFee !== '') parts.push(`初装费 ${formatMoney(setupFee)}`);
  return parts.join(' / ');
}

function formatSnapshotValue(value: unknown, key = ''): string {
  if (value === null || value === undefined || value === '') return '-';
  if (Array.isArray(value)) {
    return value
      .map((item, index) => {
        if (item && typeof item === 'object')
          return `${index + 1}. ${formatSnapshotItem(item as Record<string, unknown>)}`;
        return fieldValue(item);
      })
      .join('；');
  }
  if (value && typeof value === 'object') {
    const record = value as Record<string, unknown>;
    return Object.entries(record)
      .filter(([, childValue]) => childValue !== null && childValue !== undefined && childValue !== '')
      .map(([childKey, childValue]) => `${snapshotLabel(childKey)}：${formatSnapshotValue(childValue, childKey)}`)
      .join('；');
  }
  const raw = String(value);
  if (['bw', 'in_bw', 'out_bw'].includes(key) && /^\d+(?:\.\d+)?$/.test(raw)) return `${raw} Mbps`;
  if (key === 'memory' && /^\d+(?:\.\d+)?$/.test(raw)) return `${raw} MB`;
  if (['ip_num', 'ipv6_num', 'quantity'].includes(key) && /^\d+(?:\.\d+)?$/.test(raw)) return `${raw} 个`;
  return raw;
}

async function loadDetail() {
  const id = route.params.id as string;
  if (!id) return;
  detailLoading.value = true;
  try {
    const response = await adminApi.orders.detail(id);
    order.value = response;
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载订单详情失败'));
  } finally {
    detailLoading.value = false;
  }
}

function goBack() {
  router.push('/admin/finance/orders');
}

async function openInvoiceDetail(id: unknown) {
  if (!id) return;
  invoiceDrawer.visible = true;
  invoiceDrawer.currentId = Number(id);
  invoiceDrawer.detail = {
    invoice: order.value.invoice ? ({ ...order.value.invoice } as InvoiceRecord) : {},
    payments: [],
    items: [],
    logs: [],
  };
  await reloadInvoiceDetail();
}

async function reloadInvoiceDetail() {
  if (!invoiceDrawer.currentId) return;
  invoiceDrawer.loading = true;
  try {
    const response = await adminApi.invoices.detail(invoiceDrawer.currentId);
    invoiceDrawer.detail = normalizeInvoiceDetail(response, currentInvoice.value);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载账单详情失败'));
  } finally {
    invoiceDrawer.loading = false;
  }
}

function closeInvoiceDetail() {
  invoiceDrawer.visible = false;
  invoiceDrawer.currentId = 0;
  invoiceDrawer.detail = { invoice: {}, payments: [], items: [], logs: [] };
}

function normalizeInvoiceDetail(payload: Record<string, unknown> = {}, fallback: InvoiceRecord = {}) {
  const invoice =
    payload.invoice && typeof payload.invoice === 'object'
      ? (payload.invoice as InvoiceRecord)
      : (payload as InvoiceRecord);
  return {
    invoice: {
      ...fallback,
      ...invoice,
      payment_summary: { ...(fallback.payment_summary || {}), ...(invoice.payment_summary || {}) },
      order: invoice.order || fallback.order || null,
      product: invoice.product || fallback.product || null,
      scene: invoice.scene || fallback.scene || {},
    },
    payments: Array.isArray(payload.payments) ? (payload.payments as Record<string, unknown>[]) : [],
    items: Array.isArray(payload.items) ? (payload.items as Record<string, unknown>[]) : [],
    logs: Array.isArray(payload.logs) ? (payload.logs as Record<string, unknown>[]) : [],
  };
}

function orderTypeLabel(type: unknown) {
  return ORDER_TYPE_MAP[String(type || '')] || fieldValue(type);
}

function orderStatusLabel(status: unknown) {
  return orderStatusLabelMap[String(status ?? '')] || fieldValue(status);
}

function orderStatusTheme(status: unknown): ThemeType {
  const value = orderStatusTypeMap[String(status ?? '')] || 'default';
  if (value === 'warning' || value === 'success' || value === 'primary' || value === 'danger') return value;
  return 'default';
}

function invoiceStatusLabel(status: unknown) {
  return invoiceStatusLabelMap[String(status ?? '')] || fieldValue(status);
}

function invoiceStatusTheme(status: unknown): ThemeType {
  const value = invoiceStatusTypeMap[String(status ?? '')] || 'default';
  if (value === 'warning' || value === 'success' || value === 'primary' || value === 'danger') return value;
  return 'default';
}

function paymentStatusLabel(payment: Record<string, unknown>) {
  return getStatusLabel(PAYMENT_STATUS_MAP, Number(payment.status));
}

function paymentStatusTheme(payment: Record<string, unknown>): ThemeType {
  const value = getStatusTagType(PAYMENT_STATUS_MAP, Number(payment.status));
  if (value === 'warning' || value === 'success' || value === 'primary' || value === 'danger') return value;
  return 'default';
}

function userName(user: unknown) {
  const record = toRecord(user);
  return fieldValue(record.nickname || record.display_name || record.email);
}

function serviceIdLabel(service: unknown) {
  const record = toRecord(service);
  return fieldValue(record.service_id || record.id);
}

function goServiceDetail() {
  const service = toRecord(order.value.service);
  const id = Number(service.id || service.service_id || 0);
  if (!id) return;
  router.push({
    path: `/admin/services/${id}`,
    query: { user: String(order.value.user_id || (toRecord(order.value.user).id as number) || '') },
  });
}

function viewLinkedService(id: unknown) {
  if (!Number(id || 0)) return;
  router.push({
    path: `/admin/services/${Number(id)}`,
    query: { user: String(order.value.user_id || (toRecord(order.value.user).id as number) || '') },
  });
}

function toRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}

onMounted(loadDetail);
</script>
