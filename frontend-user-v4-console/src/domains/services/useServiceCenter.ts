import { SERVICE_STATUS, SERVICE_STATUS_MAP, toSelectOptions } from '@shared/statusConfig';
import type { ResolvedProductGroupIcon } from '@shared/text';
import { abbreviateGroupName } from '@shared/text';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref, shallowRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import clientApi from '@/api/client';
import { t } from '@/locales';
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
// 默认展示客户仍然持有的实例：已开通 + 开通中 + 已暂停。
// 已暂停（流量超额 / 线路清退 / 服务到期等）也要露出，客户才能续费或手动解除暂停。
const DEFAULT_SERVICE_STATUS_SCOPE = 'active_pending_suspended';
const DEFAULT_SERVICE_STATUS = DEFAULT_SERVICE_STATUS_SCOPE;
const MOBILE_VIEW_MODE_QUERY = '(max-width: 48rem)';
const DEFAULT_SERVICE_STATUS_OPTION = {
  label: t('client.services.defaultCategory'),
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

/**
 * 实例所属的分组路径（二级 / 三级）。
 *
 * 商品名现在只含规格（如「2核2G50M」），实例、订单等页面需要单独显示分组路径才能
 * 看出是哪个产品线。后端 product_full_path 形如
 * 「一级分组/二级分组/三级分组/商品名」：去掉末段商品名，再去掉一级分组
 * （一级分组已经是页面 tab / 分类的上下文），剩下就是要展示的部分。
 */
export function resolveServiceGroupPath(item: ServiceLike | null | undefined) {
  const fullPath = String(item?.product_full_path || '').trim();
  const segments = fullPath
    .split('/')
    .map((segment) => segment.trim())
    .filter(Boolean);

  if (segments.length > 1) {
    segments.pop();
  }

  const rootGroupName = String(item?.product?.root_group_name || '').trim();
  if (rootGroupName && segments[0] === rootGroupName) {
    segments.shift();
  }

  return segments.join(' / ');
}
export function resolveServiceName(item: ServiceLike | null | undefined) {
  return (
    item?.custom_service_name ||
    item?.name ||
    item?.product_spec_display ||
    item?.product_display_name ||
    item?.product?.display_name ||
    t('service.center.serviceHash', { id: item?.id || 0 })
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
    osText ||
    item?.product?.type_label ||
    item?.product?.group_name ||
    item?.product?.display_name ||
    t('service.center.fallbackMark');
  return String(text).replace(/\s+/g, '').slice(0, 2);
}

/**
 * 一级分组分类 tab 的图标：优先用后端 icon_resolved（内置图标 > 文字图标 > 名称缩写），
 * 老缓存或缺字段时把 icon / 历史 icon_text 别名归一成规范 TDesign 名，保证 tab 永远
 * 不会因为缺图标而报错；图片图标已下线，mode=image 的旧缓存降级为文字/缩写。
 */
const SERVICE_GROUP_ICON_ALIASES: Record<string, string> = {
  server: 'server',
  cloud: 'cloud',
  internet: 'internet',
  'control-platform': 'control-platform',
  'component-grid': 'component-grid',
  desktop: 'desktop',
  'hard-disk-storage': 'hard-disk-storage',
  'data-base': 'data-base',
  link: 'link',
  'lock-on': 'lock-on',
  building: 'building',
  tools: 'tools',
  shop: 'shop',
  // 历史别名（与后端 ProductGroupIcon::ICON_ALIASES 一致）
  platform: 'control-platform',
  gamepad: 'control-platform',
  'gamepad-1': 'control-platform',
  dedicated: 'control-platform',
  monitor: 'desktop',
  'desktop-1': 'desktop',
  grid: 'component-grid',
  node: 'server',
  network: 'internet',
  isp: 'internet',
  vps: 'server',
  officebuilding: 'building',
  bare_metal: 'building',
  hosting: 'hard-disk-storage',
  cdn: 'link',
  storage: 'hard-disk-storage',
  database: 'data-base',
  db: 'data-base',
  domain: 'link',
  ssl: 'lock-on',
  nat: 'lock-on',
  router: 'lock-on',
  'router-wave': 'lock-on',
  tool: 'tools',
  other: 'tools',
  product: 'shop',
  invalid_products: 'shop',
};

/** 只返回规范 TDesign 图标名；普通文字返回空串，避免把文字误渲染成空图标。 */
function canonicalServiceGroupIconName(value: unknown): string {
  const raw = String(value ?? '').trim();
  if (!raw) return '';

  return SERVICE_GROUP_ICON_ALIASES[raw.toLowerCase()] || '';
}

export function resolveServiceCatalogIcon(item: ServiceCatalogTypeOption): ResolvedProductGroupIcon {
  const resolved = item.icon_resolved;
  const abbr = String(resolved?.abbr || '') || abbreviateGroupName(item.label || '');
  const text = String(resolved?.text || '').trim();

  if (resolved?.mode === 'icon') {
    const iconName =
      canonicalServiceGroupIconName(resolved.icon_name || item.icon) || canonicalServiceGroupIconName(item.icon_text);
    if (iconName) {
      return { mode: 'icon', image: '', text: '', icon_name: iconName, abbr };
    }
  }

  if (resolved?.mode === 'text' && text) {
    return { mode: 'text', image: '', text, icon_name: '', abbr };
  }

  if (resolved?.mode === 'abbr' && abbr) {
    return { mode: 'abbr', image: '', text: '', icon_name: '', abbr };
  }

  // 老缓存 / 缺 icon_resolved / 历史 image 模式：icon → icon_text 别名 → 文字 → 缩写
  const legacyIconName = canonicalServiceGroupIconName(item.icon) || canonicalServiceGroupIconName(item.icon_text);
  if (legacyIconName) {
    return { mode: 'icon', image: '', text: '', icon_name: legacyIconName, abbr };
  }

  const legacyText = String(item.icon_text || '').trim();
  if (legacyText) {
    return { mode: 'text', image: '', text: legacyText, icon_name: '', abbr };
  }

  return { mode: 'abbr', image: '', text: '', icon_name: '', abbr };
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

/**
 * 列表项是否CDN。
 *
 * 与控制台里的 isCdnConsole() 同款判定，但吃的是列表项（ServiceInstance）而非详情，
 * 所以不读 machine_category —— 列表接口不下发该字段。console_template 由
 * ServiceTransformService 从 product透出，缺失时再按 product_type 兜底。
 */
export function isCdnListItem(item: ServiceLike) {
  const template = String(item?.console_template || item?.product?.console_template || '')
    .trim()
    .toLowerCase();
  if (template === 'cdn') return true;
  if (template === 'compute' || template === 'port_mapping') return false;

  const productType = String(item?.product_type || item?.product?.type || '')
    .trim()
    .toLowerCase();
  return productType === 'cdn';
}

/**
 * CDN 卡片摘要行：节点数 / 节点区域，替代云主机的 CPU / 内存 / 带宽。
 *
 * 匹配要覆盖上游实际下发的 label：节点数、节点区域（不是「加速区域」），
 * CDN 总览那边用的是 ['加速区域','区域','地区','线路']，两处label 不同，
 * 所以这里按节点/区域双关键词兜。
 */
export function resolveListCdnSpecText(item: ServiceLike) {
  return {
    nodes: findListSpecValue(item, ['节点数', '节点数量'], ''),
    region: findListSpecValue(item, ['节点区域', '加速区域', '区域', '地区'], ''),
  };
}

/** 虚拟主机（web_hosting）在列表接口里的判定口径，与控制台的 isVirtualHostConsole 对齐 */
const VIRTUAL_HOST_TYPES = new Set(['virtualhost', 'virtual_host', 'web_hosting', 'hosting']);

/**
 * 列表项是否虚拟主机。
 *
 * 与控制台里的 isVirtualHostConsole() 同款判定，但吃的是列表项（ServiceInstance）而非详情，
 * 所以不读 machine_category —— 列表接口不下发该字段。
 */
export function isVirtualHostListItem(item: ServiceLike) {
  const template = String(item?.console_template || item?.product?.console_template || '')
    .trim()
    .toLowerCase();
  if (VIRTUAL_HOST_TYPES.has(template)) return true;
  if (template === 'cdn' || template === 'compute' || template === 'port_mapping') return false;

  const productType = String(item?.product_type || item?.product?.type || '')
    .trim()
    .toLowerCase();
  return VIRTUAL_HOST_TYPES.has(productType);
}

/**
 * 虚拟主机卡片摘要行：网页空间 / 数据库 / 月流量 / 绑定域名，
 * 替代云主机的 CPU / 内存 / 带宽 —— 虚拟主机没有 CPU 与内存的概念。
 */
export function resolveListVirtualHostSpecText(item: ServiceLike) {
  return {
    webSpace: findListSpecValue(item, ['WEB空间', '网页空间', '网站空间', '磁盘'], ''),
    dbSpace: findListSpecValue(item, ['数据库空间', '数据库', 'MySQL'], ''),
    traffic: findListSpecValue(item, ['流量限制', '月流量', '流量'], ''),
    domains: findListSpecValue(item, ['绑定域名数', '域名数', '域名绑定'], ''),
  };
}

/**
 * 虚拟主机卡片右下角的主机账号。
 *
 * 虚拟主机没有独立公网 IP（虚拟主机交付的是主机名/面板账号），
 * 所以这里展示可点击复制的账号，而不是云主机的公网 IP。
 */
export function resolveListHostAccountText(item: ServiceLike) {
  const connection = (item as { connection?: { hostname?: unknown } } | null)?.connection;
  const account = String(item?.domain || connection?.hostname || item?.name || '').trim();
  return account || '--';
}

/**
 * CDN 卡片右下角的流量文案。
 *
 * 列表接口不下发 traffic（那是详情页实时向上游取的，逐行取会变成 N+1 请求），
 * 所以这里退到 specs 里的流量上限；只有在接口将来补上 traffic 时才显示「已用 / 上限」。
 */
export function resolveListTrafficText(item: ServiceLike) {
  const traffic = (item as { traffic?: Record<string, unknown> } | null)?.traffic;
  if (traffic && typeof traffic === 'object') {
    const used = String(traffic.usage_label || '').trim();
    const limit = String(traffic.limit_label || '').trim();
    if (used) return limit ? `${used} / ${limit}` : used;
  }

  return findListSpecValue(item, ['流量'], '');
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

/**
 * 暂停原因文案：系统内的固定取值走 i18n（expired = 服务已到期），
 * 上游给出的自由文本（违规使用、资源超配等）原样展示。
 * 非暂停状态不展示原因。
 */
export function resolveSuspendedReason(item: ServiceLike) {
  if (Number(item?.status) !== SERVICE_STATUS.SUSPENDED) return '';
  const reason = String(item?.suspended_reason ?? '').trim();
  if (!reason) return '';
  if (reason === 'expired') return t('client.services.suspendReasonExpired');
  return reason;
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
  /**
   * 一级分组 tab：第一个是「全部产品」，后面按后台顺序排每个有实例的一级分组。
   *
   * 数据源就是 grouped-overview 的 catalog_types（后端按一级分组统计），所以
   * tab 上的数量是该一级分组下的实例数，与列表筛选（状态 / 续费方式）无关。
   */
  const catalogTypeTabs = computed(() => [
    {
      value: '',
      label: t('client.services.allProducts'),
      count: Number(overview.value.total || 0),
      icon: null as ResolvedProductGroupIcon | null,
      fallbackText: '',
    },
    ...catalogTypeOptions.value.map((item) => {
      const icon = resolveServiceCatalogIcon(item);
      return {
        value: String(item.value ?? ''),
        label: String(item.label ?? ''),
        count: Number(item.count || 0),
        icon,
        // 内置图标缺失/未知时的文字兜底：文字图标优先，其次名称缩写
        fallbackText: icon.text || icon.abbr,
      };
    }),
  ]);
  const viewModeOptions = [
    { label: t('client.services.cardView'), value: 'grid' },
    { label: t('client.services.listView'), value: 'list' },
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
        label: t('client.services.metricTotal'),
        value: Number(overview.value.total || 0),
        copy: t('service.center.metrics.coverage', { count: Number(overview.value.category_total || 0) }),
      },
      {
        key: 'active',
        label: t('client.services.metricActive'),
        value: activeCount,
        copy: t('service.center.metrics.console'),
      },
      {
        key: 'pending',
        label: t('client.services.metricPending'),
        value: pendingCount,
        copy: t('service.center.metrics.attention'),
      },
      {
        key: 'expiring',
        label: t('client.services.metricExpiring'),
        value: expiringCount,
        copy: t('service.center.metrics.renew'),
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

  /**
   * 切换一级分组（tab / 下拉共用）。
   *
   * 同时把选择写回地址：仪表盘的「我的产品」磁贴就是靠 ?catalog_type= 跳进来的，
   * tab 切换后地址跟着变，刷新和分享才能停在同一个分组。
   */
  function pickCategory(value: string) {
    const next = String(value ?? '');
    if (filters.catalog_type === next) return;
    filters.catalog_type = next;
    filters.page = 1;
    const query = { ...(route.query || {}) };
    if (next === '') {
      delete query.catalog_type;
    } else {
      query.catalog_type = next;
    }
    void router.replace({ path: route.path, query });
    void loadList();
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
      MessagePlugin.error(getErrorMessage(error, t('service.center.renewLoadFailed')));
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
      MessagePlugin.success(t('serviceConsole.renew.invoiceCreated'));
      router.push(invoiceId > 0 ? `/client/invoices/${invoiceId}/pay` : '/client/invoices');
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, t('service.center.renewCreateFailed')));
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
      MessagePlugin.success(t('serviceConsole.remarkSaved'));
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, t('service.center.remarkSaveFailed')));
    } finally {
      remarkSubmitting.value = false;
    }
  }

  async function copyPublicIp(value: unknown) {
    await copyText(value, {
      successMsg: t('service.center.ipCopied'),
      errorMsg: t('finance.messages.copyUnsupported'),
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
    catalogTypeTabs,
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
