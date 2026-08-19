import { SERVICE_STATUS, SERVICE_STATUS_MAP, toSelectOptions } from '@shared/statusConfig';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref, shallowRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import clientApi from '@/api/client';
import type {
  CouponOption,
  ServiceCatalogTypeOption,
  ServiceInstance,
  ServiceOverviewGroup,
  ServiceOverviewPayload,
  ServiceRenewPreview,
} from '@/types/client';
import { copyText, formatMoney } from '@/utils/format';

export const OS_ICON_MAP: Record<string, string> = {
  windows: '/img/os/Windows.svg',
  win: '/img/os/Windows.svg',
  tencentos: '/img/os/TencentOS.png',
  ubuntu: '/img/os/Ubuntu.svg',
  debian: '/img/os/Debian.svg',
  centos: '/img/os/CentOS.svg',
  rocky: '/img/os/Rocky.svg',
  almalinux: '/img/os/AlmaLinux.svg',
  alma: '/img/os/AlmaLinux.svg',
  archlinux: '/img/os/ArchLinux.svg',
  arch: '/img/os/ArchLinux.svg',
  fedora: '/img/os/Fedora.svg',
  freebsd: '/img/os/FreeBSD.svg',
  bsd: '/img/os/FreeBSD.svg',
  esxi: '/img/os/ESXi.svg',
  vmware: '/img/os/ESXi.svg',
  openeuler: '/img/os/OpenEuler.svg',
  euler: '/img/os/OpenEuler.svg',
  xenserver: '/img/os/XenServer.svg',
  xen: '/img/os/XenServer.svg',
};

const SERVICE_VIEW_MODE_STORAGE_KEY = 'client-services-view-mode';
const DEFAULT_SERVICE_STATUS_SCOPE = 'active_pending';
const DEFAULT_SERVICE_STATUS = DEFAULT_SERVICE_STATUS_SCOPE;
const MOBILE_VIEW_MODE_QUERY = '(max-width: 48rem)';
const DEFAULT_SERVICE_STATUS_OPTION = {
  label: '默认分类（已开通 / 开通中）',
  value: DEFAULT_SERVICE_STATUS_SCOPE,
};

type ServiceListParams = Record<string, string | number>;
type TdesignTagTheme = 'primary' | 'success' | 'warning' | 'danger';
type ServiceLike = Partial<ServiceInstance> & Record<string, unknown>;
interface StatusMapEntry {
  label?: string;
}

interface ServiceOverview extends ServiceOverviewPayload {}

function getErrorMessage(error: unknown, fallback: string) {
  return error instanceof Error && error.message ? error.message : fallback;
}

export function resolveServiceStatusLabel(status: unknown) {
  const serviceStatus = Number(status);
  const statusMap = SERVICE_STATUS_MAP as Record<string | number, StatusMapEntry>;
  const label = String(statusMap[serviceStatus]?.label || '').trim();
  return label !== '' ? label : '-';
}

export function createEmptyOverview(): ServiceOverview {
  return {
    total: 0,
    category_total: 0,
    list: [],
    catalog_types: [],
  };
}

export { formatMoney };

export function resolveServiceName(item: ServiceLike | null | undefined) {
  return (
    item?.custom_service_name ||
    item?.name ||
    item?.product_spec_display ||
    item?.product_display_name ||
    item?.product?.display_name ||
    `服务 #${item?.id || 0}`
  );
}

export function resolveServiceOsText(item: ServiceLike) {
  return String(item?.upstream?.os || '').trim();
}

export function resolveServiceOsIcon(item: ServiceLike) {
  const name = resolveServiceOsText(item).toLowerCase();
  if (!name) return '';

  for (const [keyword, icon] of Object.entries(OS_ICON_MAP)) {
    if (name.includes(keyword)) return icon;
  }

  return '';
}

export function resolveServiceMark(item: ServiceLike) {
  const osText = resolveServiceOsText(item);
  const text =
    osText || item?.product?.type_label || item?.product?.group_name || item?.product?.display_name || '服务';
  return String(text).replace(/\s+/g, '').slice(0, 2);
}

