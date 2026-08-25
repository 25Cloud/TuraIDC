<template>
  <section class="client-dashboard">
    <loading-state :loading="loading" text="正在加载控制台数据">
      <div class="summary-grid">
        <t-card class="account-card dashboard-card" :bordered="false">
          <div class="account-card__user">
            <t-avatar style="margin-top: 5px" :image="avatarUrl || undefined" size="large">{{ avatarText }}</t-avatar>
            <div class="account-card__main">
              <h2>{{ greetingText }}，{{ displayName }}</h2>
              <p>{{ todayDateText }}</p>
              <div class="account-card__tags">
                <t-tag :theme="isVerified ? 'success' : 'warning'" variant="light">{{
                  isVerified ? '已实名' : '未实名'
                }}</t-tag>
                <t-tag :theme="isPhoneBound ? 'success' : 'default'" variant="light">{{
                  isPhoneBound ? '已绑手机' : '未绑手机'
                }}</t-tag>
                <t-tag :theme="isEmailBound ? 'success' : 'default'" variant="light">{{
                  isEmailBound ? '已绑邮箱' : '未绑邮箱'
                }}</t-tag>
                <t-tag v-if="agentGroupName" theme="warning" variant="outline" class="account-card__agent-tag">
                  {{ agentGroupName }}
                </t-tag>
              </div>
            </div>
          </div>
          <div class="account-card__actions">
            <t-button theme="primary" @click="router.push('/client/recharge')">
              <template #icon><wallet-icon /></template>
              在线充值
            </t-button>
            <t-button variant="outline" @click="router.push('/client/payments')">
              <template #icon><file-icon /></template>
              充值记录
            </t-button>
          </div>
        </t-card>

        <t-card
          v-for="item in summaryCards"
          :key="item.key"
          class="metric-card dashboard-card"
          :bordered="false"
          role="region"
          :aria-label="`${item.label}：${item.value} ${item.unit}，${item.note}`"
        >
          <div class="metric-card__label">{{ item.label }}</div>
          <div class="metric-card__value" :class="{ 'is-warning': item.warning }">
            {{ item.value }}
            <span>{{ item.unit }}</span>
          </div>
          <div class="metric-card__note">{{ item.note }}</div>
        </t-card>
      </div>

      <div class="dashboard-grid">
        <main class="dashboard-main">
          <t-card class="dashboard-card" :bordered="false">
            <template #title>我的产品</template>
            <template #actions>
              <t-button theme="primary" variant="text" @click="router.push('/client/services')">管理全部</t-button>
            </template>

            <div class="product-grid">
              <router-link
                v-for="item in productCards"
                :key="item.key"
                class="product-tile"
                :to="`/client/services?catalog_type=${item.key}`"
              >
                <span class="product-tile__icon" :class="item.tone">
                  <component :is="item.icon" />
                </span>
                <span class="product-tile__content">
                  <strong>{{ item.title }}</strong>
                  <span>{{ item.countText }}</span>
                </span>
              </router-link>
            </div>
          </t-card>

          <div class="chart-grid">
            <t-card class="dashboard-card chart-card" :bordered="false">
              <template #title>今年消费</template>
              <template #actions>
                <div class="chart-card__actions">
                  <span class="card-meta">{{ currentYearLabel }}</span>
                  <span class="chart-summary">
                    合计 <strong>{{ formatMoney(currentYearConsumptionTotal) }} 元</strong>
                  </span>
                </div>
              </template>

              <t-loading :loading="!invoicesLoaded" text="加载中">
                <div v-if="monthlyBarsHasData" class="bar-chart bar-chart--monthly" aria-label="今年月度消费">
                  <t-tooltip
                    v-for="bar in monthlyBars"
                    :key="bar.key"
                    :content="bar.tooltip"
                    placement="top"
                    show-arrow
                  >
                    <div
                      class="bar-chart__slot"
                      tabindex="0"
                      role="img"
                      :aria-label="`${bar.label}：${bar.amountLabel ? `¥${bar.amountLabel}` : '无消费'}`"
                    >
                      <div class="bar-chart__col" :style="{ height: bar.height }">
                        <span v-if="bar.amountLabel" class="bar-chart__value">¥{{ bar.amountLabel }}</span>
                        <span class="bar-chart__bar"></span>
                      </div>
                      <span class="bar-chart__label">{{ bar.label }}</span>
                    </div>
                  </t-tooltip>
                </div>
                <t-empty v-else description="暂无今年消费数据" />
              </t-loading>
            </t-card>

            <t-card class="dashboard-card chart-card" :bordered="false">
              <template #title>近 7 天每日消费</template>
              <template #actions>
                <div class="chart-card__actions">
                  <span class="chart-summary">
                    合计 <strong>{{ formatMoney(last7DaysConsumptionTotal) }} 元</strong>
                  </span>
                  <t-button theme="primary" variant="text" @click="router.push('/client/payments')">消费明细</t-button>
                </div>
              </template>

              <t-loading :loading="!balanceLogsLoaded" text="加载中">
                <div v-if="dailyBarsHasData" class="bar-chart bar-chart--daily" aria-label="近 7 天每日消费">
                  <t-tooltip v-for="bar in dailyBars" :key="bar.date" :content="bar.tooltip" placement="top" show-arrow>
                    <div
                      class="bar-chart__slot"
                      tabindex="0"
                      role="img"
                      :aria-label="`${bar.label} · 消费 ¥${formatMoney(bar.amount)}`"
                    >
                      <span class="bar-chart__bar" :style="{ height: bar.height }"></span>
                      <span v-if="bar.showLabel" class="bar-chart__label">{{ bar.label }}</span>
                    </div>
                  </t-tooltip>
                </div>
                <t-empty v-else description="暂无消费记录" />
              </t-loading>
            </t-card>
          </div>

          <t-card class="dashboard-card" :bordered="false">
            <template #title>消息中心</template>
            <template #actions>
              <t-button
                theme="primary"
                variant="text"
                @click="router.push(activeNoticeTab === 'notice' ? '/client/notices' : '/client/help')"
              >
                查看全部
              </t-button>
            </template>

            <t-tabs v-model="activeNoticeTab" class="message-tabs">
              <t-tab-panel value="notice" label="新闻公告">
                <div v-if="recentNotices.length" class="message-list">
                  <router-link
                    v-for="item in recentNotices"
                    :key="item.id"
                    class="message-row"
                    :to="`/client/notices/${item.id}`"
                  >
                    <span class="message-row__title">{{ item.title }}</span>
                    <span class="message-row__time">{{ formatDate(item.publish_at || item.created_at) }}</span>
                  </router-link>
                </div>
                <t-empty v-else description="暂无公告" />
              </t-tab-panel>
              <t-tab-panel value="help" label="帮助中心">
                <div v-if="recentHelpArticles.length" class="message-list">
                  <router-link
                    v-for="item in recentHelpArticles"
                    :key="item.id"
                    class="message-row"
                    :to="`/client/help/${item.id}`"
                  >
                    <span class="message-row__title">{{ item.title }}</span>
                    <span class="message-row__time">{{ formatDate(item.publish_at || item.created_at) }}</span>
                  </router-link>
                </div>
                <t-empty v-else description="暂无帮助内容" />
              </t-tab-panel>
            </t-tabs>
          </t-card>
        </main>

        <aside class="dashboard-aside">
          <t-card class="dashboard-card" :bordered="false">
            <template #title>待办事项</template>
            <div class="todo-list">
              <router-link
                v-for="item in todoItems"
                :key="item.key"
                class="todo-row"
                :to="item.path"
                :aria-label="item.count > 0 ? `${item.label}：${item.count} 项，需要处理` : `${item.label}：0 项`"
              >
                <span class="todo-row__left">
                  <span class="todo-row__icon" :class="item.tone"><component :is="item.icon" /></span>
                  <span>{{ item.label }}</span>
                </span>
                <span class="todo-row__count" :class="{ 'is-alert': item.count > 0 }">
                  <error-circle-icon v-if="item.count > 0" class="todo-row__alert-icon" aria-hidden="true" />
                  {{ item.count }}
                </span>
              </router-link>
            </div>
          </t-card>

          <t-card class="dashboard-card" :bordered="false">
            <template #title>快捷入口</template>
            <div class="quick-grid">
              <router-link v-for="item in quickLinks" :key="item.path" :to="item.path" class="quick-link">
                <component :is="item.icon" />
                <span>{{ item.label }}</span>
              </router-link>
            </div>
          </t-card>

          <t-card class="dashboard-card" :bordered="false">
            <template #title>{{ supportGroupTitle }}</template>
            <div class="support-box">
              <img v-if="supportQr" :src="supportQr" alt="QQ 群二维码" class="support-box__qr" />
              <div v-else class="support-box__qr support-box__qr--empty">{{ siteBranding.brandInitials }}</div>
              <div class="support-box__content">
                <strong>{{ supportGroupText }}</strong>
                <span>群号：{{ supportPhoneText }}</span>
                <t-button
                  v-if="supportGroupLink"
                  theme="primary"
                  size="small"
                  :href="supportGroupLink"
                  target="_blank"
                  variant="outline"
                >
                  加入群聊
                </t-button>
              </div>
            </div>
          </t-card>
        </aside>
      </div>
    </loading-state>
  </section>
