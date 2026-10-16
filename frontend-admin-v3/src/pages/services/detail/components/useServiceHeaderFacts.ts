import { computed, ref, watch } from 'vue';

import type { SupplierRecord } from '@/api/supplier';
import { supplierApi } from '@/api/supplier';

import type { ConsoleServiceDetail } from '../console/types';

/**
 * 魔方财务上游的 provider key。
 * 主值取自后端 App\Services\Upstream\ProviderKey::ZJMF_FINANCE_API；
 * 同时兼容历史绑定里写入的 zjmf 短 key，避免老绑定看不到跳转入口。
 */
const ZJMF_PROVIDER_KEYS = new Set(['zjmffinanceapi', 'zjmf']);

/** 实例是否来自魔方财务上游。 */
export function isZjmfUpstreamProvider(providerKey: unknown): boolean {
  const key = String(providerKey || '')
    .trim()
    .toLowerCase()
    .replace(/[\s_-]+/g, '');

  return key !== '' && (ZJMF_PROVIDER_KEYS.has(key) || key.startsWith('zjmf'));
}

/**
 * 上游站点根地址。
 * 与后端 ServiceDetailService::resolveSupplierRootUrl 同口径：只保留 scheme://host[:port]，
 * 丢掉 base_url 上带的路径——魔方的 /servicedetail 挂在站点根，保留路径会拼出坏链接。
 */
export function resolveUpstreamRootUrl(baseUrl: unknown): string {
  const raw = String(baseUrl || '').trim();
  if (!raw) return '';
  try {
    return new URL(raw).origin;
  } catch {
    return '';
  }
}

export interface UseServiceHeaderFactsOptions {
  userId: string;
  detail: { value: ConsoleServiceDetail };
}

/**
 * 顶部信息区需要的、详情接口不下发的那部分事实：
 * 上游名称、上游站点地址、魔方上游管理页地址。
 *
 * 上游地址一律取自既有绑定配置（supplier_plugin_bindings.base_url），不新增配置项。
 */
export function useServiceHeaderFacts(options: UseServiceHeaderFactsOptions) {
  const { userId, detail } = options;

  const supplier = ref<SupplierRecord | null>(null);
  const supplierLoading = ref(false);

  const supplierId = computed(() => Number(detail.value.upstream?.supplier_id || 0));
  const upstreamHostId = computed(() => Number(detail.value.upstream?.host_id || 0));
  const isZjmfUpstream = computed(() => isZjmfUpstreamProvider(detail.value.upstream?.provider_key));
  const upstreamName = computed(() => String(supplier.value?.name || '').trim());
  const upstreamRootUrl = computed(() => resolveUpstreamRootUrl(supplier.value?.api_url));

  /**
   * 魔方上游详情页地址。三个条件缺一不可：
   * 来源是魔方、上游 ID 有效、上游地址已配置，否则宁可不显示也不拼坏链接。
   */
  const upstreamAdminUrl = computed(() => {
    if (!isZjmfUpstream.value || !upstreamRootUrl.value || upstreamHostId.value <= 0) return '';
    return `${upstreamRootUrl.value}/servicedetail?id=${upstreamHostId.value}`;
  });

  async function loadSupplier(id: number) {
    if (id <= 0) {
      supplier.value = null;
      return;
    }
    supplierLoading.value = true;
    try {
      supplier.value = (await supplierApi.detail(id)) || null;
    } catch {
      // 上游信息缺失不影响主信息区展示，只是没有跳转入口
      supplier.value = null;
    } finally {
      supplierLoading.value = false;
    }
  }

  watch(supplierId, (id) => void loadSupplier(id), { immediate: true });

  return {
    userId,
    supplierLoading,
    upstreamName,
    upstreamHostId,
    isZjmfUpstream,
    upstreamAdminUrl,
  };
}