export function findListSpecValue(item: ServiceLike, aliases: string[] = [], fallback = '--') {
  const specs = Array.isArray(item?.specs) ? item.specs : [];
  for (const alias of aliases) {
    const keyword = String(alias || '')
      .trim()
      .toLowerCase();
    if (!keyword) continue;

    const matched = specs.find((spec) =>
      String(spec?.label || '')
        .trim()
        .toLowerCase()
        .includes(keyword),
    );
    const value = String(matched?.value || '').trim();
    if (value) return value;
  }

  return fallback;
}

export function resolveListBandwidthText(item: ServiceLike) {
  const direct = findListSpecValue(item, ['带宽', '宽带'], '');
  if (direct !== '') return direct;

  const inbound = findListSpecValue(item, ['下行带宽'], '');
  const outbound = findListSpecValue(item, ['上行带宽'], '');
  if (inbound && outbound) return `${outbound} / ${inbound}`;
  return inbound || outbound || '--';
}

export function resolveRuntimeStatusLabel(item: ServiceLike) {
  return resolveServiceStatusLabel(item?.status);
}

export function isExpiringSoon(dateText: string) {
  if (!dateText) return false;

  const expiresAt = new Date(dateText).getTime();
  if (!Number.isFinite(expiresAt)) return false;

  const diff = expiresAt - Date.now();
  return diff > 0 && diff <= 7 * 24 * 60 * 60 * 1000;
}

export function isProvisioningService(item: ServiceLike) {
  if (Number(item?.status) === SERVICE_STATUS.PENDING) return true;
  return resolveRuntimeStatusLabel(item) === '开通中';
}

export function resolveTdesignStatusTheme(item: ServiceLike): TdesignTagTheme {
  const tone = String(item?.status_tone || '').trim();
  if (tone === 'success') return 'success';
  if (tone === 'warning') return 'warning';
  if (tone === 'danger') return 'danger';
  return 'primary';
}