</template>
<script setup lang="ts">
import LoadingState from '@shared/user-v3/components/LoadingState.vue';
import {
  CouponIcon,
  DashboardIcon,
  ErrorCircleIcon,
  FileIcon,
  GiftIcon,
  HelpCircleIcon,
  MoneyIcon,
  NotificationIcon,
  ServerIcon,
  ServiceIcon,
  UserSafetyIcon,
  WalletIcon,
} from 'tdesign-icons-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import type { Component } from 'vue';
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';

import clientApi from '@/api/client';
import { useSiteBrandingStore } from '@/app/stores/siteBranding';
import { useNoticeReadStatus } from '@/domains/content/useNoticeReadStatus';
import { useUserStore } from '@/store';
import type {
  BalanceLog,
  ClientUserInfo,
  ContentArticleRecord,
  CouponSummary,
  FinanceLedgerSummary,
  InvoiceRecord,
  ReferralOverviewPayload,
  ServiceOverviewGroup,
  ServiceOverviewPayload,
  TicketRecord,
} from '@/types/client';
import { getErrorMessage } from '@/utils/error';
import { formatMoney, formatShortDateTime as formatDate } from '@/utils/format';

interface ProductCard {
  key: string;
  title: string;
  countText: string;
  count: number;
  path: string;
  primaryServiceId: number;
  icon: Component;
  tone: string;
}

