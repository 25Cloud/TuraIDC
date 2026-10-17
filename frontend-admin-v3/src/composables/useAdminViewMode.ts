import { computed, ref, watch } from 'vue';

/**
 * 管理端列表页的「列表 / 卡片」展示方式。
 *
 * 为什么不是每个页面自己写一套：
 * 后台此前各页风格分裂——商品、用户是表格，工单、上游是卡片，管理员没有任何
 * 统一入口切换，跨页操作手感不一致。这里给所有列表页一个共用开关：
 *
 *  - 每个页面有自己的 key（如 'products'），偏好单独记住，互不影响；
 *  - 另有一个「默认值」：新页面、新浏览器先按它来，可在设置面板里配置；
 *  - 全部存 localStorage：这是管理员个人的展示偏好，不是站点配置，
 *    换浏览器回落到默认值是预期行为，也省掉一条后端写入链路。
 */

export type AdminViewMode = 'list' | 'card';

const DEFAULT_STORAGE_KEY = 'turaidc:admin-view-mode-default';
const PAGE_STORAGE_PREFIX = 'turaidc:admin-view-mode:';

function isMode(value: unknown): value is AdminViewMode {
  return value === 'list' || value === 'card';
}

function readStorage(key: string): string {
  try {
    return window.localStorage.getItem(key) ?? '';
  } catch {
    // 隐私模式 / 禁用存储时不要抛错：展示偏好不值得让整个页面挂掉
    return '';
  }
}

function writeStorage(key: string, value: string): void {
  try {
    window.localStorage.setItem(key, value);
  } catch {
    /* 同上：写不进去就只影响下次是否记得 */
  }
}

/** 全站默认展示方式（新页面 / 未单独设置过的页面用）。 */
const defaultMode = ref<AdminViewMode>(
  (() => {
    const stored = readStorage(DEFAULT_STORAGE_KEY);

    return isMode(stored) ? stored : 'list';
  })(),
);

/** 已创建的页面开关，默认值变化时未被单独设置过的页面要跟着变。 */
const pageModes = new Map<string, ReturnType<typeof ref<AdminViewMode>>>();

export function getDefaultViewMode(): AdminViewMode {
  return defaultMode.value;
}

export function setDefaultViewMode(mode: AdminViewMode): void {
  defaultMode.value = mode;
  writeStorage(DEFAULT_STORAGE_KEY, mode);
}

/**
 * 页面级开关。
 *
 * @param pageKey 页面的稳定标识（不要用路由参数拼，避免同一页面多种 URL 各记一份）
 * @param fallback 该页面原有的形态；没有默认值时作为兜底（例如工单本来就是卡片）
 */
export function useAdminViewMode(
  pageKey: string,
  fallback: AdminViewMode = 'list',
) {
  const pageKeyFull = `${PAGE_STORAGE_PREFIX}${pageKey}`;

  if (!pageModes.has(pageKeyFull)) {
    const stored = readStorage(pageKeyFull);
    pageModes.set(pageKeyFull, ref(isMode(stored) ? stored : defaultMode.value || fallback));
  }

  const state = pageModes.get(pageKeyFull)!;

  // 默认值改了，且这个页面从没被单独切换过 → 跟随默认值
  watch(defaultMode, (next) => {
    if (!readStorage(pageKeyFull)) {
      state.value = next;
    }
  });

  function setMode(mode: AdminViewMode): void {
    state.value = mode;
    writeStorage(pageKeyFull, mode);
  }

  return {
    mode: computed(() => state.value),
    isCard: computed(() => state.value === 'card'),
    setMode,
    toggle: () => setMode(state.value === 'card' ? 'list' : 'card'),
  };
}