export function useServiceCenter() {
  const router = useRouter();
  const route = useRoute();

  const loading = ref(false);
  const overviewLoading = ref(false);
  const list = shallowRef<ServiceInstance[]>([]);
  const total = ref(0);
  const overview = shallowRef<ServiceOverview>(createEmptyOverview());

  const filters = reactive({
    page: 1,
    page_size: 10,
    status: DEFAULT_SERVICE_STATUS as string | number,
    keyword: '',
    catalog_type: '',
    quick_filter: '',
    auto_renew: '',
  });
  const viewMode = ref<'grid' | 'list'>('grid');

  const renewVisible = ref(false);
  const renewPreviewLoading = ref(false);
  const renewSubmitting = ref(false);
  const renewTarget = shallowRef<ServiceInstance | null>(null);
  const renewData = shallowRef<ServiceRenewPreview | null>(null);
  const remarkVisible = ref(false);
  const remarkSubmitting = ref(false);
  const remarkTarget = shallowRef<ServiceInstance | null>(null);
  const renewForm = reactive({
    billing_cycle: '',
    user_coupon_id: 0,
  });
  const remarkForm = reactive({
    remark: '',
  });

  const statusOptions = [DEFAULT_SERVICE_STATUS_OPTION, ...toSelectOptions(SERVICE_STATUS_MAP, false)];
  const catalogTypeOptions = computed(() =>
    Array.isArray(overview.value.catalog_types)
      ? overview.value.catalog_types.filter((item: ServiceCatalogTypeOption) => item?.value)
      : [],
  );
  const viewModeOptions = [
    { label: '卡片', value: 'grid' },
    { label: '列表', value: 'list' },
  ];

  const metricCards = computed(() => {
    const groups = Array.isArray(overview.value.list) ? overview.value.list : [];
    const activeCount = groups.reduce(
      (sum: number, item: ServiceOverviewGroup) => sum + Number(item?.active_count || 0),
      0,
    );
    const pendingCount = groups.reduce(
      (sum: number, item: ServiceOverviewGroup) => sum + Number(item?.pending_count || 0),
      0,
    );
    const expiringCount = groups.reduce(
      (sum: number, item: ServiceOverviewGroup) => sum + Number(item?.expiring_count || 0),
      0,
    );

    return [
      {
        key: 'total',
        label: '实例总数',
        value: Number(overview.value.total || 0),
        copy: `覆盖 ${Number(overview.value.category_total || 0)} 个业务分类`,
      },
      {
        key: 'active',
        label: '正常运行',
        value: activeCount,
        copy: '可直接进入控制台操作',
      },
      {
        key: 'pending',
        label: '待处理实例',
        value: pendingCount,
        copy: '开通中、暂停等需要关注的实例',
      },
      {
        key: 'expiring',
        label: '即将到期',
        value: expiringCount,
        copy: '建议提前续费避免业务中断',
      },
    ];
  });

  const selectedRenewAmount = computed(() => {
    const cycles = Array.isArray(renewData.value?.cycles) ? renewData.value.cycles : [];
    const current = cycles.find((item) => item.billing_cycle === renewForm.billing_cycle);
    return formatMoney(current?.amount || 0);
  });

  const availableRenewCoupons = computed(() =>
    Array.isArray(renewData.value?.available_coupons) ? (renewData.value.available_coupons as CouponOption[]) : [],
  );

  async function loadOverview() {
    overviewLoading.value = true;
    try {
      const res = await clientApi.groupedOverview();
      overview.value = { ...createEmptyOverview(), ...(res.data || {}) };
    } finally {
      overviewLoading.value = false;
    }
  }

  async function loadList() {
    loading.value = true;
    try {
      const params: ServiceListParams = { page: filters.page, page_size: filters.page_size };
      if (String(filters.keyword).trim()) params.keyword = String(filters.keyword).trim();
      if (filters.auto_renew === '1') {
        params.quick_filter = 'auto_renew_enabled';
      } else if (filters.auto_renew === '0') {
        params.quick_filter = 'auto_renew_disabled';
      } else {
        if (filters.status === DEFAULT_SERVICE_STATUS_SCOPE) {
          params.status_scope = DEFAULT_SERVICE_STATUS_SCOPE;
        } else if (filters.status !== '' && filters.status !== null && filters.status !== undefined) {
          params.status = filters.status;
        }
        if (filters.quick_filter) params.quick_filter = filters.quick_filter;
      }
      if (filters.catalog_type) params.catalog_type = filters.catalog_type;

      const res = await clientApi.services(params);
      list.value = Array.isArray(res.data?.list) ? res.data.list : [];
      total.value = Number(res.data?.total || 0);
    } finally {
      loading.value = false;
    }
  }

  function normalizeViewMode(value: unknown) {
    return value === 'list' ? 'list' : 'grid';
  }

  function setViewMode(value: unknown) {
    const nextMode = normalizeViewMode(value);
    if (viewMode.value === nextMode) return;
    viewMode.value = nextMode;
    if (typeof window !== 'undefined') {
      window.localStorage.setItem(SERVICE_VIEW_MODE_STORAGE_KEY, nextMode);
    }
  }

  function restoreViewMode() {
    if (typeof window === 'undefined') return;
    const stored = normalizeViewMode(window.localStorage.getItem(SERVICE_VIEW_MODE_STORAGE_KEY));
    // 手机端强制卡片视图，忽略历史偏好
    const isMobile = window.matchMedia(MOBILE_VIEW_MODE_QUERY).matches;
    viewMode.value = isMobile ? 'grid' : stored;
  }

  function hydrateFiltersFromRoute() {
    const routeCatalogType = Array.isArray(route.query.catalog_type)
      ? route.query.catalog_type[0]
      : route.query.catalog_type;
    const nextCatalogType = String(routeCatalogType || '').trim();
    const routeQuickFilter = Array.isArray(route.query.quick_filter)
      ? route.query.quick_filter[0]
      : route.query.quick_filter;
    const nextQuickFilter = String(routeQuickFilter || '').trim();

    if (nextCatalogType !== '') {
      filters.catalog_type = nextCatalogType;
      filters.status = DEFAULT_SERVICE_STATUS;
      filters.page = 1;
    }

    filters.quick_filter = nextQuickFilter;
  }

  function handleSearch() {
    filters.page = 1;
    void loadList();
  }

  function handlePageSizeChange() {
    filters.page = 1;
    void loadList();
  }

  function pickCategory(value: string) {
    if (filters.catalog_type === value) return;
    filters.catalog_type = value;
    handleSearch();
  }

  function openDetail(id: number) {
    router.push(`/client/services/${id}`);
  }

  function openInvoiceDetail(id: number) {
    if (!id) return;
    router.push({ path: '/client/invoices', query: { detail: String(id) } });
  }

  async function loadRenewPreview(serviceId: number) {
    renewPreviewLoading.value = true;
    try {
      const res = await clientApi.serviceRenewPreview(serviceId, {
        billing_cycle: renewForm.billing_cycle || undefined,
        user_coupon_id: renewForm.user_coupon_id || undefined,
      });
      renewData.value = res.data || null;
      renewForm.billing_cycle = String(
        res.data?.default_cycle || res.data?.billing_cycle || res.data?.cycles?.[0]?.billing_cycle || '',
      );
      renewForm.user_coupon_id = Number(res.data?.selected_user_coupon_id || 0);
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '加载续费信息失败'));
    } finally {
      renewPreviewLoading.value = false;
    }
  }

  async function openRenew(item: ServiceInstance) {
    renewVisible.value = true;
    renewTarget.value = item;
    renewData.value = null;
    renewForm.billing_cycle = '';
    renewForm.user_coupon_id = 0;
    await loadRenewPreview(item.id);
  }

  async function handleRenewCycleChange(value: unknown) {
    renewForm.billing_cycle = String(value || '');
    if (!renewTarget.value?.id) return;
    await loadRenewPreview(renewTarget.value.id);
  }

  async function handleRenewCouponChange(value: unknown) {
    renewForm.user_coupon_id = Number(value || 0);
    if (!renewTarget.value?.id) return;
    await loadRenewPreview(renewTarget.value.id);
  }

  async function submitRenew() {
    if (!renewTarget.value?.id || !renewForm.billing_cycle) return;
    renewSubmitting.value = true;
    try {
      const res = await clientApi.createRenewOrder(renewTarget.value.id, {
        billing_cycle: renewForm.billing_cycle,
        user_coupon_id: renewForm.user_coupon_id || undefined,
      });
      const invoiceId = Number(res.data?.id || 0);
      renewVisible.value = false;
      MessagePlugin.success('续费账单已创建，正在跳转支付');
      router.push(invoiceId > 0 ? `/client/invoices/${invoiceId}/pay` : '/client/invoices');
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '续费账单创建失败'));
    } finally {
      renewSubmitting.value = false;
    }
  }

  function openRemark(item: ServiceInstance) {
    remarkTarget.value = item;
    remarkForm.remark = String(item?.remark || '');
    remarkVisible.value = true;
  }

  async function submitRemark() {
    if (!remarkTarget.value?.id) return;
    remarkSubmitting.value = true;
    try {
      const res = await clientApi.updateServiceRemark(remarkTarget.value.id, { remark: remarkForm.remark });
      const updatedItem = res.data || ({} as ServiceInstance);
      const index = list.value.findIndex((item) => Number(item?.id || 0) === Number(remarkTarget.value?.id || 0));
      if (index >= 0) {
        const nextList = [...list.value];
        nextList.splice(index, 1, { ...nextList[index], ...updatedItem });
        list.value = nextList;
      }
      remarkVisible.value = false;
      MessagePlugin.success('备注已保存');
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '备注保存失败'));
    } finally {
      remarkSubmitting.value = false;
    }
  }

  async function copyPublicIp(value: unknown) {
    await copyText(value, {
      successMsg: '公网 IP 已复制',
      errorMsg: '当前浏览器不支持自动复制，请手动复制',
    });
  }

  function handleServiceAction(command: string, item: ServiceInstance) {
    if (command === 'renew') {
      void openRenew(item);
      return;
    }

    if (command === 'invoice' || command === 'order') {
      const targetId = item?.invoice?.id;
      openInvoiceDetail(targetId);
    }
  }

  onMounted(async () => {
    restoreViewMode();
    hydrateFiltersFromRoute();
    await Promise.all([loadOverview(), loadList()]);
  });

  return {
    loading,
    overviewLoading,
    list,
    total,
    overview,
    filters,
    viewMode,
    viewModeOptions,
    renewVisible,
    renewPreviewLoading,
    renewSubmitting,
    renewTarget,
    renewData,
    remarkVisible,
    remarkSubmitting,
    remarkTarget,
    renewForm,
    remarkForm,
    statusOptions,
    catalogTypeOptions,
    metricCards,
    selectedRenewAmount,
    availableRenewCoupons,
    loadOverview,
    loadList,
    handleSearch,
    handlePageSizeChange,
    pickCategory,
    setViewMode,
    openDetail,
    openRenew,
    handleRenewCycleChange,
    handleRenewCouponChange,
    submitRenew,
    openRemark,
    submitRemark,
    copyText: copyPublicIp,
    handleServiceAction,
    router,
  };
}