const router = useRouter();
const userStore = useUserStore();
const siteBranding = useSiteBrandingStore();
const { unreadCount, fetchUnreadCount } = useNoticeReadStatus();

const loading = ref(false);
const activeNoticeTab = ref<'notice' | 'help'>('notice');
const balanceLogsDaily = ref<BalanceLog[]>([]);
const paidInvoices = ref<InvoiceRecord[]>([]);
const balanceLogsLoaded = ref(false);
const invoicesLoaded = ref(false);
const recentNotices = ref<ContentArticleRecord[]>([]);
const recentHelpArticles = ref<ContentArticleRecord[]>([]);
const recentTickets = ref<TicketRecord[]>([]);
const financeSummary = ref<FinanceLedgerSummary>({
  cash_balance: '0.00',
  total_out: '0.00',
  recharge_in: '0.00',
  invoice_payment_out: '0.00',
  unpaid_count: 0,
  unpaid_amount: '0.00',
  total_invoices: 0,
});
const couponSummary = ref<CouponSummary>({
  total: 0,
  available: 0,
  used_up: 0,
  expired: 0,
});
const serviceOverview = ref<ServiceOverviewPayload>({
  total: 0,
  category_total: 0,
  list: [],
  catalog_types: [],
});

function isHandledError(error: unknown): error is { __handled: boolean } {
  return typeof error === 'object' && error !== null && '__handled' in error && Boolean(error.__handled);
}

function toNumber(value: unknown) {
  const normalized = Number(value);
  return Number.isFinite(normalized) ? normalized : 0;
}

function toDateText(value: Date) {
  const year = value.getFullYear();
  const month = String(value.getMonth() + 1).padStart(2, '0');
  const day = String(value.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function last7DaysRange() {
  const now = new Date();
  const start = new Date(now);
  start.setDate(start.getDate() - 6);
  return {
    start_date: toDateText(start),
    end_date: toDateText(now),
  };
}

function currentMonthRange() {
  const now = new Date();
  const start = new Date(now.getFullYear(), now.getMonth(), 1);
  const end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
  return {
    start_date: toDateText(start),
    end_date: toDateText(end),
  };
}

function resolvePagedList<T>(payload: { list?: T[] } | T[] | null | undefined): T[] {
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.list)) return payload.list;
  return [];
}

function resolveProductMeta(item: ServiceOverviewGroup, key: string) {
  const title = String(item.title || item.name || item.product_type_label || '').toLowerCase();
  if (key === 'cloud_server' || title.includes('云服务器')) return { icon: ServerIcon, tone: 'is-brand' };
  if (key === 'physical_machine' || key === 'bare_metal' || title.includes('物理机') || title.includes('裸金属')) {
    return { icon: DashboardIcon, tone: 'is-success' };
  }
  if (key === 'game_cloud' || title.includes('游戏云')) return { icon: DashboardIcon, tone: 'is-info' };
  if (key === 'cloud_desktop' || title.includes('云电脑')) return { icon: HelpCircleIcon, tone: 'is-info' };
  if (key === 'web_hosting' || title.includes('虚拟主机')) return { icon: ServiceIcon, tone: 'is-warning' };
  if (key === 'cdn' || title.includes('CDN')) return { icon: ServiceIcon, tone: 'is-warning' };
  return { icon: ServerIcon, tone: 'is-muted' };
}

const userInfo = computed<ClientUserInfo>(() => (userStore.info || {}) as ClientUserInfo);
const displayName = computed(() =>
  String(
    userInfo.value.nickname || userInfo.value.display_name || userInfo.value.email || userInfo.value.name || '客户账户',
  ),
);
const avatarText = computed(() => displayName.value.slice(0, 1) || '客');
const avatarUrl = computed(() => '');
const userIdText = computed(() => String(userInfo.value.id || '--'));
const isVerified = computed(() => Number(userInfo.value.is_verified || 0) === 1);
const isPhoneBound = computed(() => Boolean(String(userInfo.value.phone || '').trim()));
const isEmailBound = computed(() => Boolean(String(userInfo.value.email || '').trim()));
const agentGroupName = computed(() =>
  String(userInfo.value.agent_group?.name || userInfo.value.agent_group_name || ''),
);
const supportQr = computed(() => siteBranding.supportGroupQr || '');
const supportGroupLink = computed(() => siteBranding.supportGroupLink || '');
const supportPhoneText = computed(() => siteBranding.serviceQqGroup || '-');
const supportGroupTitle = computed(() => siteBranding.supportGroupTitle || '官方 QQ 群聊');
const supportGroupText = computed(() => siteBranding.supportGroupText || '加入官方 QQ 群聊');

const summaryCards = computed(() => [
  {
    key: 'balance',
    label: '账户余额',
    value: formatMoney(financeSummary.value.cash_balance),
    unit: '元',
    note: `ID: ${userIdText.value}`,
  },
  {
    key: 'recharge',
    label: '本月充值',
    value: formatMoney(financeSummary.value.recharge_in),
    unit: '元',
    note: '财务入账汇总',
  },
  {
    key: 'payment',
    label: '本月消费',
    value: formatMoney(financeSummary.value.invoice_payment_out),
    unit: '元',
    note: '本月账单支出',
    warning: true,
  },
  {
    key: 'coupon',
    label: '可用优惠券',
    value: String(Number(couponSummary.value.available || 0)),
    unit: '张',
    note: `总计 ${Number(couponSummary.value.total || 0)} 张`,
  },
]);

const currentYearLabel = computed(() => `${new Date().getFullYear()} 年`);

const greetingText = computed(() => {
  const hour = new Date().getHours();
  if (hour < 6) return '夜深了';
  if (hour < 9) return '早上好';
  if (hour < 12) return '上午好';
  if (hour < 14) return '中午好';
  if (hour < 18) return '下午好';
  if (hour < 22) return '晚上好';
  return '夜深了';
});

const todayDateText = computed(() => {
  const now = new Date();
  const weekDays = ['日', '一', '二', '三', '四', '五', '六'];
  return `${now.getFullYear()}年${now.getMonth() + 1}月${now.getDate()}日 星期${weekDays[now.getDay()]}`;
});

const productCards = computed<ProductCard[]>(() => {
  if (serviceOverview.value.list.length) {
    return serviceOverview.value.list.slice(0, 6).map((item, index) => {
      const key = String(item.key || item.product_type || `product-${index}`);
      const meta = resolveProductMeta(item, key);
      return {
        key,
        title: String(item.title || item.name || item.product_type_label || '云产品'),
        countText: `${Number(item.active_count || 0)} 个`,
        count: Number(item.active_count || 0),
        path: '/client/services',
        primaryServiceId: Number(item.primary_service_id || 0),
        icon: meta.icon,
        tone: meta.tone,
      };
    });
  }

  return [
    {
      key: 'cloud_server',
      title: '云服务器',
      countText: '0 个',
      count: 0,
      path: '/client/services',
      primaryServiceId: 0,
      icon: ServerIcon,
      tone: 'is-brand',
    },
    {
      key: 'game_cloud',
      title: '游戏云',
      countText: '0 个',
      count: 0,
      path: '/client/services',
      primaryServiceId: 0,
      icon: DashboardIcon,
      tone: 'is-info',
    },
    {
      key: 'cloud_desktop',
      title: '云电脑',
      countText: '0 个',
      count: 0,
      path: '/client/services',
      primaryServiceId: 0,
      icon: HelpCircleIcon,
      tone: 'is-info',
    },
    {
      key: 'bare_metal',
      title: '裸金属',
      countText: '0 个',
      count: 0,
      path: '/client/services',
      primaryServiceId: 0,
      icon: DashboardIcon,
      tone: 'is-success',
    },
    {
      key: 'cdn',
      title: 'CDN',
      countText: '0 个',
      count: 0,
      path: '/client/services',
      primaryServiceId: 0,
      icon: ServiceIcon,
      tone: 'is-warning',
    },
    {
      key: 'web_hosting',
      title: '虚拟主机',
      countText: '0 个',
      count: 0,
      path: '/client/services',
      primaryServiceId: 0,
      icon: UserSafetyIcon,
      tone: 'is-success',
    },
  ];
});

const dailyExpenseData = computed(() => {
  const days: { date: string; amount: number }[] = [];
  const now = new Date();
  for (let i = 6; i >= 0; i -= 1) {
    const date = new Date(now);
    date.setDate(date.getDate() - i);
    days.push({ date: toDateText(date), amount: 0 });
  }

  for (const entry of balanceLogsDaily.value) {
    const change = toNumber(entry.change_amount);
    if (change >= 0) continue;
    const dateStr = String(entry.created_at || '').slice(0, 10);
    const matched = days.find((item) => item.date === dateStr);
    if (matched) {
      matched.amount = Math.round((matched.amount + Math.abs(change)) * 100) / 100;
    }
  }

  return days;
});

const dailyBars = computed(() => {
  const amounts = dailyExpenseData.value.map((item) => item.amount);
  const max = Math.max(...amounts, 1);
  return dailyExpenseData.value.map((item) => ({
    ...item,
    height: item.amount > 0 ? `${Math.max(8, Math.round((item.amount / max) * 100))}%` : '0%',
    showLabel: true,
    label: `${Number(item.date.slice(5, 7))}/${Number(item.date.slice(8, 10))}`,
    tooltip: `${item.date.slice(5).replace('-', '/')} · 消费 ¥${formatMoney(item.amount)}`,
  }));
});

const dailyBarsHasData = computed(() => dailyExpenseData.value.some((item) => item.amount > 0));

const last7DaysConsumptionTotal = computed(
  () => Math.round(dailyExpenseData.value.reduce((sum, item) => sum + item.amount, 0) * 100) / 100,
);

const monthlyConsumptionData = computed(() => {
  const now = new Date();
  const year = now.getFullYear();
  const months: { key: string; label: string; month: number; amount: number; count: number }[] = [];
  for (let m = 0; m < 12; m += 1) {
    const key = `${year}-${String(m + 1).padStart(2, '0')}`;
    months.push({ key, label: `${String(m + 1).padStart(2, '0')}月`, month: m + 1, amount: 0, count: 0 });
  }

  for (const invoice of paidInvoices.value) {
    const date = String(invoice.paid_at || invoice.created_at || '');
    if (!date.startsWith(`${year}-`)) continue;
    const monthKey = date.slice(0, 7); // YYYY-MM
    const target = months.find((item) => item.key === monthKey);
    if (!target) continue;
    const amount = toNumber(invoice.paid_amount || invoice.amount);
    target.amount = Math.round((target.amount + amount) * 100) / 100;
    target.count += 1;
  }
  return months;
});

const monthlyBars = computed(() => {
  const amounts = monthlyConsumptionData.value.map((item) => item.amount);
  const max = Math.max(...amounts, 1);
  return monthlyConsumptionData.value.map((item) => ({
    ...item,
    height: item.amount > 0 ? `${Math.max(8, Math.round((item.amount / max) * 100))}%` : '0%',
    amountLabel: item.amount > 0 ? formatMoney(item.amount) : '',
    tooltip: `${item.label} · 消费 ¥${formatMoney(item.amount)} · ${item.count} 笔账单`,
  }));
});

const monthlyBarsHasData = computed(() => monthlyConsumptionData.value.some((item) => item.amount > 0));
const currentYearConsumptionTotal = computed(
  () => Math.round(monthlyConsumptionData.value.reduce((sum, item) => sum + item.amount, 0) * 100) / 100,
);

const expiringServiceCount = computed(() =>
  serviceOverview.value.list.reduce((sum, item) => sum + Number(item.expiring_count || 0), 0),
);

const openTicketCount = computed(() => recentTickets.value.filter((item) => Number(item.status) !== 3).length);

const todoItems = computed(() => [
  {
    key: 'invoice',
    label: '待支付账单',
    count: Number(financeSummary.value.unpaid_count || 0),
    path: '/client/invoices',
    icon: FileIcon,
    tone: 'is-brand',
  },
  {
    key: 'ticket',
    label: '待处理工单',
    count: openTicketCount.value,
    path: '/client/tickets',
    icon: ServiceIcon,
    tone: 'is-warning',
  },
  {
    key: 'renew',
    label: '即将到期服务',
    count: expiringServiceCount.value,
    path: '/client/services',
    icon: ServerIcon,
    tone: 'is-success',
  },
  {
    key: 'notice',
    label: '未读公告',
    count: unreadCount.value,
    path: '/client/notices',
    icon: NotificationIcon,
    tone: 'is-muted',
  },
]);

const quickLinks = [
  { label: '我的服务', path: '/client/services', icon: ServerIcon },
  { label: '账单记录', path: '/client/invoices', icon: FileIcon },
  { label: '充值记录', path: '/client/payments', icon: MoneyIcon },
  { label: '工单支持', path: '/client/tickets', icon: ServiceIcon },
  { label: '账户充值', path: '/client/recharge', icon: WalletIcon },
  { label: '帮助中心', path: '/client/help', icon: HelpCircleIcon },
  { label: '优惠券', path: '/client/coupons', icon: CouponIcon },
  { label: '推荐奖励', path: '/client/referral', icon: GiftIcon },
];

async function loadDashboard() {
  loading.value = true;
  const currentYear = new Date().getFullYear();

  try {
    await userStore.getUserInfo().catch(() => {});

    if (!siteBranding.siteName) {
      void siteBranding.fetchSiteConfig().catch(() => {});
    } else {
      void siteBranding.fetchSiteConfig();
    }

    const monthRange = currentMonthRange();
    // 首屏核心数据：余额、服务概览、待办项、公告（优先加载）
    const [noticesRes, ticketsRes, financeRes, couponsRes, serviceOverviewRes, referralRes] = await Promise.allSettled([
      clientApi.notices({ page: 1, page_size: 5 }),
      clientApi.tickets({ page: 1, page_size: 5 }),
      clientApi.financeLedgerSummary(monthRange),
      clientApi.couponsSummary(),
      clientApi.groupedOverview(),
      clientApi.referralOverview(),
    ]);

    // 处理首屏数据
    if (noticesRes.status === 'fulfilled') {
      recentNotices.value = resolvePagedList(noticesRes.value.data);
    }
    if (ticketsRes.status === 'fulfilled') {
      recentTickets.value = resolvePagedList(ticketsRes.value.data);
    }
    if (financeRes.status === 'fulfilled') {
      financeSummary.value = {
        ...financeSummary.value,
        ...(financeRes.value.data || {}),
      };
    }
    if (couponsRes.status === 'fulfilled') {
      couponSummary.value = {
        ...couponSummary.value,
        ...(couponsRes.value.data || {}),
      };
    }
    if (serviceOverviewRes.status === 'fulfilled') {
      serviceOverview.value = serviceOverviewRes.value.data || {
        total: 0,
        category_total: 0,
        list: [],
        catalog_types: [],
      };
    }
    if (referralRes.status === 'fulfilled') {
      const data: ReferralOverviewPayload = referralRes.value.data || {};
      const availableCoupons = Number(data.available_coupons || 0);
      if (availableCoupons > Number(couponSummary.value.available || 0)) {
        couponSummary.value.available = availableCoupons;
      }
    }

    // 首屏核心数据加载完成（总览卡片 + 产品概览 + 待办项）
    loading.value = false;

    // 次屏延迟加载：图表数据、帮助文章、未读数
    // 这些数据不阻塞首屏显示，提升感知速度
    async function loadSecondaryData() {
      try {
        const [helpRes, _unreadRes, balanceLogsRes, paidInvoicesRes] = await Promise.allSettled([
          clientApi.helpArticles({ page: 1, page_size: 10 }),
          fetchUnreadCount(true),
          clientApi.balanceLogs({ ...last7DaysRange(), page_size: 200 }),
          clientApi.invoices({
            page: 1,
            page_size: 100,
            status: 1,
            start_date: `${currentYear}-01-01`,
            end_date: `${currentYear}-12-31`,
          }),
        ]);

        if (helpRes.status === 'fulfilled') {
          recentHelpArticles.value = resolvePagedList(helpRes.value.data);
        }
        if (balanceLogsRes.status === 'fulfilled') {
          balanceLogsDaily.value = resolvePagedList(balanceLogsRes.value.data);
          balanceLogsLoaded.value = true;
        }

        if (paidInvoicesRes.status === 'fulfilled') {
          paidInvoices.value = resolvePagedList(paidInvoicesRes.value.data);
          invoicesLoaded.value = true;
        }
      } catch (error) {
        // 静默失败，不影响首屏显示
        console.error('Failed to load secondary data:', error);
      }
    }

    void loadSecondaryData();
  } catch (error: unknown) {
    loading.value = false;
    if (!isHandledError(error)) {
      MessagePlugin.error(getErrorMessage(error, '控制台数据加载失败'));
    }
  }
}

onMounted(() => {
  void loadDashboard();
});
</script>
<style scoped lang="less">
.client-dashboard {
  // padding 由 Starter 布局层统一提供
}

.summary-grid {
  display: grid;
  grid-template-columns: minmax(22rem, 2fr) repeat(4, minmax(10rem, 1fr));
  gap: var(--td-comp-margin-m);
}

.dashboard-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(18rem, 24%);
  gap: var(--td-comp-margin-m);
  margin-top: var(--td-comp-margin-m);
}

.dashboard-main,
.dashboard-aside {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-m);
  min-width: 0;
}

.chart-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--td-comp-margin-m);
}

.dashboard-card {
  height: 100%;
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
  box-shadow: var(--td-shadow-1);
}

.account-card__user {
  display: flex;
  gap: var(--td-comp-margin-m);
  align-items: flex-start;
}

.account-card__main {
  min-width: 0;

  h2 {
    margin: 0;
    overflow: hidden;
    color: var(--td-text-color-primary);
    font: var(--td-font-title-medium);
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  p {
    margin: var(--td-comp-margin-xs) 0 0;
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-medium);
  }
}

.account-card__tags,
.account-card__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-s);
  margin-top: var(--td-comp-margin-m);
}

.metric-card__label,
.metric-card__note,
.card-meta,
.chart-summary {
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}

.chart-card__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-s);
  align-items: center;
  justify-content: flex-end;
}

.metric-card__value {
  margin-top: var(--td-comp-margin-xs);
  color: var(--td-text-color-primary);
  font: var(--td-font-headline-medium);

  span {
    margin-left: var(--td-comp-margin-xs);
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-medium);
  }

  &.is-warning {
    color: var(--td-warning-color);
  }
}

.metric-card__note {
  margin-top: var(--td-comp-margin-xs);
}

.product-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: var(--td-comp-margin-s);
}

.product-tile,
.todo-row,
.quick-link {
  border: thin solid var(--td-border-color);
  background: var(--td-bg-color-container);
  color: var(--td-text-color-primary);
  cursor: pointer;
  text-decoration: none;
  transition:
    border-color var(--td-anim-duration-base) var(--td-anim-time-fn-easing),
    background-color var(--td-anim-duration-base) var(--td-anim-time-fn-easing),
    color var(--td-anim-duration-base) var(--td-anim-time-fn-easing);

  &:focus-visible {
    outline: 0.125rem solid var(--td-brand-color);
    outline-offset: 0.0625rem;
  }
}

.product-tile {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: center;
  min-width: 0;
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  text-align: left;
  border-radius: var(--td-radius-medium);

  &:hover {
    border-color: var(--td-brand-color);
    background: var(--td-brand-color-light);
  }
}

.product-tile__icon,
.todo-row__icon,
.quick-link svg {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--td-text-color-secondary);
}

.product-tile__icon,
.todo-row__icon {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: var(--td-radius-medium);
  color: var(--td-text-color-secondary);
  background: var(--td-bg-color-component);

  &.is-brand {
    color: #fff;
    background: var(--td-brand-color);
  }

  &.is-info {
    color: #fff;
    background: var(--td-brand-color-7);
  }

  &.is-success {
    color: #fff;
    background: var(--td-success-color);
  }

  &.is-warning {
    color: #fff;
    background: var(--td-warning-color);
  }

  &.is-muted {
    color: var(--td-text-color-secondary);
    background: var(--td-bg-color-component);
  }
}

.product-tile__content {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: var(--td-comp-margin-xxs);
  min-width: 0;

  strong,
  span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  strong {
    color: var(--td-text-color-primary);
    font: var(--td-font-body-medium);
  }

  span {
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }
}

.chart-card {
  min-height: 23rem;
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.chart-card :deep(.t-loading__parent),
.chart-card :deep(.t-card__body) {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-height: 0;
}

.chart-card :deep(.t-loading__parent) {
  justify-content: flex-end;
}

.message-row,
.todo-row {
  display: flex;
  align-items: center;
}

.bar-chart {
  display: flex;
  align-items: flex-end;
  height: 12rem;
  gap: var(--td-comp-margin-xxs);
  padding: var(--td-comp-paddingTB-s) 0 var(--td-comp-paddingTB-xl);
  border-bottom: thin solid var(--td-border-color);
}

.bar-chart__slot {
  position: relative;
  display: flex;
  flex: 1;
  align-items: flex-end;
  justify-content: center;
  height: 100%;
  min-width: 0;
}

.bar-chart__bar {
  width: 68%;
  min-height: 0;
  background: var(--td-brand-color);
  border-radius: 0.1875rem 0.1875rem 0 0;
}

.bar-chart__label {
  position: absolute;
  bottom: calc(var(--td-comp-margin-l) * -1);
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
}

.bar-chart--daily {
  height: 12rem;
}

.bar-chart--daily .bar-chart__slot {
  cursor: pointer;
}

.bar-chart--daily .bar-chart__bar {
  width: 50%;
}

.bar-chart--monthly {
  height: 16rem;
  padding-top: var(--td-comp-paddingTB-l);
}

.bar-chart--monthly .bar-chart__slot {
  cursor: pointer;
}

.bar-chart--monthly .bar-chart__col {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-end;
  width: 60%;
  min-height: 0;
}

.bar-chart--monthly .bar-chart__bar {
  width: 100%;
  flex: 1;
  min-height: 0;
}

.bar-chart__value {
  position: absolute;
  bottom: 100%;
  left: 50%;
  transform: translateX(-50%);
  padding-bottom: var(--td-comp-margin-xxs);
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
  white-space: nowrap;
  opacity: 0;
  transition: opacity 0.2s ease;
}

.bar-chart__slot:hover .bar-chart__value,
.bar-chart__slot:focus-within .bar-chart__value {
  opacity: 1;
}

.chart-summary {
  strong {
    color: var(--td-text-color-primary);
  }
}

.message-tabs {
  margin-top: calc(var(--td-comp-margin-s) * -1);
}

.message-list,
.todo-list {
  display: flex;
  flex-direction: column;
}

.message-row {
  gap: var(--td-comp-margin-s);
  min-height: var(--td-comp-size-xxl);
  color: var(--td-text-color-primary);
  text-decoration: none;
  border-bottom: thin solid var(--td-border-color);

  &:hover {
    color: var(--td-brand-color);
  }
}

.message-row__title {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.message-row__time {
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}

.todo-row {
  justify-content: space-between;
  width: 100%;
  padding: var(--td-comp-paddingTB-s) 0;
  border-width: 0 0 thin;
  text-align: left;

  &:hover {
    color: var(--td-brand-color);
    border-color: var(--td-brand-color);
  }
}

.todo-row__left {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: center;
  min-width: 0;
}

.todo-row__count {
  color: var(--td-text-color-secondary);
  font: var(--td-font-title-small);

  &.is-alert {
    color: var(--td-error-color);
  }
}

.todo-row__alert-icon {
  margin-right: 0.125rem;
  font-size: var(--td-font-size-body-medium);
  vertical-align: -0.0625rem;
}

.quick-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--td-comp-margin-s);
}

.quick-link {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-xs);
  align-items: center;
  justify-content: center;
  min-height: 5rem;
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-s);
  text-align: center;
  text-decoration: none;
  border-radius: var(--td-radius-medium);

  &:hover {
    border-color: var(--td-brand-color);
    background: var(--td-brand-color-light);
  }
}

.support-box {
  display: grid;
  grid-template-columns: minmax(6rem, 32%) minmax(0, 1fr);
  gap: var(--td-comp-margin-m);
  align-items: center;
}

.support-box__qr {
  aspect-ratio: 1;
  width: 100%;
  object-fit: cover;
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
}

.support-box__qr--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--td-text-color-secondary);
  background: var(--td-bg-color-component);
}

.support-box__content {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-s);
  min-width: 0;

  strong {
    color: var(--td-text-color-primary);
    font: var(--td-font-body-medium);
  }

  span {
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }
}

@media (width <= 73.75rem) {
  .summary-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .account-card {
    grid-column: 1 / -1;
  }

  .dashboard-grid {
    grid-template-columns: 1fr;
  }

  .dashboard-aside {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    align-items: stretch;
  }
}

@media (width <= 56.25rem) {
  .chart-grid,
  .dashboard-aside {
    grid-template-columns: 1fr;
  }

  .product-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (width <= 40rem) {
  .summary-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--td-comp-margin-s);
  }

  .dashboard-grid {
    margin-top: var(--td-comp-margin-s);
  }

  .dashboard-main,
  .dashboard-aside {
    gap: var(--td-comp-margin-s);
  }

  .account-card__user {
    gap: var(--td-comp-margin-s);
  }

  .account-card__main h2 {
    font: var(--td-font-title-small);
  }

  .account-card__tags,
  .account-card__actions {
    margin-top: var(--td-comp-margin-s);
  }

  .account-card__actions {
    flex-direction: column;

    :deep(.t-button) {
      width: 100%;
      margin-left: 0;
    }
  }

  .metric-card__value {
    font: var(--td-font-title-large);
  }

  .chart-card__actions {
    justify-content: flex-start;
  }

  .quick-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: var(--td-comp-margin-xs);
  }

  .quick-link {
    min-height: 4.25rem;
    padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-xs);

    span {
      overflow: hidden;
      max-width: 100%;
      font: var(--td-font-body-small);
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  }

  .message-row {
    flex-direction: column;
    align-items: flex-start;
    gap: var(--td-comp-margin-xxs);
    padding: var(--td-comp-paddingTB-s) 0;
  }
}

@media (width <= 30rem) {
  .quick-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
</style>
